<?php
/**
 * A MODEL OF THE HUB, for the EveryAction checks (3.100.0).
 *
 * Defines wp_remote_* and the ajax exits before the plugin loads, so
 * SFAF_EveryAction's real code talks to a hub that answers as $GLOBALS['hub']
 * says: a response array per step (login, tracker, logout), or a callable
 * returning one or a WP_Error. Every request is recorded in $GLOBALS['hub_seen'].
 * Required by everyaction-test.php and everyaction-live.php.
 */

/* ---- The model hub. ---- */
$GLOBALS['hub']      = array();
$GLOBALS['hub_seen'] = array();

function hub_resp( $code, $body, $type = 'application/json; charset=utf-8' ) {
    return array( 'response' => array( 'code' => $code, 'message' => '' ), 'headers' => array( 'content-type' => $type ), 'body' => $body );
}
function hub_route( $method, $url, $args ) {
    $GLOBALS['hub_seen'][] = array( 'method' => $method, 'url' => $url, 'args' => $args );
    $path = (string) parse_url( $url, PHP_URL_PATH );
    if ( '/api/login.json' === $path ) { $k = 'login'; }
    elseif ( '/api/logout' === $path ) { $k = 'logout'; }
    elseif ( preg_match( '#^/api/v2/trackers/[^/]+/fetch-all-entries$#', $path ) ) { $k = 'tracker'; }
    else { return hub_resp( 404, '' ); }
    $r = isset( $GLOBALS['hub'][ $k ] ) ? $GLOBALS['hub'][ $k ] : hub_resp( 500, '' );
    return is_callable( $r ) ? $r( $args ) : $r;
}
function wp_remote_post( $url, $args = array() ) { return hub_route( 'POST', $url, $args ); }
function wp_remote_get( $url, $args = array() ) { return hub_route( 'GET', $url, $args ); }
function wp_remote_retrieve_response_code( $r ) { return is_array( $r ) ? $r['response']['code'] : ''; }
function wp_remote_retrieve_response_message( $r ) { return is_array( $r ) ? $r['response']['message'] : ''; }
function wp_remote_retrieve_body( $r ) { return is_array( $r ) ? $r['body'] : ''; }
function wp_remote_retrieve_header( $r, $h ) { return is_array( $r ) && isset( $r['headers'][ $h ] ) ? $r['headers'][ $h ] : ''; }

/* ---- Ajax, answered by exception so a handler's exit is observable. ---- */
class Kit_Json extends Exception { public $payload; function __construct( $p ) { $this->payload = $p; parent::__construct( 'json' ); } }
function wp_send_json_success( $d = null, $code = null ) { throw new Kit_Json( array( 'success' => true, 'data' => $d ) ); }
function wp_send_json_error( $d = null, $code = null ) { throw new Kit_Json( array( 'success' => false, 'data' => $d ) ); }
function check_ajax_referer( $a = -1, $q = false, $die = true ) { return 1; }
function human_time_diff( $a, $b = 0 ) { return '1 hour'; }

