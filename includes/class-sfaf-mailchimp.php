<?php
/**
 * MAILCHIMP: A TEAM'S OWN LIST, FROM THE RSVP FORM (3.124.0).
 *
 * Aging Services, and later Black Health, keep their own Mailchimp accounts.
 * Somebody registering for one of their events can tick "Sign me up for
 * [team] email updates", and is added to that team's audience once the
 * registration is confirmed. NOTHING ABOUT MAILCHIMP APPEARS ANYWHERE until an
 * administrator has set up a connection and assigned it organizers: no card on
 * the editors, no tick on the form, no task.
 *
 * WHAT A CONNECTION IS
 * ---------------------------------------------------------------------------
 * A name (the team), an API key, the default audience, the organizers it
 * applies to and optional fixed tags, in the option self::OPTION. An organizer
 * belongs to one connection at most, so an event's ticks are one per
 * connection, never two for one team. Kept in WP admin Settings, under
 * manage_options: a key to somebody else's mailing list is configuration.
 *
 * THE KEY IS NEVER SHOWN, LOGGED OR SENT ANYWHERE BUT MAILCHIMP. It is held in
 * sfaf_credentials (never uc_settings, CLAUDE.md 7) under
 * 'mailchimp_key_' . id, encrypted with a key derived from the site's own
 * secret keys (wp_salt), so a database copy alone does not give it away. If
 * those secret keys change, the stored key no longer opens and the screen says
 * to enter it again. The screen shows only its last four characters. Every
 * message that leaves this class is built from Mailchimp's answer with the key
 * scrubbed out of it.
 *
 * ONE RECORD OF CONSENT. A tick is a row in uc_optins, as the SFAF one has
 * been since 3.62.0, with the list it was for (SFAF_Optins). The send to
 * Mailchimp is a separate thing: a row in self::table(), the queue, sent by a
 * single scheduled event so the form never waits on Mailchimp, retried on
 * failure, and every attempt logged on the row. Failures are shown on the
 * connection screen with a Retry.
 *
 * WHAT IS SENT. New to the audience: subscribed at once (Mark, 2026-10-26: the
 * form is the consent, and uc_optins says when and where), with first name,
 * last name and the tags. Already subscribed, or pending: the tags only, so a
 * name they gave Mailchimp is never overwritten. Archived, or a contact the
 * account only ever emailed (transactional): subscribed again with the tags
 * (Mark, 2026-10-26: they ticked the box). Unsubscribed or cleaned: NOTHING,
 * not even a tag, and the row says so. The event name is never a tag.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class SFAF_Mailchimp {

    /** id => connection. See connections() for the shape. */
    const OPTION = 'sfaf_mailchimp_connections';

    /** Event meta: connection id => array( show, audience, tags ). */
    const META_EVENT = '_uc_mailchimp';

    /** Series term meta: connection id => array( audience, tags ). */
    const META_SERIES = '_sfaf_series_mailchimp';

    /** The single event the queue is sent by. Not a recurring task. */
    const CRON_HOOK = 'sfaf_mailchimp_send';

    /** Seconds to wait after each failed attempt; after the last, the row has failed. */
    const RETRY_AFTER = array( 300, 1800, 7200, 43200 );

    /** How long a connection's audiences and tags are kept before being read again. */
    const META_TTL = 43200;

    public static function register() {
        add_action( self::CRON_HOOK, array( __CLASS__, 'process_queue' ) );
        add_action( 'wp_ajax_sfaf_mailchimp_test', array( __CLASS__, 'ajax_test' ) );
        add_action( 'admin_post_sfaf_mailchimp_save', array( __CLASS__, 'handle_save' ) );
        add_action( 'admin_post_sfaf_mailchimp_delete', array( __CLASS__, 'handle_delete' ) );
        add_action( 'admin_post_sfaf_mailchimp_retry', array( __CLASS__, 'handle_retry' ) );
    }

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'uc_mailchimp_queue';
    }

    /* ---------------------------------------------------------------------
     * Connections
     * ------------------------------------------------------------------- */

    /**
     * Every connection, in the order they were added.
     *
     * @return array<string,array> id => array( id, name, audience, audience_name, organizers int[], tags string[] )
     */
    public static function connections() {
        $stored = get_option( self::OPTION, array() );
        $out    = array();
        foreach ( is_array( $stored ) ? $stored : array() as $id => $c ) {
            $id = sanitize_key( (string) $id );
            if ( '' === $id || ! is_array( $c ) ) {
                continue;
            }
            $out[ $id ] = array(
                'id'            => $id,
                'name'          => isset( $c['name'] ) ? (string) $c['name'] : '',
                'audience'      => isset( $c['audience'] ) ? (string) $c['audience'] : '',
                'audience_name' => isset( $c['audience_name'] ) ? (string) $c['audience_name'] : '',
                'organizers'    => array_values( array_filter( array_map( 'intval', isset( $c['organizers'] ) ? (array) $c['organizers'] : array() ) ) ),
                'tags'          => self::clean_tags( isset( $c['tags'] ) ? $c['tags'] : array() ),
            );
        }
        return $out;
    }

    /** One connection, or null. */
    public static function get( $id ) {
        $all = self::connections();
        $id  = sanitize_key( (string) $id );
        return isset( $all[ $id ] ) ? $all[ $id ] : null;
    }

    /** Whether any connection has an organizer: the switch for everything else. */
    public static function active() {
        foreach ( self::connections() as $c ) {
            if ( $c['organizers'] ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Tags as Mailchimp takes them: trimmed, no empties, no repeats (ignoring
     * case), 100 characters at most. From an array or a comma-separated string.
     *
     * @param array|string $tags
     * @return string[]
     */
    public static function clean_tags( $tags ) {
        if ( is_string( $tags ) ) {
            $tags = explode( ',', $tags );
        }
        $out  = array();
        $seen = array();
        foreach ( (array) $tags as $t ) {
            $t = trim( preg_replace( '/\s+/', ' ', sanitize_text_field( (string) $t ) ) );
            if ( '' === $t ) {
                continue;
            }
            $t   = function_exists( 'mb_substr' ) ? mb_substr( $t, 0, 100 ) : substr( $t, 0, 100 );
            $low = strtolower( $t );
            if ( isset( $seen[ $low ] ) ) {
                continue;
            }
            $seen[ $low ] = true;
            $out[]        = $t;
        }
        return $out;
    }

    /**
     * Add or change a connection.
     *
     * @param string      $id     '' for a new one.
     * @param array       $fields name, audience, organizers, tags.
     * @param string|null $key    A new API key, or null/'' to keep the stored one.
     * @return array( 'ok' => bool, 'id' => string, 'message' => string )
     */
    public static function save( $id, $fields, $key = null ) {
        $all = get_option( self::OPTION, array() );
        $all = is_array( $all ) ? $all : array();
        $id  = sanitize_key( (string) $id );
        $new = ( '' === $id || ! isset( $all[ $id ] ) );
        if ( $new ) {
            $id = 'c' . substr( md5( uniqid( '', true ) . wp_rand() ), 0, 8 );
        }

        $name = trim( sanitize_text_field( isset( $fields['name'] ) ? (string) $fields['name'] : '' ) );
        if ( '' === $name ) {
            return array( 'ok' => false, 'id' => $id, 'message' => 'Give the connection a name, such as the team\'s.' );
        }
        $key = null === $key ? '' : trim( (string) $key );
        if ( $new && '' === $key ) {
            return array( 'ok' => false, 'id' => $id, 'message' => 'Paste the account\'s API key.' );
        }
        if ( '' !== $key && '' === self::dc( $key ) ) {
            return array( 'ok' => false, 'id' => $id, 'message' => 'That is not a Mailchimp API key. It ends in a dash and the data centre, such as -us21.' );
        }

        // An organizer belongs to one connection. Taken ones are refused by name.
        $orgs  = array_values( array_unique( array_filter( array_map( 'intval', isset( $fields['organizers'] ) ? (array) $fields['organizers'] : array() ) ) ) );
        $orgs  = array_values( array_filter( $orgs, array( 'SFAF_Organizers', 'exists' ) ) );
        $taken = array();
        foreach ( self::connections() as $other ) {
            if ( $other['id'] === $id ) {
                continue;
            }
            foreach ( array_intersect( $orgs, $other['organizers'] ) as $o ) {
                $term    = get_term( $o, SFAF_Organizers::TAXONOMY );
                $taken[] = ( $term && ! is_wp_error( $term ) ? $term->name : '#' . $o ) . ' (' . $other['name'] . ')';
            }
        }
        if ( $taken ) {
            return array( 'ok' => false, 'id' => $id, 'message' => 'An organizer can have one connection. Already taken: ' . implode( ', ', $taken ) . '.' );
        }

        $audience      = sanitize_text_field( isset( $fields['audience'] ) ? (string) $fields['audience'] : '' );
        $audience_name = '';
        $meta          = self::meta( $id, false, '' !== $key ? $key : null );
        foreach ( $meta['audiences'] as $a ) {
            if ( $a['id'] === $audience ) {
                $audience_name = $a['name'];
            }
        }
        // The name as last saved, when Mailchimp could not be asked just now.
        if ( '' === $audience_name && isset( $all[ $id ]['audience'], $all[ $id ]['audience_name'] ) && $audience === $all[ $id ]['audience'] ) {
            $audience_name = (string) $all[ $id ]['audience_name'];
        }
        if ( '' === $audience && 1 === count( $meta['audiences'] ) ) {
            $audience      = $meta['audiences'][0]['id'];
            $audience_name = $meta['audiences'][0]['name'];
        }

        if ( '' !== $key && ! self::set_key( $id, $key ) ) {
            return array( 'ok' => false, 'id' => $id, 'message' => 'The key could not be stored.' );
        }
        $all[ $id ] = array(
            'name'          => $name,
            'audience'      => $audience,
            'audience_name' => $audience_name,
            'organizers'    => $orgs,
            'tags'          => self::clean_tags( isset( $fields['tags'] ) ? $fields['tags'] : array() ),
        );
        update_option( self::OPTION, $all, false );
        if ( '' !== $key ) {
            delete_transient( 'sfaf_mc_meta_' . $id );
        }
        return array( 'ok' => true, 'id' => $id, 'message' => $new ? 'Connection added.' : 'Connection saved.' );
    }

    /**
     * Remove a connection and its key. What its events and series stored is
     * left alone and simply no longer read; anything still waiting to be sent
     * to it fails with that reason when its turn comes.
     */
    public static function delete( $id ) {
        $all = get_option( self::OPTION, array() );
        $id  = sanitize_key( (string) $id );
        if ( ! is_array( $all ) || ! isset( $all[ $id ] ) ) {
            return false;
        }
        unset( $all[ $id ] );
        update_option( self::OPTION, $all, false );
        SFAF_Credentials::remove( self::key_name( $id ) );
        delete_transient( 'sfaf_mc_meta_' . $id );
        return true;
    }

    /* ---------------------------------------------------------------------
     * The key
     * ------------------------------------------------------------------- */

    public static function key_name( $id ) {
        return 'mailchimp_key_' . sanitize_key( (string) $id );
    }

    /** The data centre a key belongs to, from its suffix: "-us21" is us21. */
    public static function dc( $key ) {
        $key = trim( (string) $key );
        if ( ! preg_match( '/^[0-9a-f]{16,64}-([a-z]{2}[0-9]{1,3})$/i', $key, $m ) ) {
            return '';
        }
        return strtolower( $m[1] );
    }

    /** Two 32-byte keys from the site's own secret keys: one to encrypt, one to sign. */
    private static function secret( $purpose ) {
        $salt = function_exists( 'wp_salt' ) ? wp_salt( 'auth' ) : ( defined( 'AUTH_KEY' ) ? AUTH_KEY : '' );
        return hash_hmac( 'sha256', 'sfaf-mailchimp-' . $purpose, (string) $salt, true );
    }

    /**
     * Encrypt: HMAC-SHA256 in counter mode, then a SHA-256 HMAC over the
     * nonce and the ciphertext. Built from hash_hmac() alone so it runs on any
     * PHP with no extension to depend on.
     */
    public static function seal( $plain ) {
        $nonce  = random_bytes( 16 );
        $cipher = self::stream( (string) $plain, $nonce );
        $tag    = hash_hmac( 'sha256', $nonce . $cipher, self::secret( 'sign' ), true );
        return 'mc1:' . base64_encode( $nonce . $tag . $cipher );
    }

    /** Decrypt what seal() wrote, or '' when it was not written with this site's keys. */
    public static function open( $sealed ) {
        $sealed = (string) $sealed;
        if ( 0 !== strpos( $sealed, 'mc1:' ) ) {
            return '';
        }
        $raw = base64_decode( substr( $sealed, 4 ), true );
        if ( false === $raw || strlen( $raw ) < 48 ) {
            return '';
        }
        $nonce  = substr( $raw, 0, 16 );
        $tag    = substr( $raw, 16, 32 );
        $cipher = substr( $raw, 48 );
        if ( ! hash_equals( hash_hmac( 'sha256', $nonce . $cipher, self::secret( 'sign' ), true ), $tag ) ) {
            return '';
        }
        return self::stream( $cipher, $nonce );
    }

    private static function stream( $data, $nonce ) {
        $key = self::secret( 'encrypt' );
        $out = '';
        for ( $i = 0, $n = strlen( $data ); $i < $n; $i += 32 ) {
            $block = hash_hmac( 'sha256', $nonce . pack( 'N', $i / 32 ), $key, true );
            $out  .= substr( $data, $i, 32 ) ^ substr( $block, 0, min( 32, $n - $i ) );
        }
        return $out;
    }

    private static function set_key( $id, $key ) {
        SFAF_Credentials::set( self::key_name( $id ), self::seal( $key ) );
        return self::key( $id ) === $key;
    }

    /** A connection's key in the clear, for the request to Mailchimp only. */
    private static function key( $id ) {
        return self::open( SFAF_Credentials::raw( self::key_name( $id ) ) );
    }

    /** Whether a key is stored, and opens. */
    public static function has_key( $id ) {
        return '' !== self::key( $id );
    }

    /** What the screen shows of a saved key: its last four characters. */
    public static function key_hint( $id ) {
        $key = self::key( $id );
        if ( '' === $key ) {
            return '' !== SFAF_Credentials::raw( self::key_name( $id ) ) ? 'Saved, but it no longer opens. Paste it again.' : 'None saved.';
        }
        return 'Saved, ending ' . substr( $key, -4 ) . '.';
    }

    /* ---------------------------------------------------------------------
     * Talking to Mailchimp
     * ------------------------------------------------------------------- */

    /**
     * One request to the Marketing API.
     *
     * @param string     $key
     * @param string     $method GET, POST, PATCH, PUT.
     * @param string     $path   After /3.0, starting with /.
     * @param array|null $body
     * @return array( 'ok' => bool, 'code' => int, 'body' => array, 'error' => string )
     */
    public static function api( $key, $method, $path, $body = null ) {
        $dc = self::dc( $key );
        if ( '' === $dc ) {
            return array( 'ok' => false, 'code' => 0, 'body' => array(), 'error' => 'No usable API key.' );
        }
        $args = array(
            'method'  => $method,
            'timeout' => 15,
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode( 'sfaf:' . $key ),
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ),
        );
        if ( null !== $body ) {
            $args['body'] = wp_json_encode( $body );
        }
        $res = wp_remote_request( 'https://' . $dc . '.api.mailchimp.com/3.0' . $path, $args );
        if ( is_wp_error( $res ) ) {
            return array( 'ok' => false, 'code' => 0, 'body' => array(), 'error' => self::scrub( 'Could not reach Mailchimp: ' . $res->get_error_message(), $key ) );
        }
        $code = (int) wp_remote_retrieve_response_code( $res );
        $data = json_decode( (string) wp_remote_retrieve_body( $res ), true );
        $data = is_array( $data ) ? $data : array();
        $ok   = $code >= 200 && $code < 300;
        $err  = '';
        if ( ! $ok ) {
            $err = trim( ( isset( $data['title'] ) ? $data['title'] . ': ' : '' ) . ( isset( $data['detail'] ) ? $data['detail'] : 'HTTP ' . $code ) );
        }
        return array( 'ok' => $ok, 'code' => $code, 'body' => $data, 'error' => self::scrub( $err, $key ) );
    }

    /** A message with the key, and anything shaped like one, taken out. */
    public static function scrub( $text, $key = '' ) {
        $text = (string) $text;
        if ( '' !== (string) $key ) {
            $text = str_replace( (string) $key, '[key]', $text );
        }
        return preg_replace( '/[0-9a-f]{16,64}-[a-z]{2}[0-9]{1,3}/i', '[key]', $text );
    }

    /**
     * A connection's audiences and every tag name in them, read from Mailchimp
     * and kept for self::META_TTL.
     *
     * @param string      $id
     * @param bool        $refresh Read again now.
     * @param string|null $key     A key not saved yet (Test connection).
     * @return array( 'audiences' => array( array( id, name ) ), 'tags' => string[], 'at' => int, 'error' => string )
     */
    public static function meta( $id, $refresh = false, $key = null ) {
        $id    = sanitize_key( (string) $id );
        $empty = array( 'audiences' => array(), 'tags' => array(), 'at' => 0, 'error' => '' );
        if ( null === $key ) {
            if ( ! $refresh ) {
                $cached = get_transient( 'sfaf_mc_meta_' . $id );
                if ( is_array( $cached ) && isset( $cached['audiences'] ) ) {
                    return $cached + $empty;
                }
            }
            $key = self::key( $id );
        }
        if ( '' === (string) $key ) {
            return $empty;
        }
        $r = self::api( $key, 'GET', '/lists?count=100&fields=lists.id,lists.name' );
        if ( ! $r['ok'] ) {
            return array( 'error' => $r['error'] ) + $empty;
        }
        $out = $empty;
        foreach ( isset( $r['body']['lists'] ) ? (array) $r['body']['lists'] : array() as $l ) {
            if ( isset( $l['id'], $l['name'] ) ) {
                $out['audiences'][] = array( 'id' => (string) $l['id'], 'name' => (string) $l['name'] );
            }
        }
        $tags = array();
        foreach ( $out['audiences'] as $a ) {
            $t = self::api( $key, 'GET', '/lists/' . rawurlencode( $a['id'] ) . '/segments?type=static&count=1000&fields=segments.name' );
            foreach ( $t['ok'] && isset( $t['body']['segments'] ) ? (array) $t['body']['segments'] : array() as $s ) {
                if ( isset( $s['name'] ) ) {
                    $tags[] = (string) $s['name'];
                }
            }
        }
        $out['tags'] = self::clean_tags( $tags );
        natcasesort( $out['tags'] );
        $out['tags'] = array_values( $out['tags'] );
        $out['at']   = time();
        if ( '' !== $id && self::get( $id ) ) {
            set_transient( 'sfaf_mc_meta_' . $id, $out, self::META_TTL );
        }
        return $out;
    }

    /**
     * Test connection: the key in the box, or the saved one when the box is
     * blank. Answers with the audiences for the dropdown, never the key.
     */
    public static function ajax_test() {
        check_ajax_referer( 'uc_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'You do not have permission to do that.' ), 403 );
        }
        $id  = isset( $_POST['id'] ) ? sanitize_key( wp_unslash( $_POST['id'] ) ) : '';
        $key = isset( $_POST['api_key'] ) ? trim( (string) wp_unslash( $_POST['api_key'] ) ) : '';
        if ( '' === $key && '' !== $id ) {
            $key = self::key( $id );
        }
        if ( '' === self::dc( $key ) ) {
            wp_send_json_error( array( 'message' => '' === $key ? 'Paste the API key first.' : 'That is not a Mailchimp API key. It ends in a dash and the data centre, such as -us21.' ) );
        }
        $ping = self::api( $key, 'GET', '/ping' );
        if ( ! $ping['ok'] ) {
            wp_send_json_error( array( 'message' => 'Mailchimp said no. ' . $ping['error'] ) );
        }
        $meta = self::meta( $id, true, $key );
        if ( '' !== $meta['error'] ) {
            wp_send_json_error( array( 'message' => 'Connected, but the audiences could not be read. ' . $meta['error'] ) );
        }
        wp_send_json_success( array(
            'message'   => sprintf( 'Connected. %d %s, %d %s.', count( $meta['audiences'] ), 1 === count( $meta['audiences'] ) ? 'audience' : 'audiences', count( $meta['tags'] ), 1 === count( $meta['tags'] ) ? 'tag' : 'tags' ),
            'audiences' => $meta['audiences'],
        ) );
    }

    /* ---------------------------------------------------------------------
     * Which connections an event or a series has
     * ------------------------------------------------------------------- */

    /**
     * The connections these organizers have, in the order they were added.
     *
     * @param int[] $organizer_ids
     * @return string[] Connection ids.
     */
    public static function for_organizers( $organizer_ids ) {
        $organizer_ids = array_map( 'intval', (array) $organizer_ids );
        $out           = array();
        foreach ( self::connections() as $c ) {
            if ( array_intersect( $c['organizers'], $organizer_ids ) ) {
                $out[] = $c['id'];
            }
        }
        return $out;
    }

    /** The connections an event's organizers have. */
    public static function for_event( $event_id ) {
        $ids = wp_get_object_terms( (int) $event_id, SFAF_Organizers::TAXONOMY, array( 'fields' => 'ids' ) );
        return self::for_organizers( is_wp_error( $ids ) ? array() : (array) $ids );
    }

    /** What an event stored for one connection, with the defaults filled in. */
    public static function event_choice( $event_id, $conn_id ) {
        $all = get_post_meta( (int) $event_id, self::META_EVENT, true );
        $c   = is_array( $all ) && isset( $all[ $conn_id ] ) && is_array( $all[ $conn_id ] ) ? $all[ $conn_id ] : array();
        return array(
            'show'     => ! isset( $c['show'] ) || '1' === (string) $c['show'],
            'audience' => isset( $c['audience'] ) ? (string) $c['audience'] : '',
            'tags'     => self::clean_tags( isset( $c['tags'] ) ? $c['tags'] : array() ),
        );
    }

    /** What a series stored for one connection. */
    public static function series_choice( $term_id, $conn_id ) {
        $all = $term_id ? get_term_meta( (int) $term_id, self::META_SERIES, true ) : array();
        $c   = is_array( $all ) && isset( $all[ $conn_id ] ) && is_array( $all[ $conn_id ] ) ? $all[ $conn_id ] : array();
        return array(
            'audience' => isset( $c['audience'] ) ? (string) $c['audience'] : '',
            'tags'     => self::clean_tags( isset( $c['tags'] ) ? $c['tags'] : array() ),
        );
    }

    /** An event's series id, or 0. */
    private static function series_of( $event_id ) {
        $ids = wp_get_object_terms( (int) $event_id, 'uc_series', array( 'fields' => 'ids' ) );
        return ( is_wp_error( $ids ) || empty( $ids ) ) ? 0 : (int) reset( $ids );
    }

    /**
     * Where a sign-up from this event goes: the event's audience, else the
     * series', else the connection's; the connection's fixed tags, then the
     * series', then the event's.
     *
     * @return array( 'audience' => string, 'tags' => string[] )
     */
    public static function destination( $event_id, $conn_id ) {
        $c = self::get( $conn_id );
        if ( ! $c ) {
            return array( 'audience' => '', 'tags' => array() );
        }
        $e        = self::event_choice( $event_id, $conn_id );
        $s        = self::series_choice( self::series_of( $event_id ), $conn_id );
        $audience = '' !== $e['audience'] ? $e['audience'] : ( '' !== $s['audience'] ? $s['audience'] : $c['audience'] );
        return array( 'audience' => $audience, 'tags' => self::clean_tags( array_merge( $c['tags'], $s['tags'], $e['tags'] ) ) );
    }

    /**
     * The team ticks the RSVP form offers for an event: one per connection its
     * organizers have whose sign-up is on, for an event that takes RSVPs here.
     *
     * @return array[] Each array( 'id' => string, 'name' => string ).
     */
    public static function form_lists( $event_id ) {
        $event_id = (int) $event_id;
        if ( ! $event_id || ! sfaf_event_takes_rsvps( $event_id ) ) {
            return array();
        }
        $out = array();
        foreach ( self::for_event( $event_id ) as $id ) {
            $c = self::get( $id );
            if ( $c && self::event_choice( $event_id, $id )['show'] ) {
                $out[] = array( 'id' => $id, 'name' => $c['name'] );
            }
        }
        return $out;
    }

    /**
     * The ids posted from the form that this event actually offered. Anything
     * else is dropped, so a constructed post cannot sign anybody up for a list
     * the event does not have.
     *
     * @param int          $event_id
     * @param array|string $posted
     * @return string[]
     */
    public static function allowed_lists( $event_id, $posted ) {
        if ( is_string( $posted ) ) {
            $posted = explode( ',', $posted );
        }
        $posted = array_map( 'sanitize_key', array_map( 'strval', (array) $posted ) );
        $out    = array();
        foreach ( self::form_lists( $event_id ) as $l ) {
            if ( in_array( $l['id'], $posted, true ) ) {
                $out[] = $l['id'];
            }
        }
        return $out;
    }

    /* ---------------------------------------------------------------------
     * Saving the editors' choices
     * ------------------------------------------------------------------- */

    /**
     * What an editor's Mailchimp card posted, for the connections it showed.
     *
     * The $offered rule (CLAUDE.md 7): only the connections named in
     * mc_present[] are written; anything stored for another is kept.
     *
     * @param array $post   $_POST, unslashed.
     * @param array $stored What is stored now.
     * @param bool  $event  True for an event, which also has the sign-up tick.
     * @return array The value to store.
     */
    public static function from_post( $post, $stored, $event ) {
        $stored  = is_array( $stored ) ? $stored : array();
        $offered = isset( $post['mc_present'] ) ? array_map( 'sanitize_key', (array) $post['mc_present'] ) : array();
        $given   = isset( $post['mc'] ) && is_array( $post['mc'] ) ? $post['mc'] : array();
        foreach ( $offered as $id ) {
            if ( ! self::get( $id ) ) {
                continue;
            }
            $g   = isset( $given[ $id ] ) && is_array( $given[ $id ] ) ? $given[ $id ] : array();
            $row = array(
                'audience' => isset( $g['audience'] ) ? sanitize_text_field( (string) $g['audience'] ) : '',
                'tags'     => self::clean_tags( array_merge(
                    isset( $g['tags'] ) ? (array) $g['tags'] : array(),
                    isset( $g['new_tags'] ) ? explode( ',', (string) $g['new_tags'] ) : array()
                ) ),
            );
            if ( $event ) {
                $row = array( 'show' => empty( $g['show'] ) ? '0' : '1' ) + $row;
            }
            $stored[ $id ] = $row;
        }
        return $stored;
    }

    /* ---------------------------------------------------------------------
     * The queue
     * ------------------------------------------------------------------- */

    /**
     * Queue one sign-up. The audience and tags are decided now, from what the
     * event, its series and the connection say at the moment of consent.
     *
     * @param int    $optin_id The uc_optins row that is the consent.
     * @param string $conn_id
     * @param array  $person   event_id, rsvp_id, email, first_name, last_name.
     * @return int The queue row, or 0.
     */
    public static function enqueue( $optin_id, $conn_id, $person ) {
        global $wpdb;
        $email = sanitize_email( isset( $person['email'] ) ? (string) $person['email'] : '' );
        if ( '' === $email || ! self::get( $conn_id ) ) {
            return 0;
        }
        $to = self::destination( (int) $person['event_id'], $conn_id );
        $ok = $wpdb->insert( self::table(), array(
            'optin_id'   => (int) $optin_id,
            'rsvp_id'    => isset( $person['rsvp_id'] ) ? (int) $person['rsvp_id'] : 0,
            'event_id'   => (int) $person['event_id'],
            'connection' => sanitize_key( $conn_id ),
            'audience'   => $to['audience'],
            'email'      => $email,
            'first_name' => isset( $person['first_name'] ) ? (string) $person['first_name'] : '',
            'last_name'  => isset( $person['last_name'] ) ? (string) $person['last_name'] : '',
            'tags'       => wp_json_encode( $to['tags'] ),
            'status'     => 'queued',
            'attempts'   => 0,
            'next_at'    => gmdate( 'Y-m-d H:i:s' ),
            'created_at' => gmdate( 'Y-m-d H:i:s' ),
            'log'        => '[]',
        ) );
        if ( ! $ok ) {
            return 0;
        }
        self::schedule( time() + 5 );
        return (int) $wpdb->insert_id;
    }

    /** One single event at $when, unless one is already due sooner. */
    private static function schedule( $when ) {
        $next = wp_next_scheduled( self::CRON_HOOK );
        if ( $next && $next <= $when ) {
            return;
        }
        if ( $next ) {
            wp_clear_scheduled_hook( self::CRON_HOOK );
        }
        wp_schedule_single_event( (int) $when, self::CRON_HOOK );
    }

    /**
     * Send what is due, 25 at a time, and schedule the next run for what is
     * left or for the next retry.
     */
    public static function process_queue() {
        global $wpdb;
        $table = self::table();
        $now   = gmdate( 'Y-m-d H:i:s' );
        $rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table WHERE status = 'queued' AND next_at <= %s ORDER BY id ASC LIMIT 25", $now ) );
        foreach ( (array) $rows as $row ) {
            self::send( $row );
        }
        $next = $wpdb->get_var( "SELECT MIN(next_at) FROM $table WHERE status = 'queued'" );
        if ( $next ) {
            self::schedule( max( time() + 5, (int) strtotime( $next . ' UTC' ) ) );
        }
    }

    /**
     * Send one row, and write what happened to it.
     *
     * @param object $row
     * @return string The outcome: added, tagged, restored, skipped, retry, failed.
     */
    public static function send( $row ) {
        $c   = self::get( $row->connection );
        $key = $c ? self::key( $c['id'] ) : '';
        if ( ! $c ) {
            return self::finish( $row, 'failed', 'The connection was removed.' );
        }
        if ( '' === $key ) {
            return self::retry( $row, 'No API key that opens is saved for ' . $c['name'] . '.' );
        }
        if ( '' === (string) $row->audience ) {
            return self::retry( $row, 'No audience is chosen for ' . $c['name'] . '.' );
        }
        $list  = '/lists/' . rawurlencode( (string) $row->audience ) . '/members/';
        $hash  = md5( strtolower( trim( (string) $row->email ) ) );
        $tags  = json_decode( (string) $row->tags, true );
        $tags  = is_array( $tags ) ? $tags : array();

        $found = self::api( $key, 'GET', $list . $hash . '?fields=status' );
        if ( 404 === $found['code'] ) {
            $merge = array( 'FNAME' => (string) $row->first_name );
            if ( '' !== (string) $row->last_name ) {
                $merge['LNAME'] = (string) $row->last_name;
            }
            $body = array( 'email_address' => (string) $row->email, 'status' => 'subscribed', 'merge_fields' => $merge );
            if ( $tags ) {
                $body['tags'] = array_values( $tags );
            }
            $add = self::api( $key, 'POST', '/lists/' . rawurlencode( (string) $row->audience ) . '/members', $body );
            if ( $add['ok'] ) {
                return self::finish( $row, 'added', 'Added to the audience.' );
            }
            // Refusals that trying again will not change: a fake address, or
            // one the account erased at the person's request.
            if ( 400 === $add['code'] ) {
                return self::finish( $row, 'skipped', 'Mailchimp would not add this address. ' . $add['error'] );
            }
            return self::retry( $row, $add['error'] );
        }
        if ( ! $found['ok'] ) {
            return self::retry( $row, $found['error'] );
        }

        $status = isset( $found['body']['status'] ) ? (string) $found['body']['status'] : '';
        if ( in_array( $status, array( 'unsubscribed', 'cleaned' ), true ) ) {
            return self::finish( $row, 'skipped', 'Not sent: ' . $status . ' in this audience.' );
        }
        $restored = false;
        if ( in_array( $status, array( 'archived', 'transactional' ), true ) ) {
            $back = self::api( $key, 'PATCH', $list . $hash, array( 'status' => 'subscribed' ) );
            if ( ! $back['ok'] ) {
                return 400 === $back['code'] ? self::finish( $row, 'skipped', 'Mailchimp would not add this address again. ' . $back['error'] ) : self::retry( $row, $back['error'] );
            }
            $restored = true;
        }
        if ( $tags ) {
            $tagged = self::api( $key, 'POST', $list . $hash . '/tags', array( 'tags' => array_map( function ( $t ) { return array( 'name' => $t, 'status' => 'active' ); }, array_values( $tags ) ) ) );
            if ( ! $tagged['ok'] ) {
                return self::retry( $row, $tagged['error'] );
            }
        }
        if ( $restored ) {
            return self::finish( $row, 'restored', 'Was ' . $status . '; added back to the audience' . ( $tags ? ' with the tags.' : '.' ) );
        }
        return self::finish( $row, 'tagged', $tags ? 'Already in the audience; the tags were added.' : 'Already in the audience; nothing to add.' );
    }

    /** Try again later, or fail once the retries are used up. */
    private static function retry( $row, $error ) {
        $attempt = (int) $row->attempts + 1;
        $waits   = self::RETRY_AFTER;
        if ( $attempt > count( $waits ) ) {
            return self::finish( $row, 'failed', $error );
        }
        self::write( $row, array(
            'attempts'   => $attempt,
            'next_at'    => gmdate( 'Y-m-d H:i:s', time() + $waits[ $attempt - 1 ] ),
            'last_error' => self::scrub( substr( (string) $error, 0, 250 ) ),
        ), 'retry', $error );
        return 'retry';
    }

    /** A row is done: sent, skipped, or failed for good. */
    private static function finish( $row, $outcome, $note ) {
        self::write( $row, array(
            'status'     => 'failed' === $outcome ? 'failed' : 'done',
            'attempts'   => (int) $row->attempts + 1,
            'outcome'    => $outcome,
            'last_error' => 'failed' === $outcome ? self::scrub( substr( (string) $note, 0, 250 ) ) : '',
            'done_at'    => gmdate( 'Y-m-d H:i:s' ),
        ), $outcome, $note );
        return $outcome;
    }

    /** Update a row and add the attempt to its log. */
    private static function write( $row, $fields, $outcome, $note ) {
        global $wpdb;
        $log   = json_decode( (string) $row->log, true );
        $log   = is_array( $log ) ? $log : array();
        $log[] = array( 'at' => gmdate( 'Y-m-d H:i:s' ), 'outcome' => $outcome, 'note' => self::scrub( substr( (string) $note, 0, 250 ) ) );
        $fields['log'] = wp_json_encode( array_slice( $log, -10 ) );
        $wpdb->update( self::table(), $fields, array( 'id' => (int) $row->id ) );
    }

    /**
     * What the connection screen lists: failures, and what is waiting after a
     * failed attempt, newest first.
     *
     * @return object[]
     */
    public static function problems( $limit = 50 ) {
        global $wpdb;
        $table = self::table();
        return (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM $table WHERE status = 'failed' OR ( status = 'queued' AND attempts > 0 ) ORDER BY id DESC LIMIT %d",
            (int) $limit
        ) );
    }

    /** How many were sent, are waiting and have failed, per connection. */
    public static function counts() {
        global $wpdb;
        $table = self::table();
        $out   = array();
        foreach ( (array) $wpdb->get_results( "SELECT connection, status, COUNT(*) AS n FROM $table GROUP BY connection, status" ) as $r ) {
            $out[ (string) $r->connection ][ (string) $r->status ] = (int) $r->n;
        }
        return $out;
    }

    /** Put a failed row back in the queue, now. */
    public static function requeue( $row_id ) {
        global $wpdb;
        $done = $wpdb->update( self::table(), array( 'status' => 'queued', 'attempts' => 0, 'next_at' => gmdate( 'Y-m-d H:i:s' ), 'last_error' => '' ), array( 'id' => (int) $row_id, 'status' => 'failed' ) );
        if ( $done ) {
            self::schedule( time() + 5 );
        }
        return (bool) $done;
    }

    /* ---------------------------------------------------------------------
     * The WP admin handlers
     * ------------------------------------------------------------------- */

    private static function back( $args ) {
        wp_safe_redirect( add_query_arg( $args, admin_url( 'edit.php?post_type=uc_event&page=uc-settings' ) ) . '#uc-mailchimp' );
        exit;
    }

    private static function guard( $action ) {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'You do not have permission to do that.' );
        }
        check_admin_referer( $action );
    }

    public static function handle_save() {
        self::guard( 'sfaf_mailchimp_save' );
        $p = wp_unslash( $_POST );
        $r = self::save(
            isset( $p['id'] ) ? (string) $p['id'] : '',
            array(
                'name'       => isset( $p['name'] ) ? $p['name'] : '',
                'audience'   => isset( $p['audience'] ) ? $p['audience'] : '',
                'organizers' => isset( $p['organizers'] ) ? (array) $p['organizers'] : array(),
                'tags'       => isset( $p['tags'] ) ? (string) $p['tags'] : '',
            ),
            isset( $p['api_key'] ) ? (string) $p['api_key'] : ''
        );
        set_transient( 'sfaf_mc_notice_' . get_current_user_id(), array( 'ok' => $r['ok'], 'message' => $r['message'] ), 60 );
        self::back( $r['ok'] ? array( 'mc_saved' => 1 ) : array( 'mc_edit' => isset( $p['id'] ) && '' !== $p['id'] ? sanitize_key( $p['id'] ) : 'new' ) );
    }

    public static function handle_delete() {
        self::guard( 'sfaf_mailchimp_delete' );
        $c = self::get( isset( $_POST['id'] ) ? wp_unslash( $_POST['id'] ) : '' );
        if ( $c ) {
            self::delete( $c['id'] );
        }
        set_transient( 'sfaf_mc_notice_' . get_current_user_id(), array( 'ok' => (bool) $c, 'message' => $c ? $c['name'] . ' was removed.' : 'That connection was already gone.' ), 60 );
        self::back( array( 'mc_saved' => 1 ) );
    }

    public static function handle_retry() {
        self::guard( 'sfaf_mailchimp_retry' );
        $ok = self::requeue( isset( $_POST['row'] ) ? (int) $_POST['row'] : 0 );
        set_transient( 'sfaf_mc_notice_' . get_current_user_id(), array( 'ok' => $ok, 'message' => $ok ? 'It will be sent again in a few seconds.' : 'That sign-up was not waiting to be retried.' ), 60 );
        self::back( array( 'mc_saved' => 1 ) );
    }

    /* ---------------------------------------------------------------------
     * The panel on WP admin Settings
     * ------------------------------------------------------------------- */

    /**
     * MAILCHIMP, BELOW THE SETTINGS FORM AND OUTSIDE IT: that form rebuilds
     * uc_settings on every save, and these are separate records with forms of
     * their own, posted to admin-post.php. Administrators only, as the page.
     *
     * The connections, each with what it applies to and what has been sent;
     * the form to add or change one, with Test connection filling the audience
     * dropdown; and the sign-ups that failed, with Retry.
     */
    public static function render_panel() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $conns  = self::connections();
        $counts = self::counts();
        $edit   = isset( $_GET['mc_edit'] ) ? sanitize_key( wp_unslash( $_GET['mc_edit'] ) ) : '';
        $notice = get_transient( 'sfaf_mc_notice_' . get_current_user_id() );
        if ( $notice ) {
            delete_transient( 'sfaf_mc_notice_' . get_current_user_id() );
        }
        $orgs   = SFAF_Organizers::all();
        $orgs   = is_wp_error( $orgs ) ? array() : (array) $orgs;
        $org_name = function ( $id ) use ( $orgs ) {
            foreach ( $orgs as $o ) { if ( (int) $o->term_id === (int) $id ) { return $o->name; } }
            return '#' . (int) $id;
        };
        $problems = self::problems();
        ?>
        <div class="uc-integration-panel uc-mailchimp-panel<?php echo ( $edit || $notice || $problems ) ? ' uc-panel-open' : ''; ?>" id="uc-mailchimp" data-uc-mailchimp-panel>
            <div class="uc-panel-header" onclick="this.parentElement.classList.toggle('uc-panel-open')">
                <span class="uc-panel-icon"><?php echo sfaf_icon( 'mail', array( 'size' => '20px' ) ); ?></span>
                <div class="uc-panel-info">
                    <h2>Mailchimp</h2>
                    <p>A team's own mailing list, from the RSVP form</p>
                </div>
                <span class="uc-panel-status <?php echo $conns ? 'uc-status-connected' : 'uc-status-pending'; ?>"><?php echo $conns ? count( $conns ) . ' ' . ( 1 === count( $conns ) ? 'connection' : 'connections' ) : 'Not configured'; ?></span>
                <span class="uc-panel-toggle">&#9660;</span>
            </div>
            <div class="uc-panel-body">
                <?php if ( $notice ) : ?>
                    <div class="notice <?php echo ! empty( $notice['ok'] ) ? 'notice-success' : 'notice-error'; ?> inline"><p><?php echo esc_html( $notice['message'] ); ?></p></div>
                <?php endif; ?>

                <?php if ( $conns ) : ?>
                    <table class="widefat striped uc-mc-table">
                        <thead><tr><th scope="col">Team</th><th scope="col">Organizers</th><th scope="col">Audience</th><th scope="col">Tags on every sign-up</th><th scope="col">API key</th><th scope="col">Sent</th><th scope="col"><span class="screen-reader-text">Actions</span></th></tr></thead>
                        <tbody>
                        <?php foreach ( $conns as $c ) :
                            $n = isset( $counts[ $c['id'] ] ) ? $counts[ $c['id'] ] : array();
                            ?>
                            <tr data-uc-mc-row="<?php echo esc_attr( $c['id'] ); ?>">
                                <td><strong><?php echo esc_html( $c['name'] ); ?></strong></td>
                                <td><?php echo $c['organizers'] ? esc_html( implode( ', ', array_map( $org_name, $c['organizers'] ) ) ) : '<em>None: nothing shows anywhere yet.</em>'; ?></td>
                                <td><?php echo esc_html( '' !== $c['audience_name'] ? $c['audience_name'] : ( '' !== $c['audience'] ? $c['audience'] : 'None chosen' ) ); ?></td>
                                <td><?php echo $c['tags'] ? esc_html( implode( ', ', $c['tags'] ) ) : '&ndash;'; ?></td>
                                <td><?php echo esc_html( self::key_hint( $c['id'] ) ); ?></td>
                                <td><?php echo esc_html( sprintf( '%d sent, %d waiting, %d failed', (int) ( $n['done'] ?? 0 ), (int) ( $n['queued'] ?? 0 ), (int) ( $n['failed'] ?? 0 ) ) ); ?></td>
                                <td class="uc-mc-actions">
                                    <a class="button" href="<?php echo esc_url( add_query_arg( 'mc_edit', $c['id'] ) . '#uc-mailchimp' ); ?>">Edit</a>
                                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="uc-mc-inline"
                                          onsubmit="return confirm(<?php echo esc_attr( wp_json_encode( 'Remove ' . $c['name'] . '? Its key is deleted, and its sign-up box leaves every RSVP form. Who already signed up stays in Mailchimp.' ) ); ?>);">
                                        <?php wp_nonce_field( 'sfaf_mailchimp_delete' ); ?>
                                        <input type="hidden" name="action" value="sfaf_mailchimp_delete" />
                                        <input type="hidden" name="id" value="<?php echo esc_attr( $c['id'] ); ?>" />
                                        <button type="submit" class="button-link button-link-delete">Remove</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>

                <?php if ( '' === $edit ) : ?>
                    <p><a class="button" href="<?php echo esc_url( add_query_arg( 'mc_edit', 'new' ) . '#uc-mailchimp' ); ?>">Add a connection</a></p>
                <?php else :
                    $c     = ( 'new' !== $edit ) ? self::get( $edit ) : null;
                    $c     = $c ? $c : array( 'id' => '', 'name' => '', 'audience' => '', 'audience_name' => '', 'organizers' => array(), 'tags' => array() );
                    $meta  = '' !== $c['id'] ? self::meta( $c['id'] ) : array( 'audiences' => array() );
                    $taken = array();
                    foreach ( $conns as $other ) {
                        if ( $other['id'] !== $c['id'] ) {
                            foreach ( $other['organizers'] as $o ) { $taken[ $o ] = $other['name']; }
                        }
                    }
                    ?>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="uc-mc-form" data-uc-mc-form>
                        <h3><?php echo '' !== $c['id'] ? 'Edit ' . esc_html( $c['name'] ) : 'Add a connection'; ?></h3>
                        <?php wp_nonce_field( 'sfaf_mailchimp_save' ); ?>
                        <input type="hidden" name="action" value="sfaf_mailchimp_save" />
                        <input type="hidden" name="id" value="<?php echo esc_attr( $c['id'] ); ?>" />
                        <div class="uc-field-row">
                            <label for="uc-mc-name">Name</label>
                            <input type="text" id="uc-mc-name" name="name" value="<?php echo esc_attr( $c['name'] ); ?>" class="uc-input" required />
                        </div>
                        <p class="description">The team, as the RSVP form names it: "Sign me up for <em>Aging Services</em> email updates".</p>
                        <div class="uc-field-row">
                            <label for="uc-mc-key">API key</label>
                            <input type="password" id="uc-mc-key" name="api_key" value="" autocomplete="new-password" class="uc-input"
                                   placeholder="<?php echo esc_attr( '' !== $c['id'] ? self::key_hint( $c['id'] ) . ' Leave blank to keep it.' : 'Paste the API key' ); ?>" />
                        </div>
                        <div class="uc-field-row">
                            <label>Connection</label>
                            <div class="uc-conn-controls">
                                <button type="button" class="button" data-uc-mc-test>Test connection</button>
                            </div>
                        </div>
                        <p class="description" data-uc-mc-test-msg hidden></p>
                        <div class="uc-field-row">
                            <label for="uc-mc-audience">Default audience</label>
                            <select id="uc-mc-audience" name="audience" data-uc-mc-audience>
                                <?php if ( ! $meta['audiences'] && '' === $c['audience'] ) : ?>
                                    <option value="">Test the connection to load the audiences</option>
                                <?php endif; ?>
                                <?php if ( ! $meta['audiences'] && '' !== $c['audience'] ) : ?>
                                    <option value="<?php echo esc_attr( $c['audience'] ); ?>" selected><?php echo esc_html( '' !== $c['audience_name'] ? $c['audience_name'] : $c['audience'] ); ?></option>
                                <?php endif; ?>
                                <?php foreach ( $meta['audiences'] as $a ) : ?>
                                    <option value="<?php echo esc_attr( $a['id'] ); ?>"<?php selected( $c['audience'], $a['id'] ); ?>><?php echo esc_html( $a['name'] ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="uc-field-row uc-field-row-top">
                            <label>Organizers</label>
                            <div class="uc-mc-orgs">
                                <?php if ( ! $orgs ) : ?>
                                    <p class="description">There are no organizers yet. Add them in caladmin, under Organizers.</p>
                                <?php endif; ?>
                                <?php foreach ( $orgs as $o ) :
                                    $other = isset( $taken[ (int) $o->term_id ] ) ? $taken[ (int) $o->term_id ] : '';
                                    ?>
                                    <label class="uc-mc-org">
                                        <input type="checkbox" name="organizers[]" value="<?php echo (int) $o->term_id; ?>"<?php checked( in_array( (int) $o->term_id, $c['organizers'], true ) ); ?><?php disabled( '' !== $other ); ?> />
                                        <?php echo esc_html( $o->name ); ?><?php echo '' !== $other ? ' <span class="description">(' . esc_html( $other ) . ')</span>' : ''; ?>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <p class="description">The sign-up box appears on the RSVP form of events with one of these organizers, and the Newsletter sign-up card on their editors and series.</p>
                        <div class="uc-field-row">
                            <label for="uc-mc-tags">Tags on every sign-up</label>
                            <input type="text" id="uc-mc-tags" name="tags" value="<?php echo esc_attr( implode( ', ', $c['tags'] ) ); ?>" class="uc-input" />
                        </div>
                        <p class="description">Optional. Separate tags with commas.</p>
                        <p class="uc-mc-buttons">
                            <button type="submit" class="button button-primary">Save connection</button>
                            <a class="button" href="<?php echo esc_url( remove_query_arg( 'mc_edit' ) . '#uc-mailchimp' ); ?>">Cancel</a>
                        </p>
                    </form>
                <?php endif; ?>

                <h3>Sign-ups that did not go through</h3>
                <?php if ( ! $problems ) : ?>
                    <p class="description" data-uc-mc-no-problems>None.</p>
                <?php else : ?>
                    <p class="description">Each is tried five times over about 14 hours before it is marked failed. Retry sends it again now.</p>
                    <table class="widefat striped uc-mc-problems">
                        <thead><tr><th scope="col">Signed up</th><th scope="col">Team</th><th scope="col">Email</th><th scope="col">Event</th><th scope="col">What happened</th><th scope="col"><span class="screen-reader-text">Retry</span></th></tr></thead>
                        <tbody>
                        <?php foreach ( $problems as $r ) :
                            $team = self::get( $r->connection );
                            ?>
                            <tr data-uc-mc-problem="<?php echo (int) $r->id; ?>">
                                <td><?php echo esc_html( sfaf_ap_datetime( (int) strtotime( $r->created_at . ' UTC' ) ) ); ?></td>
                                <td><?php echo esc_html( $team ? $team['name'] : $r->connection ); ?></td>
                                <td><?php echo esc_html( $r->email ); ?></td>
                                <td><?php echo esc_html( get_the_title( (int) $r->event_id ) ); ?></td>
                                <td><?php echo esc_html( ( 'failed' === $r->status ? 'Failed: ' : 'Will try again: ' ) . $r->last_error ); ?></td>
                                <td>
                                    <?php if ( 'failed' === $r->status ) : ?>
                                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="uc-mc-inline">
                                            <?php wp_nonce_field( 'sfaf_mailchimp_retry' ); ?>
                                            <input type="hidden" name="action" value="sfaf_mailchimp_retry" />
                                            <input type="hidden" name="row" value="<?php echo (int) $r->id; ?>" />
                                            <button type="submit" class="button">Retry</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }
}
