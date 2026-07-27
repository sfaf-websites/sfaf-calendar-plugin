<?php
/**
 * GoFundMe Pro (Classy) — authentication.
 *
 * STEP 1 of the integration: obtaining and holding an access token, nothing
 * else. There is deliberately no campaign fetching, no import and no display
 * code here yet; this file exists so that "can we authenticate at all?" can be
 * answered on its own before anything is built on top of it.
 *
 * ENDPOINT — taken from the official OpenAPI spec, apiv2-public-gfmp.json.
 * ---------------------------------------------------------------------------
 * No longer assumed. The spec in the project folder gives, verbatim:
 *
 *   servers[0].url                                  https://pro.gofundme.com/api/2.0
 *   securitySchemes.OAuth2Application.type          oauth2
 *   …flows.clientCredentials.tokenUrl               /oauth2/auth
 *   …flows.clientCredentials.scopes                 read, write
 *
 * tokenUrl is relative to the server URL, so the absolute endpoint is:
 *
 *   POST https://pro.gofundme.com/api/2.0/oauth2/auth
 *        Content-Type: application/x-www-form-urlencoded
 *        grant_type=client_credentials&client_id=…&client_secret=…
 *
 *   -> { "access_token": "…", "token_type": "bearer", "expires_in": …, … }
 *
 * (An earlier build pointed at api.classy.org, the pre-rebrand host. Wrong
 * domain, right flow.)
 *
 * Still filterable, for the sandbox host or any future move:
 *
 *     add_filter( 'sfaf_gfmp_token_endpoint', function () {
 *         return 'https://sandbox.example/api/2.0/oauth2/auth';
 *     } );
 *
 * The connection test reports the endpoint it used, so a 404 or 405 points at
 * the address rather than the credentials.
 *
 * SECRETS: the client secret is never echoed back to the browser and the access
 * token is never rendered or logged. The settings screen shows only whether a
 * secret is stored; the connection test reports status and expiry only.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class SFAF_GFMP {

    /** servers[0].url from the spec. Resource base for later steps. */
    const API_BASE = 'https://pro.gofundme.com/api/2.0';

    /** API_BASE + the spec's clientCredentials tokenUrl (/oauth2/auth). */
    const TOKEN_URL = 'https://pro.gofundme.com/api/2.0/oauth2/auth';

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
    }

    /* ---------------------------------------------------------------------
     * Configuration
     * ------------------------------------------------------------------- */

    /** The token endpoint, filterable for sandbox hosts. */
    public static function token_endpoint() {
        return (string) apply_filters( 'sfaf_gfmp_token_endpoint', self::TOKEN_URL );
    }

    /** The resource base, filterable alongside the token endpoint. */
    public static function api_base() {
        return untrailingslashit( (string) apply_filters( 'sfaf_gfmp_api_base', self::API_BASE ) );
    }

    /**
     * Optional scope for the token request.
     *
     * The spec declares read and write scopes on the clientCredentials flow but
     * does not mark either required, so nothing is sent by default and the
     * server applies whatever the app is registered for. If a call later comes
     * back short of permission, request them explicitly:
     *
     *     add_filter( 'sfaf_gfmp_token_scope', function () { return 'read write'; } );
     *
     * @return string Space-separated scopes, or '' to send none.
     */
    public static function token_scope() {
        return trim( (string) apply_filters( 'sfaf_gfmp_token_scope', '' ) );
    }

    /**
     * Stored credentials.
     *
     * @return array{client_id:string,client_secret:string,org_id:string}
     */
    public static function credentials() {
        $settings = get_option( 'uc_settings', array() );
        return array(
            'client_id'     => isset( $settings['gofundme_client_id'] ) ? trim( (string) $settings['gofundme_client_id'] ) : '',
            'client_secret' => isset( $settings['gofundme_client_secret'] ) ? trim( (string) $settings['gofundme_client_secret'] ) : '',
            'org_id'        => isset( $settings['gofundme_org_id'] ) ? trim( (string) $settings['gofundme_org_id'] ) : '',
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

        $form = array(
            'grant_type'    => 'client_credentials',
            'client_id'     => $client_id,
            'client_secret' => $client_secret,
        );
        $scope = self::token_scope();
        if ( '' !== $scope ) {
            $form['scope'] = $scope;
        }

        $response = wp_remote_post( $endpoint, array(
            'timeout'     => 20,
            'redirection' => 3,
            'headers'     => array(
                'Content-Type' => 'application/x-www-form-urlencoded',
                'Accept'       => 'application/json',
            ),
            // WordPress form-encodes an array body, which is what the
            // client-credentials grant expects.
            'body'        => $form,
        ) );

        if ( is_wp_error( $response ) ) {
            return new WP_Error(
                'sfaf_gfmp_unreachable',
                sprintf( 'Could not reach %s — %s', $endpoint, $response->get_error_message() )
            );
        }

        $status = (int) wp_remote_retrieve_response_code( $response );
        $raw    = (string) wp_remote_retrieve_body( $response );
        $body   = json_decode( $raw, true );

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

        self::store_token( $token );

        $org_note = ( '' === $stored['org_id'] )
            ? ' The token request does not use the Organization ID, but campaign calls will — add it before the next step.'
            : '';

        wp_send_json_success( array(
            'message'  => sprintf(
                'Connected — token obtained, expires in %s (%s grant).%s',
                human_time_diff( time(), $token['expires_at'] ),
                'client_credentials',
                $org_note
            ),
            'endpoint' => self::token_endpoint(),
            // Deliberately no token, no secret, no length hints.
        ) );
    }
}
