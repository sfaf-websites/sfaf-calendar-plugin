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
    public static function fetch_me( $token ) {
        $token = trim( (string) $token );

        if ( '' === $token ) {
            return new WP_Error( 'sfaf_eventbrite_missing', 'A private token is required.' );
        }

        $endpoint = self::me_endpoint();

        $response = wp_remote_get( $endpoint, self::auth_args( $token ) );

        if ( is_wp_error( $response ) ) {
            return new WP_Error(
                'sfaf_eventbrite_unreachable',
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
                'sfaf_eventbrite_challenge',
                sprintf( 'HTTP %d from %s — %s', $status, $endpoint, $challenge )
            );
        }

        if ( 200 !== $status ) {
            return new WP_Error(
                'sfaf_eventbrite_http_' . $status,
                sprintf( 'HTTP %d from %s — %s', $status, $endpoint, self::error_detail( $body, $raw, $status ) )
            );
        }

        if ( ! is_array( $body ) || empty( $body['id'] ) ) {
            return new WP_Error(
                'sfaf_eventbrite_unexpected',
                sprintf(
                    'HTTP 200 from %s but the response did not look like an Eventbrite user. %s',
                    $endpoint,
                    self::error_detail( $body, $raw, $status )
                )
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
}
