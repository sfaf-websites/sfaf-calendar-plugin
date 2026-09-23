<?php
/**
 * EveryAction events, through the MangoApps tracker on the hub (3.100.0).
 *
 * A CONNECTION TEST AND A PROBE, AND NOTHING ELSE YET. There is no adapter, no
 * fetch on the runner, no pending-queue entry and no schedule. What this class
 * can do is log in, read the tracker, log out, and say what happened, so the
 * credential question can be answered on the settings screen by the person who
 * holds the credential, and the tracker's real shape can be seen before an
 * adapter is written against it. PROJECT.md 8 has why the adapter waits.
 *
 * THE FLOW IS THE DOCUMENTED ONE, and it is a session, not a bearer token:
 *
 *   POST {hub}/api/login.json   {"ms_request":{"user":{api_key, username, password}}}
 *                               with the password base64-encoded
 *   ms_response.user._token  -> sent as the _felix_session_id cookie
 *   GET  {hub}/api/v2/trackers/{id}/fetch-all-entries
 *   POST {hub}/api/logout
 *
 * THE PASSWORD IS STORED AS TYPED AND ENCODED ONLY IN login(). A stored
 * base64 string is the same secret with one more step to read it, and a value
 * stored encoded is one that some later reader encodes a second time.
 *
 * NO CREDENTIAL LEAVES THIS CLASS. Every message and every body it hands back
 * goes through scrub(), which replaces the API key, the password in both
 * forms and the session token with a note of their length, should the hub
 * ever echo one. Nothing here writes to a log.
 *
 * @package SFAF_Calendar
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SFAF_EveryAction {

    const DEFAULT_HUB     = 'https://hub.sfaf.org';
    const DEFAULT_TRACKER = '162570';

    /** Connection state. Disposable, like sfaf_gfmp_token: never a credential. */
    const STATUS_OPTION = 'sfaf_everyaction_status';

    /** The probe shows at most this much of a body, and says when it cut. */
    const PROBE_MAX_BODY = 250000;

    const TIMEOUT = 20;

    public function register() {
        add_action( 'wp_ajax_sfaf_everyaction_test', array( $this, 'ajax_test' ) );
        add_action( 'wp_ajax_sfaf_everyaction_probe', array( $this, 'ajax_probe' ) );
    }

    /* ---------------------------------------------------------------------
     * Configuration
     * ------------------------------------------------------------------- */

    /** The hub address, without a trailing slash. */
    public static function hub() {
        $hub = SFAF_Credentials::get( 'everyaction_hub_url', self::DEFAULT_HUB );
        return untrailingslashit( $hub );
    }

    public static function tracker_id() {
        return SFAF_Credentials::get( 'everyaction_tracker_id', self::DEFAULT_TRACKER );
    }

    /**
     * Everything the login needs, from storage, with anything given in $over
     * taking its place. The screen's Test button posts what is in the fields,
     * so it works before Save; a blank secret there means "the stored one".
     *
     * @param array $over hub, api_key, username, password, tracker.
     * @return array
     */
    public static function config( $over = array() ) {
        $cfg = array(
            'hub'      => self::hub(),
            'api_key'  => SFAF_Credentials::get( 'everyaction_api_key' ),
            'username' => SFAF_Credentials::get( 'everyaction_username' ),
            'password' => SFAF_Credentials::raw( 'everyaction_password' ),
            'tracker'  => self::tracker_id(),
        );
        foreach ( (array) $over as $k => $v ) {
            if ( ! array_key_exists( $k, $cfg ) || ! is_scalar( $v ) ) {
                continue;
            }
            $v = (string) $v;
            if ( 'password' === $k ) {
                // As typed: a password is opaque, and a space in it is part of it.
                if ( '' !== trim( $v ) ) {
                    $cfg[ $k ] = $v;
                }
                continue;
            }
            $v = trim( $v );
            if ( '' !== $v ) {
                $cfg[ $k ] = ( 'hub' === $k ) ? untrailingslashit( $v ) : $v;
            }
        }
        return $cfg;
    }

    /** What is missing before a login can even be tried, as words. */
    public static function missing( $cfg ) {
        $need = array(
            'hub'      => 'the hub address',
            'api_key'  => 'the API key',
            'username' => 'the username',
            'password' => 'the password',
            'tracker'  => 'the tracker ID',
        );
        $out = array();
        foreach ( $need as $k => $words ) {
            if ( '' === trim( (string) $cfg[ $k ] ) ) {
                $out[] = $words;
            }
        }
        return $out;
    }

    /* ---------------------------------------------------------------------
     * The three calls
     * ------------------------------------------------------------------- */

    /**
     * Replace every credential in a string with its length.
     *
     * @param string $text
     * @param array  $cfg
     * @param string $token
     * @return string
     */
    public static function scrub( $text, $cfg, $token = '' ) {
        $text    = (string) $text;
        $secrets = array( $cfg['api_key'], $cfg['password'], base64_encode( (string) $cfg['password'] ), $token );
        foreach ( $secrets as $secret ) {
            $secret = (string) $secret;
            if ( strlen( $secret ) >= 4 ) {
                $text = str_replace( $secret, '[hidden, ' . strlen( $secret ) . ' characters]', $text );
            }
        }
        return $text;
    }

    /** The hub's own words for a failure, if the body carries any. */
    public static function hub_message( $body ) {
        $json = json_decode( (string) $body, true );
        if ( ! is_array( $json ) ) {
            return '';
        }
        $found = '';
        $walk  = function ( $node ) use ( &$walk, &$found ) {
            if ( '' !== $found || ! is_array( $node ) ) {
                return;
            }
            if ( isset( $node['message'] ) && is_string( $node['message'] ) && '' !== trim( $node['message'] ) ) {
                $found = trim( $node['message'] );
                return;
            }
            foreach ( $node as $child ) {
                $walk( $child );
            }
        };
        $walk( isset( $json['ms_errors'] ) ? $json['ms_errors'] : ( isset( $json['error'] ) ? $json['error'] : array() ) );
        return $found;
    }

    /** A clause as a sentence: a full stop unless it already ends in one. The
     * hub ends some messages with its own, and two in a row reads as a fault. */
    private static function sentence( $text ) {
        $text = rtrim( (string) $text );
        return preg_match( '/[.!?]$/', $text ) ? $text : $text . '.';
    }

    /** One response, reduced to what the screen may say about it. */
    private static function reply( $response ) {
        if ( is_wp_error( $response ) ) {
            return array( 'ok' => false, 'status' => 0, 'type' => '', 'body' => '', 'net' => $response->get_error_message() );
        }
        $status = (int) wp_remote_retrieve_response_code( $response );
        return array(
            'ok'     => $status >= 200 && $status < 300,
            'status' => $status,
            'type'   => (string) wp_remote_retrieve_header( $response, 'content-type' ),
            'body'   => (string) wp_remote_retrieve_body( $response ),
            'net'    => '',
        );
    }

    /**
     * Why a step failed, in one clause: the hub's message, or the HTTP status,
     * or that the hub could not be reached at all.
     */
    private static function why( $r ) {
        if ( '' !== $r['net'] ) {
            return 'could not reach the hub (' . $r['net'] . ')';
        }
        $said = self::hub_message( $r['body'] );
        if ( '' !== $said ) {
            return $said;
        }
        return 'HTTP ' . $r['status'];
    }

    /**
     * Log in. The session token, or a WP_Error whose message says why.
     *
     * @param array $cfg
     * @return string|WP_Error
     */
    public static function login( $cfg ) {
        $body = array( 'ms_request' => array( 'user' => array(
            'api_key'  => (string) $cfg['api_key'],
            'username' => (string) $cfg['username'],
            'password' => base64_encode( (string) $cfg['password'] ),
        ) ) );

        $r = self::reply( wp_remote_post( $cfg['hub'] . '/api/login.json', array(
            'timeout'     => self::TIMEOUT,
            'redirection' => 0,
            'headers'     => array( 'Content-Type' => 'application/json', 'Accept' => 'application/json' ),
            'body'        => wp_json_encode( $body ),
        ) ) );

        if ( ! $r['ok'] || '' !== self::hub_message( $r['body'] ) ) {
            return new WP_Error( 'sfaf_ea_login', self::why( $r ) );
        }
        $json  = json_decode( $r['body'], true );
        $token = isset( $json['ms_response']['user']['_token'] ) ? (string) $json['ms_response']['user']['_token'] : '';
        if ( '' === $token ) {
            return new WP_Error( 'sfaf_ea_login', 'the hub answered without a session token' );
        }
        return $token;
    }

    /** The tracker's entries, one call. $query is added to the address. */
    public static function read_tracker( $cfg, $token, $query = array() ) {
        $url = $cfg['hub'] . '/api/v2/trackers/' . rawurlencode( (string) $cfg['tracker'] ) . '/fetch-all-entries';
        if ( ! empty( $query ) ) {
            $url = add_query_arg( $query, $url );
        }
        return self::reply( wp_remote_get( $url, array(
            'timeout'     => self::TIMEOUT,
            'redirection' => 0,
            'headers'     => array( 'Accept' => 'application/json', 'Cookie' => '_felix_session_id=' . $token ),
        ) ) );
    }

    public static function logout( $cfg, $token ) {
        return self::reply( wp_remote_post( $cfg['hub'] . '/api/logout', array(
            'timeout'     => self::TIMEOUT,
            'redirection' => 0,
            'headers'     => array( 'Accept' => 'application/json', 'Cookie' => '_felix_session_id=' . $token ),
        ) ) );
    }

    /**
     * Whether a tracker reply is data. A 200 can be a sign-in page: the hub
     * sends a browser surface to Microsoft Entra and answers 200 text/html,
     * which parses as a successful fetch and is the failure PROJECT.md 8 names.
     *
     * @return string '' when it is data, otherwise why not.
     */
    public static function not_data( $r ) {
        if ( ! $r['ok'] ) {
            return self::why( $r );
        }
        if ( null === json_decode( $r['body'], true ) ) {
            return ( false !== stripos( $r['type'], 'html' ) )
                ? 'the hub answered with a web page (' . $r['type'] . '), not data'
                : 'the hub answered with something that is not JSON (' . ( '' !== $r['type'] ? $r['type'] : 'no content type' ) . ')';
        }
        return '';
    }

    /**
     * How many rows a reply holds, and where that number came from.
     *
     * NOTHING HERE KNOWS THE SHAPE YET, which is the reason the probe exists.
     * A total the hub states is preferred; otherwise the first list of records
     * found is counted. Either way the path is returned, so the screen says
     * which number it is.
     *
     * @return array{rows:int|null,total:int|null,where:string}
     */
    public static function count_rows( $body ) {
        $json = json_decode( (string) $body, true );
        $out  = array( 'rows' => null, 'total' => null, 'where' => '' );
        if ( ! is_array( $json ) ) {
            return $out;
        }
        $totals = array( 'total_count', 'total_entries', 'total_records', 'entries_count', 'total' );
        $walk = function ( $node, $path, $depth ) use ( &$walk, &$out, $totals ) {
            if ( ! is_array( $node ) || $depth > 4 ) {
                return;
            }
            $is_list = array_keys( $node ) === range( 0, count( $node ) - 1 );
            if ( $is_list ) {
                if ( null === $out['rows'] && ( empty( $node ) || is_array( reset( $node ) ) ) ) {
                    $out['rows']  = count( $node );
                    $out['where'] = '' !== $path ? $path : '(top level)';
                }
                return;
            }
            foreach ( $node as $k => $v ) {
                if ( null === $out['total'] && in_array( (string) $k, $totals, true ) && is_numeric( $v ) ) {
                    $out['total'] = (int) $v;
                }
            }
            foreach ( $node as $k => $v ) {
                $walk( $v, ( '' !== $path ? $path . '.' : '' ) . $k, $depth + 1 );
            }
        };
        $walk( $json, '', 0 );
        return $out;
    }

    /* ---------------------------------------------------------------------
     * Test connection and the probe
     * ------------------------------------------------------------------- */

    /**
     * Log in, read one row, log out, and say what happened.
     *
     * @param array $over What the screen posted.
     * @return array{ok:bool,step:string,message:string,rows:int|null}
     */
    public static function test_connection( $over = array() ) {
        $cfg  = self::config( $over );
        $miss = self::missing( $cfg );
        if ( ! empty( $miss ) ) {
            return self::remember( false, 'setup', 'Not tried. Fill in ' . implode( ', ', $miss ) . ' first.', null, $cfg );
        }

        $token = self::login( $cfg );
        if ( is_wp_error( $token ) ) {
            return self::remember( false, 'login', self::sentence( 'Login failed: ' . $token->get_error_message() ), null, $cfg );
        }

        $r       = self::read_tracker( $cfg, $token, array( 'limit' => 1 ) );
        $bad     = self::not_data( $r );
        $counted = ( '' === $bad ) ? self::count_rows( $r['body'] ) : null;
        $out     = self::logout( $cfg, $token );

        if ( '' !== $bad ) {
            return self::remember( false, 'tracker', self::sentence( 'Tracker read failed: ' . $bad ), null, $cfg, $token );
        }

        $n = ( null !== $counted['total'] ) ? $counted['total'] : $counted['rows'];
        $message = ( null !== $counted['total'] )
            ? sprintf( 'Connected. Tracker reachable, %d %s.', $n, 1 === $n ? 'row' : 'rows' )
            : sprintf( 'Connected. Tracker reachable, %d %s in the reply; the hub gave no total.', (int) $n, 1 === (int) $n ? 'row' : 'rows' );

        if ( ! $out['ok'] ) {
            $message .= ' ' . self::sentence( 'Logout failed: ' . self::why( $out ) );
        }

        /* Proven, so kept, even if nobody presses Save. As typed: the
         * password is stored plain and encoded only inside login(). */
        foreach ( array( 'everyaction_hub_url' => 'hub', 'everyaction_api_key' => 'api_key', 'everyaction_username' => 'username',
                         'everyaction_password' => 'password', 'everyaction_tracker_id' => 'tracker' ) as $key => $field ) {
            SFAF_Credentials::set( $key, $cfg[ $field ] );
        }

        return self::remember( true, 'done', $message, $n, $cfg, $token );
    }

    /** Store the outcome in the disposable status option, and return it. */
    private static function remember( $ok, $step, $message, $rows, $cfg, $token = '' ) {
        $message = self::scrub( $message, $cfg, $token );
        update_option( self::STATUS_OPTION, array(
            'connected'  => (bool) $ok,
            'step'       => (string) $step,
            'message'    => $message,
            'rows'       => $rows,
            'checked_at' => time(),
        ), false );
        return array( 'ok' => (bool) $ok, 'step' => (string) $step, 'message' => $message, 'rows' => $rows );
    }

    /** What the last test found. Never a credential. */
    public static function status() {
        $s = get_option( self::STATUS_OPTION, array() );
        return array(
            'connected'  => ! empty( $s['connected'] ),
            'message'    => isset( $s['message'] ) ? (string) $s['message'] : '',
            'checked_at' => isset( $s['checked_at'] ) ? (int) $s['checked_at'] : 0,
        );
    }

    /**
     * Log in, read the first page, log out, and hand back the body as the hub
     * sent it. Reads nothing into the calendar and writes nothing anywhere,
     * the status option included: a probe is not a test.
     *
     * @return array
     */
    public static function probe( $over = array() ) {
        $cfg = self::config( $over );
        $out = array( 'ok' => false, 'error' => '', 'url' => '', 'status' => 0, 'type' => '', 'length' => 0,
                      'truncated' => false, 'body' => '', 'rows' => null, 'total' => null, 'where' => '', 'logout' => '' );

        $miss = self::missing( $cfg );
        if ( ! empty( $miss ) ) {
            $out['error'] = 'Not tried. Fill in ' . implode( ', ', $miss ) . ' first.';
            return $out;
        }

        $token = self::login( $cfg );
        if ( is_wp_error( $token ) ) {
            $out['error'] = self::scrub( self::sentence( 'Login failed: ' . $token->get_error_message() ), $cfg );
            return $out;
        }

        $r = self::read_tracker( $cfg, $token );
        $l = self::logout( $cfg, $token );

        $out['url']    = '/api/v2/trackers/' . rawurlencode( (string) $cfg['tracker'] ) . '/fetch-all-entries';
        $out['status'] = $r['status'];
        $out['type']   = $r['type'];
        $out['logout'] = $l['ok'] ? 'Logged out.' : self::scrub( self::sentence( 'Logout failed: ' . self::why( $l ) ), $cfg, $token );

        if ( '' !== $r['net'] ) {
            $out['error'] = self::scrub( self::sentence( 'Tracker read failed: ' . self::why( $r ) ), $cfg, $token );
            return $out;
        }

        $body          = self::scrub( $r['body'], $cfg, $token );
        $out['length'] = strlen( $r['body'] );
        if ( strlen( $body ) > self::PROBE_MAX_BODY ) {
            $out['truncated'] = true;
            $body             = substr( $body, 0, self::PROBE_MAX_BODY );
        }
        $out['body'] = $body;
        $out['ok']   = $r['ok'];

        $counted      = self::count_rows( $r['body'] );
        $out['rows']  = $counted['rows'];
        $out['total'] = $counted['total'];
        $out['where'] = $counted['where'];
        return $out;
    }

    /** What the screen posted, for config(). */
    private static function posted() {
        $over = array();
        foreach ( array( 'hub', 'api_key', 'username', 'password', 'tracker' ) as $k ) {
            if ( isset( $_POST[ $k ] ) && is_scalar( $_POST[ $k ] ) ) {
                $over[ $k ] = (string) wp_unslash( $_POST[ $k ] );
            }
        }
        return $over;
    }

    public function ajax_test() {
        check_ajax_referer( 'uc_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'You do not have permission to do that.' ), 403 );
        }
        $result = self::test_connection( self::posted() );
        if ( $result['ok'] ) {
            wp_send_json_success( $result );
        }
        wp_send_json_error( $result );
    }

    public function ajax_probe() {
        check_ajax_referer( 'uc_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'You do not have permission to do that.' ), 403 );
        }
        $result = self::probe( self::posted() );
        $result['max_body'] = self::PROBE_MAX_BODY;
        wp_send_json_success( $result );
    }
}
