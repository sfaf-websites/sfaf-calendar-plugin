<?php
/**
 * 3.145.0: AN EMBED SCOPED TO A SERIES SHOWS THE EVENTS ALSO SHOWN IN IT, AND
 * THE GRID'S LONG WORDS CARRY SOFT HYPHENS.
 *
 *     php .claude/release-31450-test.php
 *
 * REAL: SFAF_Shortcodes' two query builders and render_calendar_block(),
 * SFAF_Embed::handle_request(), SFAF_Series, sfaf_soft_hyphens(), through
 * wp-kit.php, the queries answered by series-filter-kit.php.
 *
 *   S  series="14" (World AIDS Day): the list and the month grid show its own
 *      vigil and the Cycle to Zero ride also shown in it; not the private,
 *      draft or hidden cancelled ones also shown in it, nor Cycle to Zero's
 *      own; the list not the past one, and the grid the past one exactly as
 *      it shows World AIDS Day's own past day. series="13": its own two, not
 *      the vigil. A series nothing is shown in that way keeps the taxonomy
 *      clause it always had. A pin still comes out of a scoped list.
 *   E  the embed route, scoped to series 14, answers the list, the calendar,
 *      a month and the sidebar with the ride and without the four left out.
 *   H  sfaf_soft_hyphens(): Trans-for-ma-cio-nes and the rest at their
 *      syllables, short words, capitals and entities untouched, and the text
 *      the same once the soft hyphens are taken out.
 *   T  every grid title carries them, keeps its lang, and is otherwise the
 *      title it was; nothing else in the block carries one.
 *   K  calendar.css: the grid title's rule is 3.143.1's.
 *
 * Browser: release-31450-live.php. PLANT in plant-31450.php.
 */

require __DIR__ . '/wp-kit.php';
require __DIR__ . '/series-query-kit.php';
require __DIR__ . '/series-filter-kit.php';
if ( ! function_exists( 'wpautop' ) ) { function wpautop( $s ) { return $s; } }
if ( ! function_exists( 'get_post_stati' ) ) { function get_post_stati( $a = array() ) { return array( 'publish' => 1, 'pending' => 1, 'draft' => 1, 'future' => 1, 'private' => 1, 'trash' => 1 ); } }
if ( ! function_exists( 'wp_trim_words' ) ) { function wp_trim_words( $t, $n = 55, $more = '' ) { return implode( ' ', array_slice( preg_split( '/\s+/', trim( (string) $t ) ), 0, $n ) ); } }
if ( ! function_exists( 'force_balance_tags' ) ) { function force_balance_tags( $t ) { return $t; } }
if ( ! function_exists( 'get_http_origin' ) ) { function get_http_origin() { return ''; } }

if ( ! class_exists( 'WP_REST_Request' ) ) {
    class WP_REST_Request { private $p; function __construct( $p ) { $this->p = $p; } function get_param( $k ) { return isset( $this->p[ $k ] ) ? $this->p[ $k ] : null; } function get_route() { return '/sfaf-calendar/v1/embed'; } function get_method() { return 'GET'; } function get_header( $k ) { return null; } }
}
if ( ! class_exists( 'WP_REST_Response' ) ) {
    class WP_REST_Response { private $d; private $h = array(); function __construct( $d = null, $s = 200 ) { $this->d = $d; } function header( $k, $v ) { $this->h[ $k ] = $v; } function get_headers() { return $this->h; } function set_headers( $h ) { $this->h = $h; } function get_data() { return $this->d; } }
}

$fails = array();
$n     = 0;
function rt( $why, $ok ) { global $fails, $n; $n++; if ( ! $ok ) { $fails[] = $why; } }

$SHY = "\xC2\xAD";
$e   = sf_world();
$sc  = new SFAF_Shortcodes();
$names = array_flip( $e );
$who   = function ( $ids ) use ( $names ) { return array_map( function ( $id ) use ( $names ) { return isset( $names[ $id ] ) ? $names[ $id ] : (string) $id; }, array_values( $ids ) ); };
$list  = function ( $extra ) use ( $sc ) {
    $args = kit_call( 'SFAF_Shortcodes', 'build_query_args', $sc, array( 0, 1, $extra ) );
    $args['fields'] = 'ids';
    return array( sf_query( $args ), $args );
};
$month = function ( $extra ) use ( $sc ) {
    $d   = $sc->month_grid_data( current_time( 'Y-m' ), $extra );
    $ids = array();
    foreach ( $d['events'] as $day => $day_ids ) { foreach ( $day_ids as $id ) { $ids[] = (int) $id; } }
    return $ids;
};
$same = function ( $a, $b ) { sort( $a ); sort( $b ); return $a === $b; };
$left_out = array( 'private', 'draft', 'hidden', 'social', 'alpha', 'spanish' );

/* ---- S ---- */
list( $l, $largs ) = $list( array( 'series' => 14 ) );
rt( 'S: a block scoped to World AIDS Day lists ' . json_encode( $who( $l ) ) . ', not the vigil and the ride', $same( $l, array( $e['vigil'], $e['ride'] ) ) );
rt( 'S: the scoped list\'s clause is not a list of ids', ! empty( $largs['post__in'] ) );
$m = $month( array( 'series' => 14 ) );
foreach ( array( 'vigil', 'ride' ) as $k ) { rt( "S: the scoped month grid does not show the $k", in_array( $e[ $k ], $m, true ) ); }
foreach ( $left_out as $k ) { rt( "S: the scoped month grid shows the $k", ! in_array( $e[ $k ], $m, true ) ); }
rt( 'S: the scoped month grid treats the past event shown in World AIDS Day unlike its own past one: ' . json_encode( $who( $m ) ),
    in_array( $e['past'], $m, true ) === in_array( $e['pastown'], $m, true ) );
list( $l, ) = $list( array( 'series' => 13 ) );
rt( 'S: a block scoped to Cycle to Zero lists ' . json_encode( $who( $l ) ), $same( $l, array( $e['ride'], $e['social'] ) ) );
list( $l, $largs ) = $list( array( 'series' => 11 ) );
$tax = isset( $largs['tax_query'] ) ? $largs['tax_query'] : array();
rt( 'S: a block scoped to Alpha, nothing shown in it that way, is not the taxonomy clause: ' . json_encode( $largs ),
    empty( $largs['post__in'] ) && in_array( array( 'taxonomy' => 'uc_series', 'field' => 'term_id', 'terms' => 11 ), $tax, true ) );
rt( 'S: Alpha\'s scoped list is ' . json_encode( $who( $l ) ), $same( $l, array( $e['alpha'], $e['spanish'] ) ) );
list( $l, ) = $list( array( 'series' => 14, 'category' => 'beta' ) );
rt( 'S: a category beside the scope is not still applied: ' . json_encode( $who( $l ) ), array() === $l );
list( $l, ) = $list( array( 'series' => 14, 'pin' => (string) $e['ride'] ) );
rt( 'S: a scoped list with the ride pinned lists it twice or not at all: ' . json_encode( $who( $l ) ), ! in_array( $e['ride'], $l, true ) || 1 === count( array_keys( $l, $e['ride'], true ) ) );
list( $l, ) = $list( array( 'series' => 0 ) );
rt( 'S: no scope lists ' . json_encode( $who( $l ) ) . ', not every public upcoming one', $same( $l, array( $e['vigil'], $e['ride'], $e['social'], $e['alpha'], $e['spanish'] ) ) );

/* ---- E: the embed route ---- */
$embed = new SFAF_Embed( new SFAF_Shortcodes() );
$base  = array( 'series' => 14, 'page' => 1, 'mode' => 'block', 'source_links' => 'no' );
$cases = array(
    'list'     => array( 'view' => 'list' ),
    'calendar' => array( 'view' => 'calendar' ),
    'month'    => array( 'view' => 'calendar', 'mode' => 'month', 'month' => current_time( 'Y-m' ) ),
    'sidebar'  => array( 'view' => 'sidebar', 'count' => 10 ),
);
foreach ( $cases as $name => $p ) {
    $GLOBALS['kit_trans'] = array();
    $r    = $embed->handle_request( new WP_REST_Request( array_merge( $base, $p ) ) );
    $d    = $r->get_data();
    $html = is_array( $d ) && isset( $d['html'] ) ? str_replace( $SHY, '', (string) $d['html'] ) : '';
    rt( "E: the embed's $name, scoped to World AIDS Day, does not show the vigil", false !== strpos( $html, 'World AIDS Day vigil' ) );
    rt( "E: the embed's $name, scoped to World AIDS Day, does not show the Cycle to Zero ride", false !== strpos( $html, 'Cycle to Zero ride' ) );
    foreach ( array( 'Cycle to Zero private', 'Cycle to Zero draft', 'Cycle to Zero hidden', 'Cycle to Zero social', 'Alpha meetup', 'Transformaciones' ) as $t ) {
        rt( "E: the embed's $name, scoped to World AIDS Day, shows \"$t\"", false === strpos( $html, $t ) );
    }
    // The list's own cards: a list answer carries the month grid beside it, which shows past days.
    preg_match_all( '#class="uc-card-title[^"]*">(.*?)</h3>#s', $html, $cards );
    if ( 'list' === $name ) {
        $cards = implode( ' ', $cards[1] );
        rt( "E: the embed's list cards do not show the ride: " . strip_tags( $cards ), false !== strpos( $cards, 'Cycle to Zero ride' ) );
        rt( "E: the embed's list cards show the past event", false === strpos( $cards, 'Cycle to Zero past' ) );
    }
}

/* ---- H ---- */
$cases = array(
    'Transformaciones'                  => 'Trans-for-ma-cio-nes',
    'Información'                       => 'Infor-ma-ción',
    'Celebración'                       => 'Cele-bra-ción',
    'Acompañamiento'                    => 'Acom-pa-ña-miento',
    'Coffee & Conversation'             => 'Coffee & Con-ver-sa-tion',
    'Opioid Overdose Prevention'        => 'Opioid Over-dose Pre-ven-tion',
    'Neighborhood'                      => 'Neigh-bor-hood',
    'Wednesday'                         => 'Wed-nes-day',
    'Alpha meetup'                      => 'Alpha meetup',
    'HIV/AIDS AWARENESS'                => 'HIV/AIDS AWARENESS',
    'PrEP Navigation'                   => 'PrEP Navi-ga-tion',
    'Abrazos &hearts; &#038; Bienvenidos' => 'Abrazos &hearts; &#038; Bien-ve-ni-dos',
    'Celebración &thetasym;'              => 'Cele-bra-ción &thetasym;',
    ''                                  => '',
);
foreach ( $cases as $in => $want ) {
    $got = sfaf_soft_hyphens( $in );
    rt( 'H: ' . json_encode( $in ) . ' is ' . json_encode( str_replace( $SHY, '-', $got ) ) . ', not ' . json_encode( $want ), str_replace( $SHY, '-', $got ) === $want );
    rt( 'H: ' . json_encode( $in ) . ' is not the same text once the soft hyphens are out', str_replace( $SHY, '', $got ) === $in );
}
foreach ( $cases as $in => $want ) {
    $pieces = explode( $SHY, sfaf_soft_hyphens( $in ) );
    if ( count( $pieces ) < 2 ) { continue; }
    rt( 'H: ' . json_encode( $in ) . ' breaks with fewer than 3 letters before the first break', (bool) preg_match( '/\p{L}{3}$/u', $pieces[0] ) );
    rt( 'H: ' . json_encode( $in ) . ' breaks with fewer than 3 letters after the last break', (bool) preg_match( '/^\p{L}{3}/u', end( $pieces ) ) );
}

/* ---- T: the grid's titles ---- */
$b    = $sc->render_calendar_block( array( 'view' => 'calendar', 'show_filters' => 'no' ) );
$html = is_array( $b ) ? (string) $b['html'] : (string) $b;
preg_match_all( '#<span class="uc-day-event-title" lang="([a-z]*)">([^<]*)</span>#u', $html, $mm, PREG_SET_ORDER );
rt( 'T: no grid title was found', count( $mm ) >= 6 );
$seen = array();
foreach ( $mm as $x ) {
    $clean = str_replace( $SHY, '', $x[2] );
    $seen[ $clean ] = $x;
    rt( "T: \"$clean\" does not carry its soft hyphens: " . json_encode( $x[2] ), $x[2] === esc_html( sfaf_soft_hyphens( $clean ) ) );
}
rt( 'T: Transformaciones is not Trans-for-ma-cio-nes in the grid: ' . json_encode( isset( $seen['Transformaciones'] ) ? $seen['Transformaciones'][2] : null ),
    isset( $seen['Transformaciones'] ) && "Trans{$SHY}for{$SHY}ma{$SHY}cio{$SHY}nes" === $seen['Transformaciones'][2] );
rt( 'T: Transformaciones lost lang="es"', isset( $seen['Transformaciones'] ) && 'es' === $seen['Transformaciones'][1] );
foreach ( $seen as $t => $x ) { if ( 'Transformaciones' !== $t ) { rt( "T: $t is lang=\"{$x[1]}\", not en", 'en' === $x[1] ); } }
rt( 'T: a grid title has no lang at all, or another attribute', 0 === preg_match( '#<span class="uc-day-event-title"(?! lang="[a-z]+">)#', $html ) );
$rest = preg_replace( '#<span class="uc-day-event-title" lang="[a-z]*">[^<]*</span>#u', '', $html );
rt( 'T: something other than a grid title carries a soft hyphen', false === strpos( $rest, $SHY ) && false === stripos( $rest, '&shy;' ) );
$lb   = $sc->render_calendar_block( array( 'view' => 'list', 'show_filters' => 'no', 'count' => 20 ) );
$lhtml = is_array( $lb ) ? (string) $lb['html'] : (string) $lb;
preg_match_all( '#class="uc-card-title[^"]*">(.*?)</h3>#s', $lhtml, $lc );
$lc = implode( ' ', $lc[1] );
rt( 'T: a list card\'s title carries a soft hyphen, or Transformaciones is not on one: ' . json_encode( strip_tags( $lc ) ), false === strpos( $lc, $SHY ) && false !== strpos( $lc, 'Transformaciones' ) );

/* ---- K ---- */
$css = (string) file_get_contents( dirname( __DIR__ ) . '/public/css/calendar.css' );
rt( 'K: the grid title\'s rule changed', (bool) preg_match( "/\n\\.uc-day-event-title \\{\n    display: block;\n    overflow-wrap: anywhere;\n    -webkit-hyphens: auto; hyphens: auto;\n    font-weight: 600;\n\\}/", $css ) );

echo "\n3.145.0: a series-scoped embed shows the events also shown in it; the grid's soft hyphens\n" . str_repeat( '=', 72 ) . "\n";
if ( $fails ) { echo count( $fails ) . " of $n FAILED:\n  - " . implode( "\n  - ", $fails ) . "\n"; exit( 1 ); }
echo "$n checks passed.\n";
exit( 0 );
