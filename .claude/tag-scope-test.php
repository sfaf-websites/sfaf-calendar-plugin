<?php
/**
 * THE ADDRESS CAN NARROW A TAG-SCOPED BLOCK AND NEVER WIDEN IT (3.112.1, 2a).
 *
 *     php .claude/tag-scope-test.php
 *
 * build_payload() resolves the tag with effective_organizer(), the clamp
 * written for organizers. This asks whether that is the right answer for tags,
 * through the embed route itself (SFAF_Embed::handle_request(), wp-kit.php),
 * in all three modes, with a query model that honours the tag clause:
 *
 *   1  a block scoped to one tag shows only that tag's events
 *   2  a visitor's active_tags inside the scope narrows it; outside the scope
 *      is dropped, and the block shows its own events, never another tag's
 *   3  an archived tag is never a filter, inside or outside a scope
 *   4  an old slug (an alias) answers as the live one, as scope and as choice
 *
 * PLANT 2a in plant-31121.php widens the clamp; this must fail on it.
 */

require __DIR__ . '/wp-kit.php';
if ( ! function_exists( 'wpautop' ) ) { function wpautop( $s ) { return $s; } }
if ( ! function_exists( 'get_post_stati' ) ) { function get_post_stati( $a = array() ) { return array( 'publish' => 1 ); } }
if ( ! function_exists( 'wp_trim_words' ) ) { function wp_trim_words( $t, $n = 55, $m = '' ) { return implode( ' ', array_slice( preg_split( '/\s+/', trim( (string) $t ) ), 0, $n ) ); } }
if ( ! function_exists( 'force_balance_tags' ) ) { function force_balance_tags( $t ) { return $t; } }
if ( ! function_exists( 'get_http_origin' ) ) { function get_http_origin() { return ''; } }
if ( ! class_exists( 'WP_REST_Request' ) ) {
    class WP_REST_Request { private $p; function __construct( $p ) { $this->p = $p; } function get_param( $k ) { return isset( $this->p[ $k ] ) ? $this->p[ $k ] : null; } function get_route() { return '/sfaf-calendar/v1/embed'; } function get_method() { return 'GET'; } }
    class WP_REST_Response { private $d; private $h = array(); function __construct( $d = null, $s = 200 ) { $this->d = $d; } function header( $k, $v ) { $this->h[ $k ] = $v; } function get_headers() { return $this->h; } function set_headers( $h ) { $this->h = $h; } function get_data() { return $this->d; } }
}

$fails = array();
$ran   = 0;
function ts( $label, $got, $want ) {
    global $fails, $ran;
    $ran++;
    if ( $got !== $want ) { $fails[] = $label . ': got ' . json_encode( $got ) . ', wanted ' . json_encode( $want ); }
}

/* Three tags: two live, one archived, and an old slug for the first. */
$GLOBALS['kit_tax_terms']['uc_tag'] = array();
foreach ( array( 21 => array( 'Food provided', 'food' ), 22 => array( 'Giveaways', 'giveaways' ), 23 => array( 'Interpretation', 'interpretation' ) ) as $id => $nt ) {
    $t = new WP_Term(); $t->term_id = $id; $t->term_taxonomy_id = $id; $t->name = $nt[0]; $t->slug = $nt[1]; $t->taxonomy = 'uc_tag';
    $GLOBALS['kit_tax_terms']['uc_tag'][] = $t;
}
SFAF_Tags::set_retired( 23, true );
$GLOBALS['kit_options'][ SFAF_Slug_Aliases::OPTION ] = array( 'uc_tag' => array( 'free-food' => 21 ) );

function ts_slug( $id ) {
    foreach ( $GLOBALS['kit_tax_terms']['uc_tag'] as $t ) { if ( (int) $t->term_id === (int) $id ) { return $t->slug; } }
    return ( 11 === (int) $id ) ? 'alpha' : 'beta';
}
function ts_query( $a ) {
    $out = array();
    foreach ( $GLOBALS['kit_posts'] as $id => $p ) {
        if ( 'uc_event' !== $p->post_type || 'publish' !== $p->post_status ) { continue; }
        if ( ! empty( $a['post__in'] ) && ! in_array( (int) $id, array_map( 'intval', (array) $a['post__in'] ), true ) ) { continue; }
        $ok = true;
        foreach ( isset( $a['tax_query'] ) ? (array) $a['tax_query'] : array() as $c ) {
            if ( ! is_array( $c ) || empty( $c['taxonomy'] ) ) { continue; }
            $have  = isset( $GLOBALS['kit_terms'][ $id ][ $c['taxonomy'] ] ) ? $GLOBALS['kit_terms'][ $id ][ $c['taxonomy'] ] : array();
            $slugs = array_map( 'ts_slug', $have );
            $want  = (array) $c['terms'];
            $hit   = ( 'slug' === ( isset( $c['field'] ) ? $c['field'] : 'term_id' ) )
                ? (bool) array_intersect( $want, $slugs )
                : (bool) array_intersect( array_map( 'intval', $want ), array_map( 'intval', $have ) );
            if ( ! $hit ) { $ok = false; }
        }
        if ( $ok ) { $out[] = $p; }
    }
    usort( $out, function ( $x, $y ) { return strcmp( (string) get_post_meta( $x->ID, '_uc_event_date', true ), (string) get_post_meta( $y->ID, '_uc_event_date', true ) ); } );
    if ( isset( $a['fields'] ) && 'ids' === $a['fields'] ) { $out = wp_list_pluck( $out, 'ID' ); }
    return $out;
}
$GLOBALS['kit_query'] = 'ts_query'; $GLOBALS['kit_get_posts'] = 'ts_query'; $GLOBALS['kit_loop'] = true;

$titles = array( 'Dinner club' => array( 21 ), 'Swag table' => array( 22 ), 'Signed talk' => array( 23 ), 'Plain walk' => array() );
$i = 0;
foreach ( $titles as $title => $tags ) {
    $id = kit_write_post( array( 'post_type' => 'uc_event', 'post_status' => 'publish', 'post_title' => $title, 'post_author' => 1, 'post_content' => '<p>Words.</p>' ) );
    foreach ( array( '_uc_event_date' => date( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' +' . ( 2 + $i++ ) . ' days' ) ), '_uc_start_time' => '18:00', '_uc_end_time' => '19:30' ) as $k => $v ) { update_post_meta( $id, $k, $v ); }
    $GLOBALS['kit_terms'][ $id ]['uc_tag'] = $tags;
    $GLOBALS['kit_terms'][ $id ]['uc_event_category'] = array( 11 );
}

$embed = new SFAF_Embed( new SFAF_Shortcodes() );
/** Which of the four events a payload shows, in each mode. */
function ts_shown( $embed, $mode, $tag, $active ) {
    $GLOBALS['kit_trans'] = array();
    $p = array( 'mode' => $mode, 'view' => 'calendar' === $mode ? 'calendar' : 'list', 'tag' => $tag, 'active_tags' => $active, 'per_page' => 20 );
    if ( 'month' === $mode ) { $p['month'] = date( 'Y-m', strtotime( current_time( 'Y-m-d' ) . ' +3 days' ) ); $p['view'] = 'calendar'; }
    $data = $embed->handle_request( new WP_REST_Request( $p ) )->get_data();
    $html = isset( $data['html'] ) ? (string) $data['html'] : '';
    $out  = array();
    foreach ( array( 'Dinner club', 'Swag table', 'Signed talk', 'Plain walk' ) as $t ) {
        if ( false !== strpos( $html, $t ) ) { $out[] = $t; }
    }
    return $out;
}

$cases = array(
    /* 1 */ array( '1: scoped to food',                          'food',                 '',                array( 'Dinner club' ) ),
    /* 2 */ array( '2: food scope, food chosen',                 'food',                 'food',            array( 'Dinner club' ) ),
    /* 2 */ array( '2: food scope, giveaways chosen (outside)',  'food',                 'giveaways',       array( 'Dinner club' ) ),
    /* 2 */ array( '2: two-tag scope, one chosen',               'food,giveaways',       'giveaways',       array( 'Swag table' ) ),
    /* 2 */ array( '2: two-tag scope, inside and outside',       'food',                 'giveaways,food',  array( 'Dinner club' ) ),
    /* 2 */ array( '2: no scope, giveaways chosen',              '',                     'giveaways',       array( 'Swag table' ) ),
    /* 3 */ array( '3: no scope, archived chosen',               '',                     'interpretation',  array( 'Dinner club', 'Swag table', 'Signed talk', 'Plain walk' ) ),
    /* 3 */ array( '3: food scope, archived chosen',             'food',                 'interpretation',  array( 'Dinner club' ) ),
    /* 3 */ array( '3: food and archived scope, archived chosen','food,interpretation',  'interpretation',  array( 'Dinner club', 'Signed talk' ) ),
    /* 4 */ array( '4: old slug as the scope',                   'free-food',            '',                array( 'Dinner club' ) ),
    /* 4 */ array( '4: old slug as the choice',                  '',                     'free-food',       array( 'Dinner club' ) ),
    /* 4 */ array( '4: old slug chosen inside a two-tag scope',  'food,giveaways',       'free-food',       array( 'Dinner club' ) ),
    /* 4 */ array( '4: old slug scope, giveaways chosen',        'free-food',            'giveaways',       array( 'Dinner club' ) ),
    /* deleted, as item 5d leaves it: an unknown slug */
    array( 'an unknown slug chosen reaches nothing',             '',                     'gone-tag',        array( 'Dinner club', 'Swag table', 'Signed talk', 'Plain walk' ) ),
);
foreach ( array( 'items', 'block', 'month' ) as $mode ) {
    foreach ( $cases as $c ) {
        ts( "$mode, {$c[0]}", ts_shown( $embed, $mode, $c[1], $c[2] ), $c[3] );
    }
}

echo "\nTag scope through the embed route, in the items, block and month modes\n" . str_repeat( '=', 72 ) . "\n";
echo "ran $ran assertions: a scope, a choice inside and outside it, an archived tag, an old slug.\n";
if ( $fails ) { echo "\n" . count( $fails ) . " failed:\n  - " . implode( "\n  - ", $fails ) . "\n"; exit( 1 ); }
echo "the address narrows a tag-scoped block and never widens it.\n";
exit( 0 );
