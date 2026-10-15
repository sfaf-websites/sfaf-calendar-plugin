<?php
/**
 * THE EMBED STAYS CHEAP, AND A PUBLIC REQUEST WRITES NOTHING IT SHOULD NOT (3.112.2).
 *
 *     php .claude/embed-budget-test.php
 *
 * 3.112.0 took sfaf.org's calendar down, and the cause found was cost: a block
 * with no filters attribute drew the Location row, whose index read every
 * published event's meta and venue one query at a time, 24 queries in 3.111.0
 * against 397 for 300 events, on every cache miss, on a server (LiteSpeed,
 * resources.sfaf.org) whose per-client limits then stopped accepting
 * connections. So this counts queries, which is the measure that carries over
 * to the live database, the way WordPress would make them:
 *
 *   - one per WP_Query, and one each to prime meta and terms when it returns
 *     posts rather than ids
 *   - a meta read of a post not primed is one query for all of its meta
 *   - wp_get_post_terms() is a query every time; get_the_terms() only when the
 *     post's terms are not primed; get_terms() once per distinct ask
 *   - update_meta_cache() and update_object_term_cache() are one query each,
 *     and prime what they name
 *
 * over a site the size of the live one (300 published events across six
 * months, 287 drafts) and fails when:
 *
 *   A  a cold embed build exceeds QUERY_BUDGET queries, or TIME_BUDGET_MS here,
 *      for: the sfaf.org/calendar block (filters=category,organizer,series,
 *      per_page=12, view=combined, toggle=yes, source_links=no, page=1,
 *      mode=block), a block with no filters attribute, a block that asks for
 *      every row, and the two neighbouring months the embed prefetches
 *   B  a block with no filters attribute shows Tags or Location
 *   C  an embed request, the version a loader reads, or a public page's
 *      shortcode and hooks write anything but the payload cache's own
 *      transient and the Location index's, ask anything of a URL, or schedule
 *      or run a scheduled task
 *   D  the loader asks anything of PHP: it reads embed-version.js
 *
 * PLANT 1 in plant-31122.php puts the one-at-a-time index back.
 */

const QUERY_BUDGET   = 120;
const TIME_BUDGET_MS = 500;

$root = dirname( __DIR__ );
$fails = array();
function bt( $ok, $why ) { global $fails; if ( ! $ok ) { $fails[] = $why; } }

/* ---- What a public request may not do, recorded. -------------------------- */
$GLOBALS['BT'] = array( 'remote' => array(), 'cron' => array(), 'writes' => array() );
foreach ( array( 'wp_remote_get', 'wp_remote_post', 'wp_remote_request', 'wp_remote_head', 'wp_safe_remote_get', 'wp_safe_remote_post', 'wp_safe_remote_request' ) as $fn ) {
    eval( 'function ' . $fn . '( $url = "", $args = array() ) { $GLOBALS["BT"]["remote"][] = "' . $fn . ' " . $url; return array( "response" => array( "code" => 200 ), "body" => "" ); }' );
}
foreach ( array( 'spawn_cron', 'wp_schedule_event', 'wp_schedule_single_event', 'wp_cron' ) as $fn ) {
    eval( 'function ' . $fn . '( ...$a ) { $GLOBALS["BT"]["cron"][] = "' . $fn . '"; return true; }' );
}

/* ---- The counting model. -------------------------------------------------- */
function bq_reset() { $GLOBALS['BQ'] = array( 'q' => 0, 'by' => array(), 'pm' => array(), 'pt' => array(), 'once' => array(), 'cached' => false, 'batch' => 0 ); }
function bq( $why ) { $GLOBALS['BQ']['q']++; $GLOBALS['BQ']['by'][ $why ] = ( isset( $GLOBALS['BQ']['by'][ $why ] ) ? $GLOBALS['BQ']['by'][ $why ] : 0 ) + 1; }
function bq_meta( $id ) { $id = (int) $id; if ( ! isset( $GLOBALS['BQ']['pm'][ $id ] ) ) { $GLOBALS['BQ']['pm'][ $id ] = 1; bq( 'meta of a post not primed' ); } }
function bq_terms( $id, $tax ) {
    if ( $GLOBALS['BQ']['batch'] > 0 ) { return; }
    $id = (int) $id;
    if ( $GLOBALS['BQ']['cached'] ) {
        if ( isset( $GLOBALS['BQ']['pt'][ $id ] ) || isset( $GLOBALS['BQ']['pt'][ $id . '|' . $tax ] ) ) { return; }
        $GLOBALS['BQ']['pt'][ $id . '|' . $tax ] = 1; bq( 'get_the_terms, not primed' ); return;
    }
    bq( 'wp_get_post_terms' );
}
function update_meta_cache( $type, $ids ) { bq( 'update_meta_cache' ); foreach ( (array) $ids as $i ) { $GLOBALS['BQ']['pm'][ (int) $i ] = 1; } return true; }
function update_object_term_cache( $ids, $type ) { bq( 'update_object_term_cache' ); foreach ( (array) $ids as $i ) { $GLOBALS['BQ']['pt'][ (int) $i ] = 1; } return true; }
bq_reset();

/* wp-kit, with the reads counted and the writes recorded. */
$kit = file_get_contents( __DIR__ . '/wp-kit.php' );
$swap = array(
    "function get_post_meta( \$id, \$k = '', \$s = false ) {" => "function get_post_meta( \$id, \$k = '', \$s = false ) { bq_meta( \$id );",
    "function wp_get_post_terms( \$id, \$tax = '', \$a = array() ) {" => "function wp_get_post_terms( \$id, \$tax = '', \$a = array() ) { bq_terms( \$id, \$tax );",
    "function get_the_terms( \$id, \$tax ) { \$t = wp_get_post_terms( \$id, \$tax );" => "function get_the_terms( \$id, \$tax ) { \$GLOBALS['BQ']['cached'] = true; \$t = wp_get_post_terms( \$id, \$tax ); \$GLOBALS['BQ']['cached'] = false;",
    "function wp_get_object_terms( \$id, \$tax, \$a = array() ) {" => "function wp_get_object_terms( \$id, \$tax, \$a = array() ) { bq( 'wp_get_object_terms' ); \$GLOBALS['BQ']['batch']++; try { return bt_object_terms( \$id, \$tax, \$a ); } finally { \$GLOBALS['BQ']['batch']--; } }\nfunction bt_object_terms( \$id, \$tax, \$a = array() ) {",
    "function get_terms( \$a = array(), \$b = null ) {" => "function get_terms( \$a = array(), \$b = null ) { \$k = json_encode( \$a ); if ( ! isset( \$GLOBALS['BQ']['once'][ \$k ] ) ) { \$GLOBALS['BQ']['once'][ \$k ] = 1; bq( 'get_terms' ); }",
    "function update_option( \$n, \$v = null, \$a = null ) {" => "function update_option( \$n, \$v = null, \$a = null ) { \$GLOBALS['BT']['writes'][] = 'option ' . \$n;",
    "function add_option( \$n, \$v = '', \$d = '', \$a = null ) {" => "function add_option( \$n, \$v = '', \$d = '', \$a = null ) { \$GLOBALS['BT']['writes'][] = 'option ' . \$n;",
    "function delete_option( \$n ) {" => "function delete_option( \$n ) { \$GLOBALS['BT']['writes'][] = 'option ' . \$n;",
    "function set_transient( \$k, \$v, \$e = 0 ) {" => "function set_transient( \$k, \$v, \$e = 0 ) { \$GLOBALS['BT']['writes'][] = 'transient ' . \$k;",
    "function delete_transient( \$k ) {" => "function delete_transient( \$k ) { \$GLOBALS['BT']['writes'][] = 'transient ' . \$k;",
    "function update_post_meta( \$id, \$k, \$v, \$prev = '' ) {" => "function update_post_meta( \$id, \$k, \$v, \$prev = '' ) { \$GLOBALS['BT']['writes'][] = 'post meta ' . \$k;",
    "function update_term_meta( \$id, \$k, \$v, \$p = '' ) {" => "function update_term_meta( \$id, \$k, \$v, \$p = '' ) { \$GLOBALS['BT']['writes'][] = 'term meta ' . \$k;",
    "\$GLOBALS['kit_root'] = str_replace( '\\\\', '/', dirname( __DIR__ ) );" => "\$GLOBALS['kit_root'] = " . var_export( str_replace( '\\', '/', $root ), true ) . ';',
);
foreach ( $swap as $from => $to ) {
    if ( 1 !== substr_count( $kit, $from ) ) { echo "this check is blind: wp-kit.php no longer has: $from\n"; exit( 1 ); }
    $kit = str_replace( $from, $to, $kit );
}
$tmp = sys_get_temp_dir() . '/embed-budget-kit-' . getmypid() . '.php';
file_put_contents( $tmp, $kit );
require $tmp;
@unlink( $tmp );
if ( ! function_exists( 'wpautop' ) ) { function wpautop( $s ) { return $s; } }
if ( ! function_exists( 'get_post_stati' ) ) { function get_post_stati( $a = array() ) { return array( 'publish' => 1, 'future' => 1, 'draft' => 1, 'pending' => 1, 'private' => 1, 'trash' => 1 ); } }
if ( ! function_exists( 'wp_trim_words' ) ) { function wp_trim_words( $t, $n = 55, $m = '' ) { return implode( ' ', array_slice( preg_split( '/\s+/', trim( (string) $t ) ), 0, $n ) ); } }
if ( ! function_exists( 'force_balance_tags' ) ) { function force_balance_tags( $t ) { return $t; } }
if ( ! function_exists( 'get_http_origin' ) ) { function get_http_origin() { return ''; } }
if ( ! class_exists( 'WP_REST_Request' ) ) {
    class WP_REST_Request { private $p; function __construct( $p ) { $this->p = $p; } function get_param( $k ) { return isset( $this->p[ $k ] ) ? $this->p[ $k ] : null; } function get_route() { return '/sfaf-calendar/v1/embed'; } function get_method() { return 'GET'; } }
    class WP_REST_Response { private $d; private $h = array(); function __construct( $d = null, $s = 200 ) { $this->d = $d; } function header( $k, $v ) { $this->h[ $k ] = $v; } function get_headers() { return $this->h; } function set_headers( $h ) { $this->h = $h; } function get_data() { return $this->d; } }
}

/* A query model that honours what the renderers ask. */
function bt_query( $a ) {
    bq( 'WP_Query' );
    $type   = isset( $a['post_type'] ) ? (array) $a['post_type'] : array( 'uc_event' );
    $status = isset( $a['post_status'] ) ? (array) $a['post_status'] : array( 'publish' );
    $out = array();
    foreach ( $GLOBALS['kit_posts'] as $id => $p ) {
        if ( ! in_array( $p->post_type, $type, true ) ) { continue; }
        if ( ! in_array( 'any', $status, true ) && ! in_array( $p->post_status, $status, true ) ) { continue; }
        if ( ! empty( $a['post__in'] ) && ! in_array( (int) $id, array_map( 'intval', (array) $a['post__in'] ), true ) ) { continue; }
        if ( ! empty( $a['post__not_in'] ) && in_array( (int) $id, array_map( 'intval', (array) $a['post__not_in'] ), true ) ) { continue; }
        if ( ! empty( $a['meta_query'] ) && ! bt_meta_ok( $id, $a['meta_query'] ) ) { continue; }
        if ( ! empty( $a['tax_query'] ) && ! bt_tax_ok( $id, $a['tax_query'] ) ) { continue; }
        $out[] = $p;
    }
    usort( $out, function ( $x, $y ) { return strcmp( (string) $GLOBALS['kit_meta'][ $x->ID ]['_uc_event_date'], (string) $GLOBALS['kit_meta'][ $y->ID ]['_uc_event_date'] ); } );
    $per = isset( $a['posts_per_page'] ) ? (int) $a['posts_per_page'] : 10;
    if ( $per > 0 ) { $page = max( 1, isset( $a['paged'] ) ? (int) $a['paged'] : 1 ); $out = array_slice( $out, ( $page - 1 ) * $per, $per ); }
    if ( ! ( isset( $a['fields'] ) && 'ids' === $a['fields'] ) && $out ) {
        if ( ! isset( $a['update_post_meta_cache'] ) || false !== $a['update_post_meta_cache'] ) { bq( 'priming meta' ); foreach ( $out as $p ) { $GLOBALS['BQ']['pm'][ $p->ID ] = 1; } }
        if ( ! isset( $a['update_post_term_cache'] ) || false !== $a['update_post_term_cache'] ) { bq( 'priming terms' ); foreach ( $out as $p ) { $GLOBALS['BQ']['pt'][ $p->ID ] = 1; } }
    }
    return ( isset( $a['fields'] ) && 'ids' === $a['fields'] ) ? wp_list_pluck( $out, 'ID' ) : $out;
}
function bt_meta_ok( $id, $mq ) {
    $rel = ( isset( $mq['relation'] ) && 'OR' === strtoupper( $mq['relation'] ) ) ? 'OR' : 'AND';
    $res = array();
    foreach ( $mq as $k => $c ) {
        if ( 'relation' === $k || ! is_array( $c ) ) { continue; }
        if ( ! isset( $c['key'] ) ) { $res[] = bt_meta_ok( $id, $c ); continue; }
        $have = isset( $GLOBALS['kit_meta'][ $id ][ $c['key'] ] ) ? (string) $GLOBALS['kit_meta'][ $id ][ $c['key'] ] : null;
        $cmp  = isset( $c['compare'] ) ? strtoupper( $c['compare'] ) : ( isset( $c['value'] ) ? '=' : 'EXISTS' );
        $v    = isset( $c['value'] ) ? $c['value'] : null;
        switch ( $cmp ) {
            case 'EXISTS': $ok = null !== $have; break;
            case 'NOT EXISTS': $ok = null === $have; break;
            case '=': $ok = $have === (string) $v; break;
            case '!=': $ok = $have !== (string) $v; break;
            case '>=': $ok = null !== $have && $have >= $v; break;
            case '<=': $ok = null !== $have && $have <= $v; break;
            case '>': $ok = null !== $have && $have > $v; break;
            case '<': $ok = null !== $have && $have < $v; break;
            case 'BETWEEN': $ok = null !== $have && $have >= $v[0] && $have <= $v[1]; break;
            case 'IN': $ok = in_array( $have, (array) $v, true ); break;
            case 'NOT IN': $ok = ! in_array( $have, (array) $v, true ); break;
            default: $ok = true;
        }
        $res[] = $ok;
    }
    if ( ! $res ) { return true; }
    return 'OR' === $rel ? in_array( true, $res, true ) : ! in_array( false, $res, true );
}
function bt_tax_ok( $id, $tq ) {
    foreach ( $tq as $k => $c ) {
        if ( 'relation' === $k || ! is_array( $c ) || empty( $c['taxonomy'] ) ) { continue; }
        $have = isset( $GLOBALS['kit_terms'][ $id ][ $c['taxonomy'] ] ) ? $GLOBALS['kit_terms'][ $id ][ $c['taxonomy'] ] : array();
        $want = (array) $c['terms'];
        if ( 'slug' === ( isset( $c['field'] ) ? $c['field'] : 'term_id' ) ) {
            $slugs = array(); foreach ( $have as $t ) { $slugs[] = 11 === (int) $t ? 'alpha' : 'beta'; }
            $hit = (bool) array_intersect( $want, $slugs );
        } else {
            $hit = (bool) array_intersect( array_map( 'intval', $want ), array_map( 'intval', $have ) );
        }
        $op = isset( $c['operator'] ) ? strtoupper( $c['operator'] ) : 'IN';
        if ( 'NOT IN' === $op ? $hit : ! $hit ) { return false; }
    }
    return true;
}
$GLOBALS['kit_query'] = 'bt_query'; $GLOBALS['kit_get_posts'] = 'bt_query'; $GLOBALS['kit_loop'] = true;
$GLOBALS['kit_db'] = function ( $m, $sql ) { bq( 'wpdb' ); return ( 'get_results' === $m || 'get_col' === $m ) ? array() : null; };

/* The live site's size: 300 published events across six months, 287 drafts,
   a third with a venue, a third with a typed address, every 25th multi-day. */
mt_srand( 7 );
$today = current_time( 'Y-m-d' );
for ( $i = 0; $i < 587; $i++ ) {
    $id = kit_write_post( array( 'post_type' => 'uc_event', 'post_status' => $i < 300 ? 'publish' : 'draft', 'post_title' => 'Event ' . $i, 'post_author' => 1, 'post_content' => '<p>Words about it.</p>' ) );
    $d = date( 'Y-m-d', strtotime( $today . ' ' . mt_rand( -45, 140 ) . ' days' ) );
    $meta = array( '_uc_event_date' => $d, '_uc_start_time' => '18:00', '_uc_end_time' => '19:30', '_uc_location' => ( 0 === $i % 3 ? '' : ( 1035 + $i % 9 ) . ' Market Street' ), '_uc_rsvp_enabled' => ( $i % 2 ? '1' : '' ) );
    if ( 0 === $i % 25 ) { $meta['_uc_multi_day'] = '1'; $meta['_uc_last_date'] = date( 'Y-m-d', strtotime( $d . ' +2 days' ) ); }
    foreach ( $meta as $k => $v ) { $GLOBALS['kit_meta'][ $id ][ $k ] = $v; }
    $GLOBALS['kit_terms'][ $id ]['uc_event_category'] = array( 11 + $i % 2 );
    $GLOBALS['kit_terms'][ $id ]['uc_organizer'] = array( 11 + ( $i >> 1 ) % 2 );
    $GLOBALS['kit_terms'][ $id ]['uc_series'] = array( 11 + $i % 2 );
    if ( 1 === $i % 3 ) { $GLOBALS['kit_terms'][ $id ]['uc_venue'] = array( 11 + $i % 2 ); }
}

$embed = new SFAF_Embed( new SFAF_Shortcodes() );
$sfaf  = array( 'filters' => 'category,organizer,series', 'per_page' => 12, 'view' => 'combined', 'toggle' => 'yes', 'source_links' => 'no', 'page' => 1, 'mode' => 'block' );
/** One request, cold: what it cost, and what it wrote. */
function bt_run( $embed, $p ) {
    bq_reset();
    $GLOBALS['kit_trans']   = array();
    $GLOBALS['BT']['writes'] = array();
    $t = microtime( true );
    $d = $embed->handle_request( new WP_REST_Request( $p ) )->get_data();
    return array( 'q' => $GLOBALS['BQ']['q'], 'ms' => ( microtime( true ) - $t ) * 1000, 'by' => $GLOBALS['BQ']['by'], 'html' => isset( $d['html'] ) ? (string) $d['html'] : '', 'writes' => $GLOBALS['BT']['writes'] );
}
$cases = array(
    'the sfaf.org/calendar block'           => $sfaf,
    'a block with no filters attribute'     => array( 'per_page' => 12, 'view' => 'combined', 'toggle' => 'yes', 'page' => 1, 'mode' => 'block' ),
    'a block that asks for every row'       => array_merge( $sfaf, array( 'filters' => 'category,organizer,series,tag,location' ) ),
    'the month before, as the embed prefetches it' => array_merge( $sfaf, array( 'mode' => 'month', 'month' => date( 'Y-m', strtotime( $today . ' -1 month' ) ) ) ),
    'the month after'                       => array_merge( $sfaf, array( 'mode' => 'month', 'month' => date( 'Y-m', strtotime( $today . ' +1 month' ) ) ) ),
);
$rows = array();
$allowed_write = '#^transient (sfaf_embed_|sfaf_loc_index_)#';
foreach ( $cases as $name => $p ) {
    $r = bt_run( $embed, $p );
    arsort( $r['by'] );
    $top = array(); foreach ( array_slice( $r['by'], 0, 3, true ) as $k => $n ) { $top[] = "$k $n"; }
    $rows[] = sprintf( '  %-46s %4d queries  %6.1f ms   [%s]', $name, $r['q'], $r['ms'], implode( ', ', $top ) );
    bt( $r['q'] <= QUERY_BUDGET, "A: $name took {$r['q']} queries, over the budget of " . QUERY_BUDGET . ': ' . json_encode( $r['by'] ) );
    bt( $r['ms'] <= TIME_BUDGET_MS, sprintf( 'A: %s took %.0f ms here, over %d', $name, $r['ms'], TIME_BUDGET_MS ) );
    bt( '' !== $r['html'], "A: $name drew nothing, so its cost proves nothing" );
    foreach ( $r['writes'] as $w ) { bt( (bool) preg_match( $allowed_write, $w ), "C: $name wrote $w" ); }
    if ( 'a block with no filters attribute' === $name ) {
        bt( false === strpos( $r['html'], 'data-uc-pick="tag"' ) && false === strpos( $r['html'], 'data-uc-pick="location"' ), 'B: a block with no filters attribute shows Tags or Location' );
        bt( false !== strpos( $r['html'], 'data-uc-who' ), 'B: a block with no filters attribute lost the rows it had in 3.111.0' );
    }
    if ( 'a block that asks for every row' === $name ) {
        bt( false !== strpos( $r['html'], 'data-uc-pick="location"' ), 'A: the block that asks for every row drew no Location row, so the index was not measured' );
    }
}
/* A visitor's Location choice builds the index: within budget, and kept, so the
   next request with a choice reads it rather than the events. */
$picked = array_merge( $cases['a block that asks for every row'], array( 'active_locations' => 'p-' . substr( md5( strtolower( '1036 Market Street' ) ), 0, 10 ) ) );
$r = bt_run( $embed, $picked );
bt( $r['q'] <= QUERY_BUDGET, "A: a block with a Location chosen took {$r['q']} queries: " . json_encode( $r['by'] ) );
$kept = array_filter( $GLOBALS['kit_trans'], function ( $k ) { return 0 === strpos( $k, 'sfaf_loc_index_' ); }, ARRAY_FILTER_USE_KEY );
bt( (bool) $kept, 'A: the Location index is not kept between requests' );
$rows[] = sprintf( '  %-46s %4d queries  %6.1f ms', 'a block with a Location chosen', $r['q'], $r['ms'] );

/* ---- C: the loader's version, and a public page. -------------------------- */
$r = bt_run( $embed, array( 'embed_version' => '1' ) );
bt( $r['q'] <= 1 && ! $r['writes'], 'C: the version answer read or wrote something: ' . json_encode( $r ) );
$GLOBALS['BT']['writes'] = array();
bq_reset();
$sc = new SFAF_Shortcodes();
ob_start();
$b = $sc->render_calendar_block( array( 'view' => 'combined', 'toggle' => 'yes', 'month' => date( 'Y-m' ) ) );
ob_end_clean();
bt( $GLOBALS['BQ']['q'] <= QUERY_BUDGET, 'A: the shortcode block on this site took ' . $GLOBALS['BQ']['q'] . ' queries' );
/* A public page's hooks, twice: the first load on a fresh install runs the
   one-time migrations, which write their own flags once; a site in steady
   state, which is every load after that, must write nothing. */
$page_load = function () {
    foreach ( array( 'init', 'wp_loaded', 'wp', 'template_redirect', 'wp_enqueue_scripts' ) as $hook ) {
        foreach ( isset( $GLOBALS['kit_hooks'][ $hook ] ) ? $GLOBALS['kit_hooks'][ $hook ] : array() as $h ) {
            try { call_user_func_array( $h['cb'], array_slice( array( '' ), 0, $h['n'] ) ); } catch ( Throwable $e ) { /* a hook needing a real request is not this check's */ }
        }
    }
};
$page_load();
$GLOBALS['BT']['writes'] = array();
$GLOBALS['BT']['remote'] = array();
$GLOBALS['BT']['cron']   = array();
$page_load();
foreach ( $GLOBALS['BT']['writes'] as $w ) {
    bt( (bool) preg_match( $allowed_write, $w ), "C: a public page load in steady state wrote $w" );
}
bt( ! $GLOBALS['BT']['remote'], 'C: a public request asked a URL: ' . implode( '; ', $GLOBALS['BT']['remote'] ) );
bt( ! $GLOBALS['BT']['cron'], 'C: a public request scheduled or ran a task: ' . implode( '; ', $GLOBALS['BT']['cron'] ) );

/* ---- D: the loader. ------------------------------------------------------- */
$loader = (string) file_get_contents( $root . '/public/js/embed.js' );
$code   = preg_replace( '#/\*[\s\S]*?\*/|(?<![:\'"])//[^\n]*#', '', $loader );
bt( false === strpos( $code, 'XMLHttpRequest' ) && false === strpos( $code, 'fetch(' ) && false === strpos( $code, 'embed_version' ), 'D: the loader asks PHP something again' );
bt( (bool) preg_match( "#beside\('embed-version\.js'\) \+ '\?t=' \+ bucket#", $code ) && (bool) preg_match( '#Math\.floor\(Date\.now\(\) / 300000\) \* 300#', $code ), 'D: the loader does not ask for embed-version.js under a five-minute bucket' );

echo "\nThe embed's cost, counted as WordPress would query, and what a public request may do\n" . str_repeat( '=', 72 ) . "\n";
echo implode( "\n", $rows ) . "\n";
echo 'budget: ' . QUERY_BUDGET . ' queries and ' . TIME_BUDGET_MS . " ms a cold build; 3.111.0's sfaf.org block measured 80, 3.112.0's no-filters block 397.\n";
if ( $fails ) { echo "\n" . count( $fails ) . " problem(s):\n  - " . implode( "\n  - ", $fails ) . "\n"; exit( 1 ); }
echo "\nevery build is inside the budget, and no public request writes, asks a URL or runs a task it should not.\n";
exit( 0 );
