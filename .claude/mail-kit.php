<?php
/**
 * A MINIATURE WORDPRESS FOR THE MAIL THAT GOES TO STAFF AND REGISTRANTS (3.102.0).
 *
 *     require __DIR__ . '/mail-kit.php';
 *
 * Loaded by digest-test.php and emailless-test.php. Not a check itself.
 *
 * WHY NOT wp-kit. wp-kit answers every query with nothing and every user as
 * user 1, which is right for rendering a form and useless for "which events may
 * this person see" or "did the ledger refuse the second send". This kit has
 * posts, meta, terms, users with roles, options, a WP_Query that filters on
 * status, date and meta, and a $wpdb that runs the handful of SQL shapes the
 * mail code writes, with the reminder ledger's two UNIQUE keys enforced. That
 * enforcement is the send-once guarantee, so a model without it would prove
 * nothing about sending twice.
 *
 * WHAT IS REAL: SFAF_Email, SFAF_Online, SFAF_Cancellation, SFAF_Reminders,
 * SFAF_Notifications, SFAF_Digest, SFAF_Teams, SFAF_Venues, SFAF_Series,
 * SFAF_Sources, SFAF_RSVP, and, lifted out of their files, the event gate
 * (get_role, user_can_view_all, user_can_edit_event), the AP formatters, the
 * location reader and the count and capacity helpers. wp_mail() records what it
 * is handed in $GLOBALS['mk_mail'].
 */

error_reporting( E_ALL & ~E_DEPRECATED );
$mk_root = dirname( __DIR__ );
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
define( 'SFAF_PLUGIN_URL', 'https://resources.example.org/wp-content/plugins/sfaf-calendar/' );
define( 'SFAF_PLUGIN_DIR', $mk_root . '/' );
define( 'OBJECT', 'OBJECT' ); define( 'OBJECT_K', 'OBJECT_K' ); define( 'ARRAY_A', 'ARRAY_A' );
define( 'DAY_IN_SECONDS', 86400 ); define( 'HOUR_IN_SECONDS', 3600 ); define( 'MINUTE_IN_SECONDS', 60 );
date_default_timezone_set( 'America/Los_Angeles' );

function mk_reset() {
    $GLOBALS['mk_posts'] = array(); $GLOBALS['mk_meta'] = array(); $GLOBALS['mk_next'] = 100;
    $GLOBALS['mk_terms'] = array(); $GLOBALS['mk_obj_terms'] = array(); $GLOBALS['mk_next_term'] = 900; $GLOBALS['mk_tmeta'] = array();
    $GLOBALS['mk_users'] = array(); $GLOBALS['mk_umeta'] = array(); $GLOBALS['mk_opts'] = array();
    $GLOBALS['mk_mail'] = array(); $GLOBALS['mk_db'] = array( 'wp_uc_rsvps' => array(), 'wp_uc_reminder_log' => array() );
    $GLOBALS['mk_db_next'] = 1;
    // The count caches are request-local statics in the plugin; a new world is a new request.
    if ( function_exists( 'sfaf_rsvp_count_store' ) ) { $s =& sfaf_rsvp_count_store(); $s = array(); $t =& sfaf_rsvp_format_count_store(); $t = array(); }
}

/* ---- Errors, hooks, escaping. ---- */
class WP_Error { private $c; private $m; function __construct( $c = '', $m = '' ) { $this->c = $c; $this->m = $m; } function get_error_message() { return $this->m; } function get_error_code() { return $this->c; } }
class WP_Term { public $term_id; public $name; public $slug; public $taxonomy; public $description = ''; public $count = 0; public $parent = 0; }
function is_wp_error( $t ) { return $t instanceof WP_Error; }
$GLOBALS['mk_hooks'] = array();
function add_action( $h, $cb, $p = 10, $n = 1 ) { $GLOBALS['mk_hooks'][ $h ][] = array( $cb, $n ); return true; }
function add_filter( $h, $cb, $p = 10, $n = 1 ) { return true; }
function remove_action( $h, $cb, $p = 10 ) { return true; }
function do_action( $h, ...$args ) { foreach ( isset( $GLOBALS['mk_hooks'][ $h ] ) ? $GLOBALS['mk_hooks'][ $h ] : array() as $x ) { call_user_func_array( $x[0], array_slice( $args, 0, $x[1] ) ); } }
function apply_filters( $h, $v ) { return $v; }
function esc_html( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $u ) { return htmlspecialchars( (string) $u, ENT_QUOTES, 'UTF-8' ); }
function esc_url_raw( $u ) { return (string) $u; }
function wp_kses_post( $t ) { return (string) $t; }
function wp_strip_all_tags( $t, $b = false ) { return trim( strip_tags( (string) $t ) ); }
function sanitize_text_field( $t ) { return trim( preg_replace( '/\s+/', ' ', strip_tags( (string) $t ) ) ); }
function sanitize_key( $k ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) ); }
function sanitize_email( $e ) { return trim( (string) $e ); }
function sanitize_title( $s ) { return trim( strtolower( preg_replace( '/[^a-z0-9]+/i', '-', (string) $s ) ), '-' ); }
function is_email( $e ) { return (bool) filter_var( (string) $e, FILTER_VALIDATE_EMAIL ); }
function wp_unslash( $v ) { return $v; }
function wp_json_encode( $d, $o = 0 ) { return json_encode( $d, $o ); }
function wp_list_pluck( $l, $f ) { $o = array(); foreach ( (array) $l as $k => $i ) { $o[ $k ] = is_object( $i ) ? $i->$f : $i[ $f ]; } return $o; }
function absint( $n ) { return abs( (int) $n ); }
function __( $t, $d = '' ) { return $t; }
function _n( $s, $p, $n, $d = '' ) { return 1 === (int) $n ? $s : $p; }
function wp_rand( $a = 0, $b = 0 ) { return mt_rand( $a, $b ?: mt_getrandmax() ); }
function wp_generate_password( $l = 12, $s = true, $x = false ) { return bin2hex( random_bytes( 8 ) ); }
function home_url( $p = '', $s = null ) { return 'https://resources.example.org' . $p; }
function add_query_arg( $k, $v = '', $url = '' ) {
    if ( is_array( $k ) ) { $url = $v; $q = http_build_query( $k ); }
    else { $q = rawurlencode( $k ) . '=' . rawurlencode( (string) $v ); }
    return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . $q;
}
function wp_timezone() { return new DateTimeZone( 'America/Los_Angeles' ); }
function wp_timezone_string() { return 'America/Los_Angeles'; }
function current_time( $t, $g = 0 ) { return 'mysql' === $t ? date( 'Y-m-d H:i:s' ) : ( 'timestamp' === $t ? time() : date( $t ) ); }
function date_i18n( $f, $ts = false, $g = false ) { return date( $f, false === $ts ? time() : $ts ); }
function wp_date( $f, $ts = null, $tz = null ) { return date( $f, null === $ts ? time() : $ts ); }
function get_option( $n, $d = false ) { return array_key_exists( $n, $GLOBALS['mk_opts'] ) ? $GLOBALS['mk_opts'][ $n ] : $d; }
function update_option( $n, $v, $a = null ) { $GLOBALS['mk_opts'][ $n ] = $v; return true; }
function add_option( $n, $v = '', $d = '', $a = null ) { if ( isset( $GLOBALS['mk_opts'][ $n ] ) ) { return false; } $GLOBALS['mk_opts'][ $n ] = $v; return true; }
function delete_option( $n ) { unset( $GLOBALS['mk_opts'][ $n ] ); return true; }
function get_transient( $k ) { return false; } function set_transient( $k, $v, $e = 0 ) { return true; } function delete_transient( $k ) { return true; }

/* ---- Mail: recorded, never sent. ---- */
/* The text part rides phpmailer_init, as with core's mailer. */
class MK_PHPMailer { public $AltBody = ''; }
function wp_mail( $to, $subject, $body, $headers = '', $att = array() ) {
    $pm = new MK_PHPMailer();
    do_action( 'phpmailer_init', $pm );
    $GLOBALS['mk_mail'][] = array( 'to' => $to, 'subject' => $subject, 'html' => $body, 'headers' => $headers, 'text' => $pm->AltBody );
    return true;
}
function mk_mail_to( $addr ) { return array_values( array_filter( $GLOBALS['mk_mail'], function ( $m ) use ( $addr ) { return strtolower( $m['to'] ) === strtolower( $addr ); } ) ); }

/* ---- Posts and meta. ---- */
function mk_post( $args ) {
    $id = $GLOBALS['mk_next']++;
    $GLOBALS['mk_posts'][ $id ] = (object) array_merge( array( 'ID' => $id, 'post_type' => 'uc_event', 'post_status' => 'publish', 'post_title' => 'Event ' . $id, 'post_author' => 0, 'post_content' => '', 'post_name' => 'event-' . $id, 'post_date' => date( 'Y-m-d H:i:s' ) ), $args, array( 'ID' => $id ) );
    return $id;
}
function get_post( $id = null ) { $id = is_object( $id ) ? $id->ID : (int) $id; return isset( $GLOBALS['mk_posts'][ $id ] ) ? $GLOBALS['mk_posts'][ $id ] : null; }
function get_post_status( $id ) { $p = get_post( $id ); return $p ? $p->post_status : false; }
function get_post_type( $id ) { $p = get_post( $id ); return $p ? $p->post_type : false; }
function get_post_field( $f, $id ) { $p = get_post( $id ); return ( $p && isset( $p->$f ) ) ? $p->$f : ''; }
function get_the_title( $id = 0 ) { return get_post_field( 'post_title', $id ); }
function get_permalink( $id = 0 ) { return 'https://resources.example.org/events/' . (int) ( is_object( $id ) ? $id->ID : $id ) . '/'; }
function wp_update_post( $a, $e = false ) { $id = (int) $a['ID']; foreach ( $a as $k => $v ) { if ( 'ID' !== $k && isset( $GLOBALS['mk_posts'][ $id ] ) ) { $GLOBALS['mk_posts'][ $id ]->$k = $v; } } return $id; }
function get_post_meta( $id, $k = '', $s = false ) { return isset( $GLOBALS['mk_meta'][ (int) $id ][ $k ] ) ? $GLOBALS['mk_meta'][ (int) $id ][ $k ] : ( $s ? '' : array() ); }
function update_post_meta( $id, $k, $v, $p = '' ) { $GLOBALS['mk_meta'][ (int) $id ][ $k ] = $v; return true; }
function add_post_meta( $id, $k, $v, $u = false ) { if ( $u && isset( $GLOBALS['mk_meta'][ (int) $id ][ $k ] ) ) { return false; } $GLOBALS['mk_meta'][ (int) $id ][ $k ] = $v; return true; }
function delete_post_meta( $id, $k, $v = '' ) { unset( $GLOBALS['mk_meta'][ (int) $id ][ $k ] ); return true; }

class WP_Query {
    public $posts = array();
    public function __construct( $a = array() ) {
        $st  = isset( $a['post_status'] ) ? (array) $a['post_status'] : array( 'publish' );
        $out = array();
        foreach ( $GLOBALS['mk_posts'] as $id => $p ) {
            if ( isset( $a['post_type'] ) && $p->post_type !== $a['post_type'] ) { continue; }
            if ( ! in_array( $p->post_status, $st, true ) ) { continue; }
            $ok = true;
            foreach ( isset( $a['meta_query'] ) ? $a['meta_query'] : array() as $k => $c ) {
                if ( 'relation' === $k || ! is_array( $c ) ) { continue; }
                $has  = isset( $GLOBALS['mk_meta'][ $id ][ $c['key'] ] );
                $have = $has ? (string) $GLOBALS['mk_meta'][ $id ][ $c['key'] ] : null;
                $cmp  = isset( $c['compare'] ) ? strtoupper( $c['compare'] ) : '=';
                if ( 'NOT EXISTS' === $cmp ) { if ( $has ) { $ok = false; } continue; }
                if ( 'EXISTS' === $cmp ) { if ( ! $has ) { $ok = false; } continue; }
                if ( 'BETWEEN' === $cmp ) { if ( ! $has || $have < $c['value'][0] || $have > $c['value'][1] ) { $ok = false; } continue; }
                if ( ! $has || $have !== (string) $c['value'] ) { $ok = false; }
            }
            if ( $ok ) { $out[] = (int) $id; }
        }
        $this->posts = ( isset( $a['fields'] ) && 'ids' === $a['fields'] ) ? $out : array_map( 'get_post', $out );
    }
}

/* ---- Terms. ---- */
function mk_term( $tax, $name ) {
    $t = new WP_Term(); $t->term_id = $GLOBALS['mk_next_term']++; $t->name = $name; $t->slug = sanitize_title( $name ); $t->taxonomy = $tax;
    $GLOBALS['mk_terms'][ $t->term_id ] = $t;
    return $t->term_id;
}
function get_term( $id, $tax = '' ) { $id = (int) $id; return ( isset( $GLOBALS['mk_terms'][ $id ] ) && ( '' === $tax || $GLOBALS['mk_terms'][ $id ]->taxonomy === $tax ) ) ? $GLOBALS['mk_terms'][ $id ] : null; }
function get_term_by( $f, $v, $tax = '' ) { foreach ( $GLOBALS['mk_terms'] as $t ) { if ( $t->taxonomy === $tax && ( ( 'name' === $f && $t->name === $v ) || ( 'slug' === $f && $t->slug === $v ) || ( 'id' === $f && $t->term_id === (int) $v ) ) ) { return $t; } } return false; }
function term_exists( $t, $tax = '' ) { return get_term( $t, $tax ) ? array( 'term_id' => (int) $t ) : null; }
function get_terms( $a = array() ) {
    $out = array();
    foreach ( $GLOBALS['mk_terms'] as $t ) { if ( isset( $a['taxonomy'] ) && $t->taxonomy !== $a['taxonomy'] ) { continue; } $out[] = $t; }
    return ( isset( $a['fields'] ) && 'ids' === $a['fields'] ) ? wp_list_pluck( $out, 'term_id' ) : $out;
}
function get_term_meta( $id, $k = '', $s = false ) { return isset( $GLOBALS['mk_tmeta'][ (int) $id ][ $k ] ) ? $GLOBALS['mk_tmeta'][ (int) $id ][ $k ] : ''; }
function update_term_meta( $id, $k, $v ) { $GLOBALS['mk_tmeta'][ (int) $id ][ $k ] = $v; return true; }
function wp_set_object_terms( $id, $terms, $tax, $append = false ) { $GLOBALS['mk_obj_terms'][ (int) $id ][ $tax ] = array_values( array_map( 'intval', (array) $terms ) ); return $GLOBALS['mk_obj_terms'][ (int) $id ][ $tax ]; }
function wp_get_object_terms( $id, $tax, $a = array() ) {
    $tax = is_array( $tax ) ? reset( $tax ) : $tax; $id = is_array( $id ) ? reset( $id ) : $id;
    $ids = isset( $GLOBALS['mk_obj_terms'][ (int) $id ][ $tax ] ) ? $GLOBALS['mk_obj_terms'][ (int) $id ][ $tax ] : array();
    if ( isset( $a['fields'] ) && 'ids' === $a['fields'] ) { return $ids; }
    return array_values( array_filter( array_map( 'get_term', $ids ) ) );
}
function wp_get_post_terms( $id, $tax = '', $a = array() ) { return wp_get_object_terms( $id, $tax, $a ); }
function get_the_terms( $id, $tax ) { $t = wp_get_object_terms( $id, $tax ); return $t ? $t : false; }

/* ---- Users. ---- */
function mk_user( $email, $name, $role = '', $site_admin = false ) {
    $id = count( $GLOBALS['mk_users'] ) + 1;
    $GLOBALS['mk_users'][ $id ] = (object) array( 'ID' => $id, 'user_email' => $email, 'display_name' => $name, 'site_admin' => $site_admin );
    if ( '' !== $role ) { $GLOBALS['mk_umeta'][ $id ]['_uc_calendar_role'] = $role; }
    return $id;
}
function get_userdata( $id ) { $id = (int) $id; return isset( $GLOBALS['mk_users'][ $id ] ) ? $GLOBALS['mk_users'][ $id ] : false; }
function get_user_by( $f, $v ) { foreach ( $GLOBALS['mk_users'] as $u ) { if ( ( 'email' === $f && $u->user_email === $v ) || ( 'id' === $f && $u->ID === (int) $v ) ) { return $u; } } return false; }
function user_can( $u, $cap ) { $u = is_object( $u ) ? $u : get_userdata( $u ); return $u && 'manage_options' === $cap && ! empty( $u->site_admin ); }
function get_user_meta( $id, $k = '', $s = false ) { return isset( $GLOBALS['mk_umeta'][ (int) $id ][ $k ] ) ? $GLOBALS['mk_umeta'][ (int) $id ][ $k ] : ( $s ? '' : array() ); }
function update_user_meta( $id, $k, $v ) { $GLOBALS['mk_umeta'][ (int) $id ][ $k ] = $v; return true; }
function get_users( $a = array() ) {
    $out = array();
    foreach ( $GLOBALS['mk_users'] as $id => $u ) {
        if ( isset( $a['meta_key'] ) && ! isset( $GLOBALS['mk_umeta'][ $id ][ $a['meta_key'] ] ) ) { continue; }
        if ( isset( $a['include'] ) && ! in_array( $id, array_map( 'intval', (array) $a['include'] ), true ) ) { continue; }
        $out[] = $u;
    }
    return $out;
}

/* ---- $wpdb: the SQL the mail code writes, and the ledger's UNIQUE keys. ---- */
class MK_DB {
    public $prefix = 'wp_';
    public $posts = 'wp_posts';
    public $insert_id = 0;
    public $last_error = '';
    private $unique = array(
        'wp_uc_reminder_log' => array( array( 'event_id', 'recipient_hash' ), array( 'token' ) ),
    );
    public function suppress_errors( $s = true ) { return false; }
    public function esc_like( $t ) { return addcslashes( (string) $t, '_%\\' ); }
    public function prepare( $q, ...$args ) {
        if ( 1 === count( $args ) && is_array( $args[0] ) ) { $args = $args[0]; }
        $i = 0;
        return preg_replace_callback( '/%[dsf]/', function ( $m ) use ( &$i, $args ) {
            $v = isset( $args[ $i ] ) ? $args[ $i ] : ''; $i++;
            return '%d' === $m[0] ? (string) (int) $v : ( '%f' === $m[0] ? (string) (float) $v : "'" . addslashes( (string) $v ) . "'" );
        }, $q );
    }
    public function insert( $table, $data, $fmt = null ) {
        foreach ( isset( $this->unique[ $table ] ) ? $this->unique[ $table ] : array() as $cols ) {
            foreach ( $GLOBALS['mk_db'][ $table ] as $row ) {
                $same = true;
                foreach ( $cols as $c ) { if ( (string) $row[ $c ] !== (string) $data[ $c ] ) { $same = false; } }
                if ( $same ) { $this->last_error = 'Duplicate entry'; return false; }
            }
        }
        $data['id'] = $GLOBALS['mk_db_next']++;
        $GLOBALS['mk_db'][ $table ][ $data['id'] ] = $data;
        $this->insert_id = $data['id'];
        return 1;
    }
    public function update( $table, $data, $where, $f1 = null, $f2 = null ) {
        $n = 0;
        foreach ( $GLOBALS['mk_db'][ $table ] as $id => $row ) {
            $hit = true;
            foreach ( $where as $k => $v ) { if ( (string) $row[ $k ] !== (string) $v ) { $hit = false; } }
            if ( $hit ) { $GLOBALS['mk_db'][ $table ][ $id ] = array_merge( $row, $data ); $n++; }
        }
        return $n;
    }
    /** Conditions joined by AND: col = 'v', col = 3, col <> ''. */
    private function where( $sql ) {
        $conds = array();
        if ( ! preg_match( '/\bWHERE\s+(.+?)(?:\s+ORDER BY|\s+LIMIT|\s+GROUP BY|$)/s', $sql, $m ) ) { return $conds; }
        foreach ( preg_split( '/\s+AND\s+/', trim( $m[1] ) ) as $c ) {
            if ( preg_match( "/^(\\w+(?:\\.\\w+)?)\\s*(=|<>)\\s*(?:'((?:[^'\\\\]|\\\\.)*)'|(-?\\d+))$/", trim( $c ), $x ) ) {
                $conds[] = array( preg_replace( '/^\w+\./', '', $x[1] ), $x[2], isset( $x[4] ) && '' !== $x[4] ? $x[4] : stripslashes( $x[3] ) );
            } else {
                throw new RuntimeException( 'mail-kit: a WHERE clause it cannot read: ' . $c );
            }
        }
        return $conds;
    }
    private function rows( $sql ) {
        if ( ! preg_match( '/\bFROM\s+(\w+)/', $sql, $t ) || ! isset( $GLOBALS['mk_db'][ $t[1] ] ) ) {
            throw new RuntimeException( 'mail-kit: a table it does not have: ' . $sql );
        }
        $out = array();
        foreach ( $GLOBALS['mk_db'][ $t[1] ] as $row ) {
            $ok = true;
            foreach ( $this->where( $sql ) as $c ) {
                $v = isset( $row[ $c[0] ] ) ? (string) $row[ $c[0] ] : '';
                if ( ( '=' === $c[1] && $v !== (string) $c[2] ) || ( '<>' === $c[1] && $v === (string) $c[2] ) ) { $ok = false; }
            }
            if ( $ok ) { $out[] = $row; }
        }
        if ( preg_match( '/ORDER BY\s+(\w+)\s+(DESC|ASC)?/i', $sql, $o ) ) {
            usort( $out, function ( $a, $b ) use ( $o ) { $r = strcmp( (string) $a[ $o[1] ], (string) $b[ $o[1] ] ); return ( isset( $o[2] ) && 'DESC' === strtoupper( $o[2] ) ) ? -$r : $r; } );
        }
        if ( preg_match( '/LIMIT\s+(\d+)/', $sql, $l ) ) { $out = array_slice( $out, 0, (int) $l[1] ); }
        return $out;
    }
    public function get_results( $sql, $o = 'OBJECT' ) { return array_map( function ( $r ) { return (object) $r; }, $this->rows( $sql ) ); }
    public function get_row( $sql, $o = 'OBJECT', $y = 0 ) { $r = $this->rows( $sql ); return $r ? (object) $r[0] : null; }
    public function get_var( $sql ) {
        $r = $this->rows( $sql );
        if ( preg_match( '/SELECT\s+COUNT\(\*\)/i', $sql ) ) { return count( $r ); }
        if ( ! $r ) { return null; }
        preg_match( '/SELECT\s+(\w+)/i', $sql, $c );
        return isset( $r[0][ $c[1] ] ) ? $r[0][ $c[1] ] : null;
    }
    public function query( $sql ) {
        if ( preg_match( "/^UPDATE\\s+(\\w+)\\s+SET\\s+(.+?)\\s+WHERE/s", trim( $sql ), $m ) ) {
            $set = array();
            foreach ( preg_split( '/,\s*/', $m[2] ) as $pair ) {
                if ( preg_match( "/(\\w+)\\s*=\\s*'((?:[^'\\\\]|\\\\.)*)'/", $pair, $p ) ) { $set[ $p[1] ] = stripslashes( $p[2] ); }
            }
            $n = 0;
            foreach ( $this->rows( str_replace( 'UPDATE ' . $m[1], 'SELECT * FROM ' . $m[1], preg_replace( '/SET\s+.+?\s+WHERE/s', 'WHERE', $sql ) ) ) as $row ) {
                $GLOBALS['mk_db'][ $m[1] ][ $row['id'] ] = array_merge( $row, $set );
                $n++;
            }
            return $n;
        }
        throw new RuntimeException( 'mail-kit: a query it cannot run: ' . $sql );
    }
}
$GLOBALS['wpdb'] = new MK_DB();

/** One registration row, as SFAF_RSVP::submit() writes it. */
function mk_rsvp( $event_id, $first, $email, $status = 'confirmed' ) {
    $GLOBALS['wpdb']->insert( 'wp_uc_rsvps', array(
        'event_id' => (int) $event_id, 'name' => $first, 'first_name' => $first, 'last_name' => '', 'email' => $email,
        'phone' => '', 'status' => $status, 'token' => bin2hex( random_bytes( 16 ) ), 'created_at' => date( 'Y-m-d H:i:s' ), 'format' => '',
    ) );
    $s =& sfaf_rsvp_count_store(); $s = array();
    return $GLOBALS['wpdb']->insert_id;
}
function mk_ledger() { return array_values( $GLOBALS['mk_db']['wp_uc_reminder_log'] ); }

/* ---- The real code. ---- */
function mk_lift( $src, $needle ) {
    $at = strpos( $src, $needle );
    if ( false === $at ) { echo "FAIL: mail-kit could not find $needle in the source, so it is testing nothing.\n"; exit( 1 ); }
    $d = 0;
    for ( $i = strpos( $src, '{', $at ); $i < strlen( $src ); $i++ ) {
        if ( '{' === $src[ $i ] ) { $d++; }
        if ( '}' === $src[ $i ] && 0 === --$d ) { return substr( $src, $at, $i - $at + 1 ) . "\n"; }
    }
    echo "FAIL: $needle never closes.\n"; exit( 1 );
}
$mk_tpl  = file_get_contents( $mk_root . '/includes/sfaf-template-functions.php' );
$mk_main = file_get_contents( $mk_root . '/sfaf-calendar.php' );
$mk_port = file_get_contents( $mk_root . '/includes/class-sfaf-portal.php' );

foreach ( array( 'function sfaf_local_timestamp(', 'function sfaf_ap_date(', 'function sfaf_ap_time(', 'function sfaf_ap_time_range(',
                 'function sfaf_ap_time_zone(', 'function sfaf_ap_zoned(', 'function sfaf_location_part_keys(', 'function sfaf_event_location_name(',
                 'function sfaf_event_location(', 'function sfaf_event_takes_rsvps(', 'function sfaf_flatten_html(' ) as $mk_n ) {
    eval( mk_lift( $mk_tpl, $mk_n ) );
}
foreach ( array( 'function &sfaf_rsvp_count_store(', 'function &sfaf_rsvp_format_count_store(', 'function sfaf_clear_rsvp_count_cache(',
                 'function sfaf_get_rsvp_count_by_format(', 'function sfaf_get_rsvp_count(', 'function sfaf_capacity_meta_key(', 'function sfaf_event_capacity(',
                 'function sfaf_event_formats(', 'function sfaf_format_full(', 'function sfaf_event_full(' ) as $mk_n ) {
    eval( mk_lift( $mk_main, $mk_n ) );
}
function sfaf_show_feature( $id, $f ) { $v = get_post_meta( $id, '_uc_show_' . $f, true ); return '' === $v ? true : '1' === (string) $v; }
function sfaf_replace_tokens( $t, $e, $d = array() ) { return $t; }
function sfaf_ics_url( $id ) { return 'https://resources.example.org/?uc_ics=' . (int) $id; }
function sfaf_google_calendar_url( $id ) { return 'https://calendar.google.com/calendar/render?action=TEMPLATE'; }
function sfaf_icon( $n, $a = array() ) { return ''; }
function sfaf_get_faqs( $id ) { return array(); }

/* THE EVENT GATE, OUT OF THE PORTAL, and nothing else of it. */
eval( 'class SFAF_Portal {'
    . mk_lift( $mk_port, 'public static function roles(' )
    . mk_lift( $mk_port, 'public static function is_site_admin(' )
    . mk_lift( $mk_port, 'public static function get_role(' )
    . mk_lift( $mk_port, 'public static function user_can_view_all(' )
    . mk_lift( $mk_port, 'public static function user_can_edit_event(' )
    . mk_lift( $mk_port, 'public static function link(' )
    . '}' );

class SFAF_Optins { public static $recorded = array(); public static function record( $e, $n, $id, $src ) { self::$recorded[] = $e; return true; } }

foreach ( array( 'class-sfaf-email', 'class-sfaf-online', 'class-sfaf-cancellation', 'class-sfaf-teams', 'class-sfaf-venues', 'class-sfaf-series',
                 'class-sfaf-sources', 'class-sfaf-reminders', 'class-sfaf-notifications', 'class-sfaf-digest', 'class-sfaf-rsvp' ) as $mk_f ) {
    require_once $mk_root . '/includes/' . $mk_f . '.php';
}

mk_reset();
