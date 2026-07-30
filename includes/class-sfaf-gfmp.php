<?php
/**
 * GoFundMe Pro (Classy) — authentication.
 *
 * STEP 1 of the integration: obtaining and holding an access token, nothing
 * else. There is deliberately no campaign fetching, no import and no display
 * code here yet; this file exists so that "can we authenticate at all?" can be
 * answered on its own before anything is built on top of it.
 *
 * ENDPOINTS — confirmed with GoFundMe Pro support, not inferred.
 * ---------------------------------------------------------------------------
 * Two different hosts, which is the thing to keep straight:
 *
 *   TOKEN   POST https://api.classy.org/oauth2/auth
 *                Content-Type: application/x-www-form-urlencoded
 *                x-integration-id: <integration ID>
 *                grant_type=client_credentials&client_id=…&client_secret=…
 *
 *                -> { "access_token": …, "expires_in": …, "token_type": "bearer" }
 *
 *   DATA    GET  https://pro.gofundme.com/api/2.0/…
 *                Authorization: Bearer <access_token>
 *                x-integration-id: <integration ID>
 *
 * Credentials go in the body, form-encoded. Not HTTP Basic. No scope parameter.
 *
 * Support has confirmed api.classy.org/oauth2/auth is the correct and only
 * token endpoint: pro.gofundme.com does NOT serve tokens, so it is not a
 * fallback to try when a token request fails. That earlier guess produced "The
 * access token is missing" — a 401 from the resource-auth middleware that
 * looks like a credential problem and is not. The token URL remains editable
 * for the unlikely case that support changes it, but it should be left alone.
 *
 * THE CLOUDFLARE 403. Support also confirmed the credentials were never at
 * fault: their edge security was flagging this server's traffic as a bot. The
 * fix they gave is the x-integration-id header, which every request now
 * carries via request_args(). See DEFAULT_INTEGRATION_ID.
 *
 * The data host is the one thing still unsettled: the docs example shows
 * api.classy.org/2.0/resource while the spec says pro.gofundme.com/api/2.0. The
 * spec's value is the default, and it is editable in the settings panel so it
 * can be corrected on a live site without a rebuild. Filters
 * (sfaf_gfmp_token_endpoint, sfaf_gfmp_api_base) still override the settings.
 *
 * SECRETS: the client secret is never echoed back to the browser and the access
 * token is never rendered or logged. The settings screen shows only whether a
 * secret is stored; the connection test reports status and expiry only.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class SFAF_GFMP {

    /** Default data host — servers[0].url from the OpenAPI spec. */
    const DEFAULT_API_BASE = 'https://pro.gofundme.com/api/2.0';

    /** Default token host — from the authentication documentation. */
    const DEFAULT_TOKEN_URL = 'https://api.classy.org/oauth2/auth';

    /**
     * Integration ID issued by GoFundMe Pro, sent as x-integration-id.
     *
     * Their edge security was flagging this server's traffic as a bot and
     * answering with a Cloudflare 403 before the credentials were ever
     * examined. Support's fix is this header, which identifies the request as
     * a known integration rather than anonymous server traffic.
     *
     * A constant with a filter over it rather than a literal in the request,
     * so a reissued ID is a one-line change or a filter on a live site:
     *
     *     add_filter( 'sfaf_gfmp_integration_id', function () { return '…'; } );
     */
    const DEFAULT_INTEGRATION_ID = 'NSINTG4F8A2C9D6Q1';

    /**
     * Where the token lives.
     *
     * Its own option rather than a key inside uc_settings: the settings screen
     * re-saves that whole array on every submit, which would otherwise wipe or
     * stale-write the token. Stored with autoload off — it is only read when a
     * request to Classy is actually being made.
     */
    const TOKEN_OPTION = 'sfaf_gfmp_token';

    /** Seconds of headroom so a token is never used in its final moments. */
    const EXPIRY_MARGIN = 60;

    public function register() {
        add_action( 'wp_ajax_sfaf_gfmp_connect', array( $this, 'ajax_connect' ) );
        // [PROBE] temporary diagnostic — remove with the rest of the block.
        add_action( 'wp_ajax_sfaf_gfmp_probe', array( $this, 'ajax_probe' ) );
    }

    /* ---------------------------------------------------------------------
     * Configuration
     * ------------------------------------------------------------------- */

    /**
     * The token endpoint.
     *
     * Settings field first so it can be corrected on a live site, then the
     * documented default. The filter still wins over both, for environments
     * configured in code.
     */
    public static function token_endpoint() {
        $configured = SFAF_Credentials::get( 'gofundme_token_url' );
        $url = ( '' !== $configured ) ? $configured : self::DEFAULT_TOKEN_URL;
        return (string) apply_filters( 'sfaf_gfmp_token_endpoint', $url );
    }

    /**
     * The data/resource base — a different host from the token endpoint.
     *
     * Editable for the same reason, and more pressingly: the documentation and
     * the OpenAPI spec disagree about which host serves resources.
     */
    public static function api_base() {
        $configured = SFAF_Credentials::get( 'gofundme_api_base' );
        $url = ( '' !== $configured ) ? $configured : self::DEFAULT_API_BASE;
        return untrailingslashit( (string) apply_filters( 'sfaf_gfmp_api_base', $url ) );
    }

    /**
     * The User-Agent sent with every GoFundMe Pro request.
     *
     * The token host sits behind Cloudflare, which answered WordPress's default
     * agent with a 403 "Just a moment…" interstitial — bot protection, not a
     * credential rejection. An identifying agent is both the polite thing to
     * send and what a filter like that expects to see.
     *
     * Filterable because tuning it is the cheapest lever if the challenge
     * returns:
     *
     *     add_filter( 'sfaf_gfmp_user_agent', function () { return '…'; } );
     *
     * @return string
     */
    public static function user_agent() {
        $default = 'SFAF-Calendar/' . SFAF_VERSION . ' (+https://sfaf.org)';
        return (string) apply_filters( 'sfaf_gfmp_user_agent', $default );
    }

    /**
     * The integration ID sent as x-integration-id on every request.
     *
     * @return string
     */
    public static function integration_id() {
        return (string) apply_filters( 'sfaf_gfmp_integration_id', self::DEFAULT_INTEGRATION_ID );
    }

    /**
     * Base arguments for any request to GoFundMe Pro — token or data.
     *
     * Centralised so the two cannot drift: whatever gets a request past
     * Cloudflare for the token endpoint is then automatically also sent on
     * every campaign call. x-integration-id is the header GoFundMe Pro support
     * identified as the fix for their edge security flagging this server as a
     * bot, and it is set here rather than on the token request alone so no
     * later data call can be missing it.
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
                'Accept'           => 'application/json',
                'x-integration-id' => self::integration_id(),
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
     * Stored credentials.
     *
     * @return array{client_id:string,client_secret:string,org_id:string}
     */
    public static function credentials() {
        return array(
            'client_id'     => SFAF_Credentials::get( 'gofundme_client_id' ),
            'client_secret' => SFAF_Credentials::get( 'gofundme_client_secret' ),
            'org_id'        => SFAF_Credentials::get( 'gofundme_org_id' ),
        );
    }

    /** True when a secret is on file, without revealing it. */
    public static function has_secret() {
        $c = self::credentials();
        return '' !== $c['client_secret'];
    }

    /* ---------------------------------------------------------------------
     * Token
     * ------------------------------------------------------------------- */

    /**
     * Ask Classy for an access token.
     *
     * Credentials are passed in rather than read here so the settings screen can
     * test what is currently typed in the form, before it has been saved.
     *
     * @param string $client_id
     * @param string $client_secret
     * @return array|WP_Error Token data on success.
     */
    public static function request_token( $client_id, $client_secret ) {
        $client_id     = trim( (string) $client_id );
        $client_secret = trim( (string) $client_secret );

        if ( '' === $client_id || '' === $client_secret ) {
            return new WP_Error( 'sfaf_gfmp_missing', 'Client ID and Client Secret are both required.' );
        }

        $endpoint = self::token_endpoint();

        // The documented format: credentials in a form-encoded body, no Basic
        // header, no scope. WordPress form-encodes an array body.
        $response = wp_remote_post( $endpoint, self::request_args( array(
            'headers' => array(
                'Content-Type' => 'application/x-www-form-urlencoded',
            ),
            'body'    => array(
                'grant_type'    => 'client_credentials',
                'client_id'     => $client_id,
                'client_secret' => $client_secret,
            ),
        ) ) );

        if ( is_wp_error( $response ) ) {
            return new WP_Error(
                'sfaf_gfmp_unreachable',
                sprintf( 'Could not reach %s — %s', $endpoint, $response->get_error_message() )
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
                'sfaf_gfmp_challenge',
                sprintf( 'HTTP %d from %s — %s', $status, $endpoint, $challenge )
            );
        }

        if ( $status < 200 || $status >= 300 ) {
            return new WP_Error(
                'sfaf_gfmp_http_' . $status,
                sprintf( 'HTTP %d from %s — %s', $status, $endpoint, self::error_detail( $body, $raw, $status ) )
            );
        }

        if ( ! is_array( $body ) || empty( $body['access_token'] ) ) {
            return new WP_Error(
                'sfaf_gfmp_no_token',
                sprintf( 'HTTP %d from %s but the response contained no access_token. %s', $status, $endpoint, self::error_detail( $body, $raw, $status ) )
            );
        }

        $expires_in = isset( $body['expires_in'] ) ? (int) $body['expires_in'] : 0;
        if ( $expires_in <= 0 ) {
            // Absent expiry: assume the documented two hours rather than
            // treating the token as immortal.
            $expires_in = 2 * HOUR_IN_SECONDS;
        }

        return array(
            'access_token' => (string) $body['access_token'],
            'token_type'   => isset( $body['token_type'] ) ? (string) $body['token_type'] : 'bearer',
            'expires_in'   => $expires_in,
            'expires_at'   => time() + $expires_in - self::EXPIRY_MARGIN,
            'obtained_at'  => time(),
        );
    }

    /**
     * Recognise a bot-protection challenge, and say so plainly.
     *
     * Cloudflare's interstitial arrives as an HTML page, so the generic error
     * reader would return a slice of markup that tells you nothing. Worse, it
     * looks like an auth failure — it is a 403 — when the credentials were
     * never examined at all.
     *
     * Detection is deliberately two-sided: the body's tell-tale phrases, and
     * the response headers Cloudflare adds. Either alone is enough.
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
            'Bot protection blocked this request (matched "%s") — the credentials were never checked, so this is not a credential problem. '
            . 'This plugin sends the x-integration-id header GoFundMe Pro support issued for exactly this, plus an identifying User-Agent, '
            . 'and neither satisfied the edge this time. Next things to try: confirm the integration ID is still current with GoFundMe Pro '
            . '(override it with the sfaf_gfmp_integration_id filter); ask them to allow this server; or tune the agent via the '
            . 'sfaf_gfmp_user_agent filter. Do not change the token endpoint — support confirmed %s is the correct one and that '
            . 'pro.gofundme.com does not serve tokens. Integration ID sent: %s. Agent sent: %s',
            $hit,
            self::DEFAULT_TOKEN_URL,
            self::integration_id(),
            self::user_agent()
        );
    }

    /**
     * A readable reason from an error response.
     *
     * Only the standard OAuth2 error fields are used, falling back to a short
     * slice of the raw body. Truncated so a stray HTML error page cannot flood
     * the notice.
     *
     * @param mixed  $body   Decoded body.
     * @param string $raw    Raw body.
     * @param int    $status HTTP status.
     * @return string
     */
    private static function error_detail( $body, $raw, $status ) {
        if ( is_array( $body ) ) {
            foreach ( array( 'error_description', 'error', 'message', 'detail' ) as $key ) {
                if ( ! empty( $body[ $key ] ) && is_string( $body[ $key ] ) ) {
                    return $body[ $key ];
                }
            }
        }
        $raw = trim( wp_strip_all_tags( $raw ) );
        if ( '' === $raw ) {
            if ( 404 === $status || 405 === $status ) {
                return 'No response body. A 404/405 here usually means the token endpoint address is wrong — see the note in class-sfaf-gfmp.php.';
            }
            return 'No response body.';
        }
        return ( strlen( $raw ) > 300 ) ? substr( $raw, 0, 300 ) . '…' : $raw;
    }

    /** Persist a token. Never rendered anywhere. */
    private static function store_token( $token ) {
        update_option( self::TOKEN_OPTION, $token, false );
    }

    /** Forget the stored token (used when credentials fail). */
    public static function clear_token() {
        delete_option( self::TOKEN_OPTION );
    }

    /** The stored token, or array() when there is none. */
    public static function stored_token() {
        $token = get_option( self::TOKEN_OPTION, array() );
        return is_array( $token ) ? $token : array();
    }

    /** True when a stored token exists and has not expired. */
    public static function has_valid_token() {
        $token = self::stored_token();
        return ! empty( $token['access_token'] ) && ! empty( $token['expires_at'] ) && time() < (int) $token['expires_at'];
    }

    /**
     * A usable access token, refreshing when the stored one has expired.
     *
     * Nothing calls this yet — it is the seam the fetch step will use.
     *
     * @return string|WP_Error
     */
    public static function get_access_token() {
        if ( self::has_valid_token() ) {
            $token = self::stored_token();
            return (string) $token['access_token'];
        }

        $creds = self::credentials();
        $token = self::request_token( $creds['client_id'], $creds['client_secret'] );
        if ( is_wp_error( $token ) ) {
            return $token;
        }

        self::store_token( $token );
        return $token['access_token'];
    }

    /* ---------------------------------------------------------------------
     * Data calls — campaigns
     *
     * LIST   GET {data_base}/organizations/{organization_id}/campaigns
     *              ?page=N&per_page=100
     *              Authorization: Bearer <access token>
     *              x-integration-id: <integration ID>
     *
     * Paginated in the Laravel style the spec documents as PaginatedResponse:
     * the rows are under `data`, and `current_page` / `last_page` say whether
     * to keep going.
     * ------------------------------------------------------------------- */

    /** Pages to follow before giving up, unless filtered. */
    const DEFAULT_MAX_PAGES = 20;

    /** Rows per page to request. */
    const DEFAULT_PER_PAGE = 100;

    /**
     * Request arguments carrying the bearer token.
     *
     * The one place the Authorization header is built for data calls, layered
     * over request_args() so the User-Agent, Accept and x-integration-id
     * headers come along automatically.
     *
     * @param string $token Access token.
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
     * The published rate limit: 300 requests per minute (5/sec) per
     * application, confirmed by GoFundMe Pro support. A breach answers 429
     * with a retry_after.
     *
     * Recorded as a constant so the numbers the import path budgets against
     * are written down rather than remembered. An hourly run over ~20
     * campaigns costs roughly 40 requests (one overview and one FAQ page
     * each), which is an eighth of one minute's allowance.
     */
    const RATE_LIMIT_PER_MINUTE = 300;

    /** How many times a 429 is waited out before giving up. */
    const RATE_LIMIT_RETRIES = 2;

    /** Never sleep longer than this for a retry_after, whatever it says. */
    const RATE_LIMIT_MAX_WAIT = 10;

    /**
     * GET a URL with the bearer token and return its decoded JSON.
     *
     * Same treatment every other call in this file gets: bot-challenge check
     * first, then status, then "is this even JSON", with the real status and
     * endpoint named in any error.
     *
     * A 429 is the one status that is retried rather than reported. Support
     * confirmed the limit is 300 requests/minute with a retry_after on the
     * response, so being told to wait is an instruction, not a failure — and
     * the alternative is a whole source's fetch reporting an error because one
     * call arrived a second early.
     *
     * @param string $token Access token.
     * @param string $url   Absolute URL.
     * @return array|WP_Error
     */
    private static function request_json( $token, $url ) {
        $attempts = 0;

        do {
            $response = wp_remote_get( $url, self::auth_args( $token ) );

            if ( is_wp_error( $response ) ) {
                return new WP_Error(
                    'sfaf_gfmp_unreachable',
                    sprintf( 'Could not reach %s — %s', $url, $response->get_error_message() )
                );
            }

            $status = (int) wp_remote_retrieve_response_code( $response );
            if ( 429 !== $status || $attempts >= self::RATE_LIMIT_RETRIES ) {
                break;
            }

            $attempts++;
            $wait = (int) wp_remote_retrieve_header( $response, 'retry-after' );
            if ( $wait < 1 ) {
                $wait = 1;
            }
            sleep( min( $wait, self::RATE_LIMIT_MAX_WAIT ) );
        } while ( true );

        $raw  = (string) wp_remote_retrieve_body( $response );
        $body = json_decode( $raw, true );

        $challenge = self::challenge_detail( $response, $raw );
        if ( '' !== $challenge ) {
            return new WP_Error(
                'sfaf_gfmp_challenge',
                sprintf( 'HTTP %d from %s — %s', $status, $url, $challenge )
            );
        }

        if ( 429 === $status ) {
            return new WP_Error(
                'sfaf_gfmp_rate_limited',
                sprintf(
                    'HTTP 429 from %s — rate limited after %d retries. The published limit is %d requests/minute per application.',
                    $url,
                    self::RATE_LIMIT_RETRIES,
                    self::RATE_LIMIT_PER_MINUTE
                )
            );
        }

        if ( 200 !== $status ) {
            return new WP_Error(
                'sfaf_gfmp_http_' . $status,
                sprintf( 'HTTP %d from %s — %s', $status, $url, self::error_detail( $body, $raw, $status ) )
            );
        }

        if ( ! is_array( $body ) ) {
            return new WP_Error(
                'sfaf_gfmp_unexpected',
                sprintf( 'HTTP 200 from %s but the response was not JSON. %s', $url, self::error_detail( $body, $raw, $status ) )
            );
        }

        return $body;
    }

    /**
     * Build a full data URL.
     *
     * @param string $path  Path below the data base.
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
     * Every campaign belonging to the configured organization.
     *
     * @return array|WP_Error {items, pages, truncated, truncated_reason, endpoint, org_id}
     */
    public static function fetch_campaigns() {
        $creds  = self::credentials();
        $org_id = $creds['org_id'];

        if ( '' === $org_id ) {
            return new WP_Error(
                'sfaf_gfmp_missing_org',
                'No GoFundMe Pro Organization ID is stored. Add it under Settings → GoFundMe Pro — campaign calls are addressed to /organizations/{id}/campaigns and cannot be made without it.'
            );
        }

        $token = self::get_access_token();
        if ( is_wp_error( $token ) ) {
            return $token;
        }

        $max_pages = (int) apply_filters( 'sfaf_gfmp_max_pages', self::DEFAULT_MAX_PAGES );
        if ( $max_pages < 1 ) {
            $max_pages = 1;
        }
        $per_page = (int) apply_filters( 'sfaf_gfmp_per_page', self::DEFAULT_PER_PAGE );
        if ( $per_page < 1 ) {
            $per_page = self::DEFAULT_PER_PAGE;
        }

        $path             = 'organizations/' . rawurlencode( $org_id ) . '/campaigns';
        $items            = array();
        $page             = 1;
        $pages            = 0;
        $truncated        = false;
        $truncated_reason = '';
        $first_endpoint   = self::endpoint( $path, array( 'page' => 1, 'per_page' => $per_page ) );

        do {
            $url  = self::endpoint( $path, array( 'page' => $page, 'per_page' => $per_page ) );
            $body = self::request_json( $token, $url );
            if ( is_wp_error( $body ) ) {
                return $body;
            }
            $pages++;

            if ( isset( $body['data'] ) && is_array( $body['data'] ) ) {
                foreach ( $body['data'] as $row ) {
                    if ( is_array( $row ) ) {
                        $items[] = $row;
                    }
                }
            } elseif ( 1 === $pages ) {
                $keys = array_keys( $body );
                return new WP_Error(
                    'sfaf_gfmp_unexpected',
                    sprintf(
                        'HTTP 200 from %s but the response had no "data" list. Keys returned: %s',
                        $url,
                        empty( $keys ) ? '(none)' : implode( ', ', $keys )
                    )
                );
            }

            $current   = isset( $body['current_page'] ) ? (int) $body['current_page'] : $page;
            $last      = isset( $body['last_page'] ) ? (int) $body['last_page'] : $current;
            $has_more  = ( $current < $last );
            $page      = $current + 1;

            if ( $has_more && $pages >= $max_pages ) {
                $truncated        = true;
                $truncated_reason = sprintf(
                    'Stopped at the %d-page limit with %d pages still to read. Raise it with the sfaf_gfmp_max_pages filter.',
                    $max_pages,
                    $last - $current
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
            'org_id'           => $org_id,
        );
    }

    /**
     * One campaign by ID: GET {data_base}/campaigns/{id}
     *
     * The spec returns a bare Campaign object; a `data` envelope is unwrapped
     * too, in case this account's response differs from the documentation.
     *
     * @param string $campaign_id
     * @return array|WP_Error Raw campaign.
     */
    public static function fetch_campaign( $campaign_id ) {
        $campaign_id = trim( (string) $campaign_id );
        if ( '' === $campaign_id ) {
            return new WP_Error( 'sfaf_gfmp_missing_campaign', 'A campaign ID is required.' );
        }

        $token = self::get_access_token();
        if ( is_wp_error( $token ) ) {
            return $token;
        }

        $url  = self::endpoint( 'campaigns/' . rawurlencode( $campaign_id ) );
        $body = self::request_json( $token, $url );
        if ( is_wp_error( $body ) ) {
            return $body;
        }

        if ( isset( $body['data'] ) && is_array( $body['data'] ) && ! empty( $body['data']['id'] ) ) {
            $body = $body['data'];
        }

        if ( empty( $body['id'] ) ) {
            return new WP_Error(
                'sfaf_gfmp_unexpected',
                sprintf( 'HTTP 200 from %s but the response did not look like a campaign.', $url )
            );
        }

        return $body;
    }

    /**
     * Aggregate totals for one campaign — the raised amount for a progress bar.
     *
     * GET {data_base}/campaigns/{id}/overview
     *
     * THIS ENDPOINT IS SUPPORTED. GoFundMe Pro support confirmed it directly:
     * its absence from the supplied OpenAPI specification is a gap in their
     * documentation, which they are fixing, not a missing feature. Earlier
     * releases hedged about whether it existed at all; it does, it answers on
     * this path with the token and headers already in use, and the raw probe
     * proved it in the same request context as the import.
     *
     * THE FIELDS IT ACTUALLY RETURNS, observed on campaign 773343:
     *
     *     gross_amount        1833      donations before fees
     *     total_gross_amount  1833      equal to gross_amount on this account
     *     net_amount          1817.22   after fees
     *     fees_amount         15.78
     *     percent_to_goal     1.833     1833 / 100000 — computed from GROSS
     *
     * There is NO raised_amount, progress_bar_amount or
     * total_online_funds_raised. Those are CampaignAggregates names from the
     * specification and reading them was the actual cause of the "no raised
     * amounts were available" report: the request succeeded every time and the
     * body was then searched for keys that were never in it. See
     * SFAF_Source_GFMP::raised_amount() for which one is mapped and why.
     *
     * @param string $token      Access token.
     * @param string $campaign_id
     * @return array|WP_Error
     */
    public static function fetch_campaign_overview( $token, $campaign_id ) {
        $campaign_id = trim( (string) $campaign_id );
        if ( '' === $campaign_id ) {
            return new WP_Error( 'sfaf_gfmp_missing_campaign', 'A campaign ID is required.' );
        }

        $path = (string) apply_filters(
            'sfaf_gfmp_campaign_overview_path',
            'campaigns/' . rawurlencode( $campaign_id ) . '/overview',
            $campaign_id
        );

        return self::request_json( $token, self::endpoint( $path ) );
    }

    /* ---------------------------------------------------------------------
     * Campaign FAQs
     *
     * GET {data_base}/campaigns/{id}/faqs?page=N&per_page=100
     *
     * Confirmed by probe: campaign 773343 returned 11 real FAQs, each with
     * question, answer, weight, tag and its own id. Paginated in the same
     * Laravel style as the campaign list — rows under `data`, current_page /
     * last_page driving the loop — so this follows pagination properly rather
     * than assuming one page. The probe used per_page 20 and happened to fit
     * in one page; that is not something to build on.
     * ------------------------------------------------------------------- */

    /** Rows per FAQ page to request. */
    const DEFAULT_FAQ_PER_PAGE = 100;

    /** FAQ pages to follow before giving up. */
    const DEFAULT_FAQ_MAX_PAGES = 10;

    /**
     * Every FAQ on one campaign.
     *
     * `complete` is the thing the caller must respect: it is false when the
     * page run was cut short, and a run that is not complete must never be
     * used to conclude that an FAQ has been deleted at the source.
     *
     * @param string $token       Access token.
     * @param string $campaign_id
     * @return array|WP_Error {items:array[], pages:int, complete:bool}
     */
    public static function fetch_campaign_faqs( $token, $campaign_id ) {
        $campaign_id = trim( (string) $campaign_id );
        if ( '' === $campaign_id ) {
            return new WP_Error( 'sfaf_gfmp_missing_campaign', 'A campaign ID is required.' );
        }

        $per_page = (int) apply_filters( 'sfaf_gfmp_faq_per_page', self::DEFAULT_FAQ_PER_PAGE );
        if ( $per_page < 1 ) {
            $per_page = self::DEFAULT_FAQ_PER_PAGE;
        }
        $max_pages = (int) apply_filters( 'sfaf_gfmp_faq_max_pages', self::DEFAULT_FAQ_MAX_PAGES );
        if ( $max_pages < 1 ) {
            $max_pages = 1;
        }

        $path     = 'campaigns/' . rawurlencode( $campaign_id ) . '/faqs';
        $items    = array();
        $page     = 1;
        $pages    = 0;
        $complete = true;

        do {
            $body = self::request_json( $token, self::endpoint( $path, array( 'page' => $page, 'per_page' => $per_page ) ) );
            if ( is_wp_error( $body ) ) {
                return $body;
            }
            $pages++;

            if ( isset( $body['data'] ) && is_array( $body['data'] ) ) {
                foreach ( $body['data'] as $row ) {
                    if ( is_array( $row ) ) {
                        $items[] = $row;
                    }
                }
            }

            $current  = isset( $body['current_page'] ) ? (int) $body['current_page'] : $page;
            $last     = isset( $body['last_page'] ) ? (int) $body['last_page'] : $current;
            $has_more = ( $current < $last );
            $page     = $current + 1;

            if ( $has_more && $pages >= $max_pages ) {
                $complete = false;
                break;
            }
        } while ( $has_more );

        return array(
            'items'    => $items,
            'pages'    => $pages,
            'complete' => $complete,
        );
    }

    /* =====================================================================
     * TEMPORARY DIAGNOSTIC — raw campaign probe.  [PROBE]
     *
     * Everything in this block, the ajax_probe hook in register(), the panel
     * markup in class-sfaf-admin.php and the initGfmpProbe() function in
     * admin.js are marked [PROBE] and exist to be deleted together in one
     * commit once the campaign payload has been observed.
     *
     * Its whole purpose is to stop guessing. The supplied API specification
     * has now been wrong or silent four times — the token endpoint, the
     * raised amounts, the campaign image and the campaign description — so
     * this reports what the account actually returns, verbatim, and maps
     * nothing.
     *
     * READ-ONLY. Nothing here creates, updates or imports anything, and no
     * part of the import path calls it.
     * ================================================================== */

    /** Bodies larger than this are cut, and the cut is always announced. */
    const PROBE_MAX_BODY = 250000;

    /**
     * GET a path and hand back the status and body exactly as they arrived.
     *
     * Deliberately NOT request_json(): that turns any non-2xx into a WP_Error
     * and discards the body, and the body of a failure is precisely the data
     * this is for. Nothing is decoded, judged or filtered here.
     *
     * The token is used and never returned.
     *
     * @param string $path Path below the data base.
     * @return array{path:string,url:string,status:int,message:string,content_type:string,length:int,body:string,error:string,truncated:bool}
     */
    public static function probe_raw( $path ) {
        $url = self::endpoint( $path );

        $out = array(
            'path'         => (string) $path,
            'url'          => $url,
            'status'       => 0,
            'message'      => '',
            'content_type' => '',
            'length'       => 0,
            'body'         => '',
            'error'        => '',
            'truncated'    => false,
        );

        $token = self::get_access_token();
        if ( is_wp_error( $token ) ) {
            // Never include the token or the secret in this — only why the
            // token could not be obtained.
            $out['error'] = 'Could not obtain an access token: ' . $token->get_error_message();
            return $out;
        }

        $response = wp_remote_get( $url, self::auth_args( $token ) );
        if ( is_wp_error( $response ) ) {
            $out['error'] = 'Request failed before a response: ' . $response->get_error_message();
            return $out;
        }

        $body = (string) wp_remote_retrieve_body( $response );

        $out['status']       = (int) wp_remote_retrieve_response_code( $response );
        $out['message']      = (string) wp_remote_retrieve_response_message( $response );
        $out['content_type'] = (string) wp_remote_retrieve_header( $response, 'content-type' );
        $out['length']       = strlen( $body );

        if ( $out['length'] > self::PROBE_MAX_BODY ) {
            $out['truncated'] = true;
            $body             = substr( $body, 0, self::PROBE_MAX_BODY );
        }
        $out['body'] = $body;

        return $out;
    }

    /**
     * Probe the four campaign endpoints and return every raw result.  [PROBE]
     *
     * @param string $campaign_id
     * @return array[]
     */
    public static function probe_campaign( $campaign_id ) {
        $campaign_id = rawurlencode( trim( (string) $campaign_id ) );

        $paths = array(
            'campaigns/' . $campaign_id,
            'campaigns/' . $campaign_id . '/stories',
            'campaigns/' . $campaign_id . '/faqs',
            'campaigns/' . $campaign_id . '/overview',
        );

        $results = array();
        foreach ( $paths as $path ) {
            $results[] = self::probe_raw( $path );
        }
        return $results;
    }

    /**
     * Settings-screen probe.  [PROBE]
     */
    public function ajax_probe() {
        check_ajax_referer( 'uc_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'You do not have permission to do that.' ), 403 );
        }

        $campaign_id = isset( $_POST['campaign_id'] ) ? sanitize_text_field( wp_unslash( $_POST['campaign_id'] ) ) : '';
        if ( '' === trim( $campaign_id ) ) {
            wp_send_json_error( array( 'message' => 'Enter a campaign ID to probe.' ) );
        }

        wp_send_json_success( array(
            'campaign_id' => $campaign_id,
            'base'        => self::api_base(),
            'max_body'    => self::PROBE_MAX_BODY,
            'results'     => self::probe_campaign( $campaign_id ),
        ) );
    }

    /**
     * Connection status for the settings screen.
     *
     * Derived entirely from whether a real token is on file — there is no
     * stored "connected" flag any more, so the badge cannot claim a connection
     * that was never made.
     *
     * @return array{connected:bool,expires_at:int,expires_human:string,has_credentials:bool}
     */
    public static function status() {
        $creds = self::credentials();
        $token = self::stored_token();

        $connected  = self::has_valid_token();
        $expires_at = isset( $token['expires_at'] ) ? (int) $token['expires_at'] : 0;

        return array(
            'connected'       => $connected,
            'expires_at'      => $expires_at,
            'expires_human'   => ( $connected && $expires_at ) ? human_time_diff( time(), $expires_at ) : '',
            'has_credentials' => ( '' !== $creds['client_id'] && '' !== $creds['client_secret'] ),
        );
    }

    /* ---------------------------------------------------------------------
     * Settings-screen connection test
     * ------------------------------------------------------------------- */

    /**
     * Test the connection with whatever is currently in the form.
     *
     * Credentials come from the POST when supplied so the button can be used
     * before saving; a blank secret means "use the one already stored", which
     * is what the masked field submits when it has not been retyped.
     */
    public function ajax_connect() {
        check_ajax_referer( 'uc_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'You do not have permission to do that.' ), 403 );
        }

        $stored = self::credentials();

        $client_id = isset( $_POST['client_id'] ) ? sanitize_text_field( wp_unslash( $_POST['client_id'] ) ) : '';
        if ( '' === $client_id ) {
            $client_id = $stored['client_id'];
        }

        // Not sanitize_text_field: a client secret is an opaque string and must
        // reach Classy byte-for-byte. It is never echoed back out.
        $client_secret = isset( $_POST['client_secret'] ) ? trim( (string) wp_unslash( $_POST['client_secret'] ) ) : '';
        if ( '' === $client_secret ) {
            $client_secret = $stored['client_secret'];
        }

        $token = self::request_token( $client_id, $client_secret );

        if ( is_wp_error( $token ) ) {
            // A failed test invalidates any previous claim of a connection.
            self::clear_token();
            wp_send_json_error( array(
                'message'  => $token->get_error_message(),
                'endpoint' => self::token_endpoint(),
            ) );
        }

        // Credentials that have just proven they work are persisted here and
        // now, so a successful test followed by navigating away without
        // pressing Save Changes does not quietly discard them.
        SFAF_Credentials::set( 'gofundme_client_id', $client_id );
        SFAF_Credentials::set( 'gofundme_client_secret', $client_secret );

        self::store_token( $token );

        $org_note = ( '' === $stored['org_id'] )
            ? ' The token request does not use the Organization ID, but campaign calls will — add it before the next step.'
            : '';

        wp_send_json_success( array(
            'message'  => sprintf(
                'Connected — token obtained, expires in %s (client_credentials grant). Data calls will use %s.%s',
                human_time_diff( time(), $token['expires_at'] ),
                self::api_base(),
                $org_note
            ),
            'endpoint' => self::token_endpoint(),
            // Deliberately no token, no secret, no length hints.
        ) );
    }
}
