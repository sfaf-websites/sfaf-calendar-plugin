<?php
/**
 * EveryAction events, through the MangoApps tracker on the hub (3.100.0), and
 * the import from 3.101.0.
 *
 * THIS CLASS TALKS TO THE TWO PLACES THE IMPORT READS. The hub, for the rows,
 * and the public events list, for each event's signup page. The adapter that
 * turns rows into events is SFAF_Source_EveryAction; this class holds the
 * session, the paged read, the list reader and what the panel shows.
 *
 * THE FLOW IS THE DOCUMENTED ONE, and it is a session, not a bearer token:
 *
 *   POST {hub}/api/login.json   {"ms_request":{"user":{api_key, username, password}}}
 *                               with the password base64-encoded
 *   ms_response.user._token  -> sent as the _felix_session_id cookie
 *   GET  {hub}/api/trackers/forms/get_submissions/{id}   rows at ms_response.data
 *        and ?page=N after the first, see read_rows()
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

    /** Where the tracker read puts the rows (3.100.2). */
    const ROWS_AT = 'ms_response.data';

    /** The tracker answers a hundred rows a read; fewer means the last page. */
    const PER_PAGE = 100;

    /** A ceiling on reads per fetch, far above the tracker's size. */
    const MAX_PAGES = 50;

    /** The public events list, and how many entries it shows a page. */
    const LIST_URL       = 'https://50plus.sfaf.org/a/asevents';
    const LIST_PAGE_SIZE = 10;
    const LIST_MAX_PAGES = 40;

    /** How often the runner reads the list. */
    const LINKS_EVERY = 86400;

    /**
     * UUID => signup address, from the last good read of the list. Replaced
     * whole by each good read, and never by a failed or empty one.
     */
    const LINKS_OPTION = 'sfaf_everyaction_links';

    /** The last read of the list: when, whether it worked, how many matched. */
    const LINKS_RUN_OPTION = 'sfaf_everyaction_links_run';

    /** The last fetch: when, rows read, created, updated, or what failed. */
    const FETCH_OPTION = 'sfaf_everyaction_fetch';

    public function register() {
        add_action( 'wp_ajax_sfaf_everyaction_test', array( $this, 'ajax_test' ) );
        add_action( 'wp_ajax_sfaf_everyaction_probe', array( $this, 'ajax_probe' ) );
    }

    /** Whether the scheduled runner fetches EveryAction. Off unless ticked. */
    public static function auto_import() {
        $settings = get_option( 'uc_settings', array() );
        return is_array( $settings ) && isset( $settings['everyaction_auto_import'] ) && '1' === (string) $settings['everyaction_auto_import'];
    }

    /** Whether everything a login needs is stored. */
    public static function configured() {
        return empty( self::missing( self::config() ) );
    }

    /* ---------------------------------------------------------------------
     * Configuration
     * ------------------------------------------------------------------- */

    /** The hub address, without a trailing slash. */
    public static function hub() {
        return self::origin( SFAF_Credentials::get( 'everyaction_hub_url', self::DEFAULT_HUB ) );
    }

    /**
     * The hub as scheme, host and port, and nothing after it (3.100.1).
     *
     * Every call appends its own path, so an address typed with one, such as
     * `https://hub.sfaf.org/api/` or the login URL itself, would double it:
     * `/api/api/login.json`. The hub is a host, so only the host is kept.
     */
    public static function origin( $url ) {
        $url   = trim( (string) $url );
        $parts = wp_parse_url( $url );
        if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
            return untrailingslashit( $url );
        }
        return strtolower( $parts['scheme'] ) . '://' . $parts['host'] . ( ! empty( $parts['port'] ) ? ':' . (int) $parts['port'] : '' );
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
                $cfg[ $k ] = ( 'hub' === $k ) ? self::origin( $v ) : $v;
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
        /* {"status":500,"error":"Internal Server Error"}: the hub's own shape
         * for a crash, with the words as a plain string. */
        if ( isset( $json['error'] ) && is_string( $json['error'] ) && '' !== trim( $json['error'] ) ) {
            return trim( $json['error'] );
        }
        /* {"ms_error":"You don't have permission."}: the tracker read's
         * refusal, answered 200 (3.100.2). */
        if ( isset( $json['ms_error'] ) && is_string( $json['ms_error'] ) && '' !== trim( $json['ms_error'] ) ) {
            return trim( $json['ms_error'] );
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
    public static function sentence( $text ) {
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

        /* A 2xx that carries ms_errors is the hub's structured refusal, and
         * its sentence is the whole answer: "Login id or Password is
         * Incorrect." The status adds nothing. */
        if ( $r['ok'] && '' !== $said ) {
            return $said;
        }

        /* ANY OTHER FAILURE GIVES THE STATUS, AND THEN WHAT THE HUB SAID
         * (3.100.1). "HTTP 422" alone left nobody able to tell what the hub
         * had not liked; the body is the only place that says. A JSON message
         * first, then a short plain-text body as it came. A web page is named,
         * not pasted. The caller scrubs credentials out of all of it. */
        $out = 'HTTP ' . $r['status'];
        if ( '' === $said ) {
            $plain = trim( preg_replace( '/\s+/', ' ', (string) $r['body'] ) );
            if ( '' !== $plain ) {
                if ( false !== stripos( $r['type'], 'html' ) || '<' === substr( $plain, 0, 1 ) ) {
                    return $out . ', and the hub sent a web page';
                }
                $said = ( strlen( $plain ) > 300 ) ? substr( $plain, 0, 300 ) . '...' : $plain;
            }
        }
        return '' !== $said ? $out . ', and the hub said "' . $said . '"' : $out;
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
            // A STRING, SO WORDPRESS SENDS IT AS IT IS. An array here is sent
            // form-encoded, which is not the request the hub documents. The
            // two flags make the bytes the ones a hand-written body has: a
            // slash in the base64 password stays a slash rather than "\/",
            // and a name stays as typed. Checked against WordPress 7.1.2's own
            // HTTP layer and against curl in 3.100.1: the bodies match to the
            // byte. See .claude/everyaction-test.php.
            'body'        => wp_json_encode( $body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
        ) ) );

        if ( ! $r['ok'] || '' !== self::hub_message( $r['body'] ) ) {
            $why = self::why( $r );
            /* THE ONE STATUS THE HUB HAS BEEN SEEN TO GIVE A WELL-FORMED
             * LOGIN (3.100.1): 422 with "ok", for an API key it does not
             * recognise, whatever the username and password. With a key it
             * knows, the same request is answered 200 with ms_errors. */
            if ( 422 === $r['status'] ) {
                $why = self::sentence( $why ) . ' The hub answers this way when it does not recognise the API key: check the key';
            }
            return new WP_Error( 'sfaf_ea_login', $why );
        }
        $json  = json_decode( $r['body'], true );
        $token = isset( $json['ms_response']['user']['_token'] ) ? (string) $json['ms_response']['user']['_token'] : '';
        if ( '' === $token ) {
            return new WP_Error( 'sfaf_ea_login', 'the hub answered without a session token' );
        }
        return $token;
    }

    /**
     * The tracker read's path (3.100.2). THE VERSION 1 READ, because the hub
     * refuses the version 2 one, /api/v2/trackers/{id}/fetch-all-entries, for
     * this tracker with a 200 carrying "You don't have permission." The same
     * session is given the rows here. PROJECT.md 3 has the row shape.
     */
    public static function tracker_path( $cfg ) {
        return '/api/trackers/forms/get_submissions/' . rawurlencode( (string) $cfg['tracker'] );
    }

    /** One read of the tracker. $page 0 is the bare address. */
    public static function read_tracker( $cfg, $token, $page = 0 ) {
        $url = $cfg['hub'] . self::tracker_path( $cfg );
        if ( $page > 0 ) {
            $url = add_query_arg( array( 'page' => (int) $page ), $url );
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
     * which parses as a successful fetch and is the failure PROJECT.md 3 names.
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
        /* A 200 can be a refusal too: {"ms_error":"You don't have permission."}. */
        if ( '' !== self::hub_message( $r['body'] ) ) {
            return self::why( $r );
        }
        if ( null === self::count_rows( $r['body'] ) ) {
            return 'the hub answered without a list of rows at ' . self::ROWS_AT;
        }
        return '';
    }

    /**
     * How many rows a reply holds at ms_response.data, or null when there is
     * no list there.
     *
     * @return int|null
     */
    public static function count_rows( $body ) {
        $json = json_decode( (string) $body, true );
        if ( ! isset( $json['ms_response']['data'] ) || ! is_array( $json['ms_response']['data'] ) ) {
            return null;
        }
        $data = $json['ms_response']['data'];
        return ( array() === $data || array_keys( $data ) === range( 0, count( $data ) - 1 ) ) ? count( $data ) : null;
    }

    /** The rows in one tracker reply, or an empty list. */
    public static function rows_in( $body ) {
        $json = json_decode( (string) $body, true );
        if ( ! isset( $json['ms_response']['data'] ) || ! is_array( $json['ms_response']['data'] ) ) {
            return array();
        }
        return array_values( array_filter( $json['ms_response']['data'], 'is_array' ) );
    }

    /* ---------------------------------------------------------------------
     * The whole tracker (3.101.0)
     * ------------------------------------------------------------------- */

    /**
     * Every row, read a page at a time until a page comes back short.
     *
     * THE PAGE PARAMETER IS NOT DOCUMENTED. The hub's apidoc lists `id` and
     * nothing else for this read, and `page` is what the rest of its version 1
     * API pages with. So the read does not trust it, it checks it:
     *
     *   - The bare address is the first page.
     *   - `page=1` is asked next. If it repeats the first page, pages count
     *     from 1 and the read carries on at 2; if it does not, they count from
     *     0 and it is already the second page. Either way nothing is skipped.
     *   - A full page that adds no row not already read means the hub ignored
     *     the parameter, or went round to the start. The read stops there and
     *     says the list may be incomplete, which is what keeps the removal
     *     step from running on it. It never loops and never counts a row twice.
     *
     * @param array  $cfg
     * @param string $token
     * @return array{rows:array,complete:bool,reason:string,reads:int,error:string}
     */
    public static function read_rows( $cfg, $token ) {
        $out  = array( 'rows' => array(), 'complete' => false, 'reason' => '', 'reads' => 0, 'error' => '' );
        $seen = array();

        for ( $page = 0; $page <= self::MAX_PAGES; $page++ ) {
            $r   = self::read_tracker( $cfg, $token, $page );
            $bad = self::not_data( $r );
            if ( '' !== $bad ) {
                $out['error'] = ( 0 === $page )
                    ? self::sentence( 'Tracker read failed: ' . $bad )
                    : self::sentence( sprintf( 'Tracker read failed after %d rows: %s', count( $out['rows'] ), $bad ) );
                return $out;
            }
            $out['reads']++;

            $rows = self::rows_in( $r['body'] );
            $new  = 0;
            foreach ( $rows as $row ) {
                $key = isset( $row['UUID'] ) && '' !== trim( (string) $row['UUID'] ) ? 'u:' . trim( (string) $row['UUID'] ) : 'r:' . md5( wp_json_encode( $row ) );
                if ( isset( $seen[ $key ] ) ) {
                    continue;
                }
                $seen[ $key ]  = true;
                $out['rows'][] = $row;
                $new++;
            }

            if ( count( $rows ) < self::PER_PAGE ) {
                $out['complete'] = true;
                return $out;
            }
            if ( 1 === $page && 0 === $new ) {
                continue; // page=1 was the first page again: pages count from 1
            }
            if ( $page > 0 && 0 === $new ) {
                $out['reason'] = 'the tracker gave the same rows twice, so its pages could not be told apart and the list may be incomplete';
                return $out;
            }
        }

        $out['reason'] = sprintf( 'the tracker ran past %d pages, so the list may be incomplete', self::MAX_PAGES );
        return $out;
    }

    /* ---------------------------------------------------------------------
     * The signup links, from the public events list (3.101.0)
     * ------------------------------------------------------------------- */

    /**
     * Pairs of event id and signup address from one page of the public list.
     *
     * Every entry is counted, with or without a link, because the page size is
     * what says whether this was the last page. Only an entry carrying both an
     * id and an address becomes a pair.
     *
     * @param string $html
     * @return array<int,array{id:string,href:string}>
     */
    public static function parse_list( $html ) {
        $html = (string) $html;
        if ( '' === trim( $html ) ) {
            return array();
        }
        $doc  = new DOMDocument();
        $prev = libxml_use_internal_errors( true );
        $doc->loadHTML( '<?xml encoding="utf-8"?>' . $html );
        libxml_clear_errors();
        libxml_use_internal_errors( $prev );

        $xp  = new DOMXPath( $doc );
        $out = array();
        foreach ( $xp->query( "//div[contains(concat(' ', normalize-space(@class), ' '), ' oa-event-result-container ')]" ) as $div ) {
            $id   = trim( (string) $div->getAttribute( 'data-event-id' ) );
            $href = '';
            /* RELATIVE TO THIS ENTRY: the leading dot. Without it the query
             * searches the whole page and every entry gets the first link. */
            $a = $xp->query( ".//a[contains(concat(' ', normalize-space(@class), ' '), ' oa-event-result-signup-link ')]", $div )->item( 0 );
            if ( $a ) {
                $href = trim( (string) $a->getAttribute( 'href' ) );
                if ( ! preg_match( '#^https?://#i', $href ) ) {
                    $href = '';
                }
            }
            $out[] = array( 'id' => $id, 'href' => $href );
        }
        return $out;
    }

    /**
     * Read the whole list, page by page through ?pn=N.
     *
     * PAST ITS LAST PAGE THE LIST SHOWS PAGE ONE AGAIN, measured on 2026-09-24:
     * pn=16 and pn=40 both answer with page one's entries. So a short page
     * ends the read, and so does a page whose entries were all read already,
     * which is the case a list of exactly a multiple of ten would reach.
     *
     * @return array{ok:bool,pairs:array,entries:int,pages:int,error:string}
     */
    public static function read_links() {
        $out  = array( 'ok' => false, 'pairs' => array(), 'entries' => 0, 'pages' => 0, 'error' => '' );
        $seen = array();

        for ( $n = 1; $n <= self::LIST_MAX_PAGES; $n++ ) {
            $url = ( 1 === $n ) ? self::LIST_URL : add_query_arg( array( 'pn' => $n ), self::LIST_URL );
            $r   = self::reply( wp_remote_get( $url, array(
                'timeout'     => self::TIMEOUT,
                'redirection' => 3,
                'headers'     => array( 'Accept' => 'text/html' ),
            ) ) );
            if ( ! $r['ok'] ) {
                $out['error'] = self::sentence( sprintf( 'The events list could not be read at page %d: %s', $n, self::why( $r ) ) );
                return $out;
            }
            $found = self::parse_list( $r['body'] );
            $out['pages']++;

            $fresh = 0;
            foreach ( $found as $entry ) {
                $key = '' !== $entry['id'] ? $entry['id'] : md5( $entry['href'] );
                if ( isset( $seen[ $key ] ) ) {
                    continue;
                }
                $seen[ $key ] = true;
                $fresh++;
                $out['entries']++;
                if ( '' !== $entry['id'] && '' !== $entry['href'] ) {
                    $out['pairs'][ $entry['id'] ] = $entry['href'];
                }
            }

            if ( count( $found ) < self::LIST_PAGE_SIZE || 0 === $fresh ) {
                break;
            }
        }

        if ( empty( $out['pairs'] ) ) {
            $out['error'] = 'The events list was read and held no signup links, so the last good set was kept.';
            return $out;
        }
        $out['ok'] = true;
        return $out;
    }

    /** UUID => signup address, from the last good read. */
    public static function links() {
        $stored = get_option( self::LINKS_OPTION, array() );
        return ( is_array( $stored ) && isset( $stored['pairs'] ) && is_array( $stored['pairs'] ) ) ? $stored['pairs'] : array();
    }

    /** When the list was last read successfully, or 0. */
    public static function links_read_at() {
        $stored = get_option( self::LINKS_OPTION, array() );
        return is_array( $stored ) && isset( $stored['at'] ) ? (int) $stored['at'] : 0;
    }

    /**
     * The list itself, filtered to one day: where the button goes for an event
     * whose own page the list does not show yet.
     *
     * The list's date filter is a form, and its own redirect turns that into
     * date_start and date_end as MM-DD-YYYY, which it also reads from a plain
     * link. Checked 2026-09-24: DateFrom and DateTo in the address are ignored.
     *
     * @param string $date Y-m-d.
     * @return string
     */
    public static function day_url( $date ) {
        $d = DateTime::createFromFormat( 'Y-m-d', (string) $date );
        if ( ! $d ) {
            return self::LIST_URL;
        }
        $day = $d->format( 'm-d-Y' );
        return self::LIST_URL . '?date_start=' . $day . '&date_end=' . $day;
    }

    /** The event's own signup page from the last read, or '' when the list has none. */
    public static function matched_url( $uuid ) {
        $links = self::links();
        $uuid  = (string) $uuid;
        return isset( $links[ $uuid ] ) ? (string) $links[ $uuid ] : '';
    }

    /** Whether an address is the list's own, filtered or not, rather than an event's page. */
    public static function is_list_url( $url ) {
        return 0 === strpos( (string) $url, self::LIST_URL );
    }

    /**
     * Imported EveryAction events a fetch may still write, as id => UUID.
     *
     * @return array<int,string>
     */
    private static function imported_events() {
        $q = new WP_Query( array(
            'post_type'              => 'uc_event',
            'post_status'            => SFAF_Sources::updatable_statuses(),
            'posts_per_page'         => 1000,
            'fields'                 => 'ids',
            'no_found_rows'          => true,
            'ignore_sticky_posts'    => true,
            'update_post_meta_cache' => true,
            'update_post_term_cache' => false,
            'meta_query'             => array(
                array( 'key' => SFAF_Sources::META_SOURCE, 'value' => SFAF_Source_EveryAction::SLUG ),
            ),
        ) );
        $out = array();
        foreach ( $q->posts as $id ) {
            $out[ (int) $id ] = (string) get_post_meta( (int) $id, SFAF_Sources::META_EXTERNAL_ID, true );
        }
        return $out;
    }

    /**
     * How many upcoming imported events the list has a page for.
     *
     * The number the panel shows beside the read, so a change to the list's
     * markup shows as this dropping rather than as nothing at all.
     *
     * @return array{matched:int,upcoming:int}
     */
    public static function count_matches() {
        $links = self::links();
        $today = SFAF_Sources::today();
        $out   = array( 'matched' => 0, 'upcoming' => 0 );
        foreach ( self::imported_events() as $id => $uuid ) {
            $date = (string) get_post_meta( $id, '_uc_event_date', true );
            if ( '' === $date || $date < $today ) {
                continue;
            }
            $out['upcoming']++;
            if ( '' !== $uuid && isset( $links[ $uuid ] ) ) {
                $out['matched']++;
            }
        }
        return $out;
    }

    /**
     * Read the list, keep the pairs, and give every imported event the address
     * it now has. An event the list has no page for keeps the one it has,
     * which is its day on the list until the id appears.
     *
     * @return array{ok:bool,message:string,pairs:int,matched:int,upcoming:int,changed:int}
     */
    public static function refresh_links() {
        $read    = self::read_links();
        $changed = 0;

        if ( $read['ok'] ) {
            update_option( self::LINKS_OPTION, array( 'at' => time(), 'pairs' => $read['pairs'] ), false );
            foreach ( self::imported_events() as $id => $uuid ) {
                if ( '' === $uuid || ! isset( $read['pairs'][ $uuid ] ) ) {
                    continue;
                }
                $url = esc_url_raw( $read['pairs'][ $uuid ] );
                if ( '' !== $url && (string) get_post_meta( $id, SFAF_Sources::META_SOURCE_URL, true ) !== $url ) {
                    update_post_meta( $id, SFAF_Sources::META_SOURCE_URL, $url );
                    $changed++;
                }
            }
        }

        $counts = self::count_matches();
        $run    = array(
            'at'       => time(),
            'ok'       => (bool) $read['ok'],
            'message'  => $read['ok'] ? '' : $read['error'],
            'pairs'    => count( $read['ok'] ? $read['pairs'] : self::links() ),
            'matched'  => $counts['matched'],
            'upcoming' => $counts['upcoming'],
            'changed'  => $changed,
        );
        update_option( self::LINKS_RUN_OPTION, $run, false );
        return $run;
    }

    /**
     * The runner's task: the list once a day, paced like the orphan check.
     *
     * @return array{status:string,summary:string,counts:array}
     */
    public static function run_links() {
        $last = get_option( self::LINKS_RUN_OPTION, array() );
        if ( is_array( $last ) && ! empty( $last['at'] ) && ( time() - (int) $last['at'] ) < self::LINKS_EVERY ) {
            return array( 'status' => 'ok', 'summary' => 'Read within the last day already.', 'counts' => array() );
        }
        $run = self::refresh_links();
        if ( ! $run['ok'] ) {
            return array( 'status' => 'failed', 'summary' => $run['message'], 'counts' => array() );
        }
        return array(
            'status'  => 'ok',
            'summary' => sprintf( '%d signup links read; %d of %d upcoming imported events matched.', $run['pairs'], $run['matched'], $run['upcoming'] ),
            'counts'  => array( 'matched' => $run['matched'], 'upcoming' => $run['upcoming'], 'changed' => $run['changed'] ),
        );
    }

    /** The last read of the list, for the panel. */
    public static function links_run() {
        $run = get_option( self::LINKS_RUN_OPTION, array() );
        return is_array( $run ) ? $run : array();
    }

    /** The last fetch, for the panel. */
    public static function last_fetch() {
        $f = get_option( self::FETCH_OPTION, array() );
        return is_array( $f ) ? $f : array();
    }

    /** Record a fetch from its run_adapter() result. */
    public static function record_fetch( $result ) {
        update_option( self::FETCH_OPTION, array(
            'at'      => time(),
            'rows'    => isset( $result['fetched'] ) ? (int) $result['fetched'] : 0,
            'new'     => isset( $result['new'] ) ? (int) $result['new'] : 0,
            'updated' => isset( $result['updated'] ) ? (int) $result['updated'] : 0,
            'error'   => isset( $result['error'] ) ? (string) $result['error'] : '',
        ), false );
    }

    /* ---------------------------------------------------------------------
     * Test connection and the probe
     * ------------------------------------------------------------------- */

    /**
     * Log in, read the tracker, log out, and say what happened.
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

        $r   = self::read_tracker( $cfg, $token );
        $bad = self::not_data( $r );
        $out = self::logout( $cfg, $token );

        if ( '' !== $bad ) {
            return self::remember( false, 'tracker', self::sentence( 'Tracker read failed: ' . $bad ), null, $cfg, $token );
        }

        $n       = self::count_rows( $r['body'] );
        $message = sprintf( 'Connected. Tracker reachable, %d %s.', $n, 1 === $n ? 'row' : 'rows' );

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
     * Log in, read the tracker, log out, and hand back the body as the hub
     * sent it. Reads nothing into the calendar and writes nothing anywhere,
     * the status option included: a probe is not a test.
     *
     * @return array
     */
    public static function probe( $over = array() ) {
        $cfg = self::config( $over );
        $out = array( 'ok' => false, 'error' => '', 'url' => '', 'status' => 0, 'type' => '', 'length' => 0,
                      'truncated' => false, 'body' => '', 'rows' => null, 'where' => self::ROWS_AT, 'problem' => '', 'logout' => '' );

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

        $out['url']    = self::tracker_path( $cfg );
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

        /* The body is printed either way; a refusal is also said, in the
         * words Test connection would use. */
        $bad            = self::not_data( $r );
        $out['ok']      = ( '' === $bad );
        $out['problem'] = ( '' === $bad ) ? '' : self::scrub( self::sentence( 'Tracker read failed: ' . $bad ), $cfg, $token );
        $out['rows']    = self::count_rows( $r['body'] );
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
