<?php
/**
 * 3.143.1: THE SERIES FILTER, THE ANNOUNCEMENT COLORS, THE CELL'S BAR AND
 * THE GRID'S HYPHENS.
 *
 *     php .claude/release-31431-test.php
 *
 * REAL: SFAF_Shortcodes' two query builders, available_groups(), who_counts()
 * and the month grid, SFAF_Series, through wp-kit.php, the queries answered by
 * series-filter-kit.php; calendar.css read as text and measured.
 *
 *   Q  World AIDS Day chosen: the list and the month grid show its own event
 *      and the Cycle to Zero event also shown in it; not the private, draft or
 *      hidden cancelled ones also shown in it; the list not the past one, and
 *      the grid the past one exactly as it shows World AIDS Day's own past day.
 *      Cycle to Zero chosen: its own two, not World AIDS Day's. Two chosen: both.
 *      With nothing shown that way, the clause is the taxonomy one it was.
 *   S  a block scoped to World AIDS Day is unchanged: its own events only.
 *   G  a series reached only through "Also show" is offered, and choosing it
 *      is kept by the clamp; the group counts count the event in it.
 *   L  every grid title carries its event's language: es for Transformaciones,
 *      en for the rest.
 *   K  the bar: Yellow's tint #FFFAE0 under 1px of Yellow's ink #705F00, Dark
 *      Gray words, hover #FFF8D1, all three from sfaf_category_shades(); the
 *      words over 4.5:1 on both fills, the edge over 3:1 on white; the cell's
 *      bar over the td's padding; the strip and the banner the same colors;
 *      hyphens: auto on the grid's titles.
 *
 * Browser: release-31431-live.php. PLANT in plant-31431.php.
 */

require __DIR__ . '/wp-kit.php';
require __DIR__ . '/series-query-kit.php';
require __DIR__ . '/series-filter-kit.php';
if ( ! function_exists( 'wpautop' ) ) { function wpautop( $s ) { return $s; } }
if ( ! function_exists( 'get_post_stati' ) ) { function get_post_stati( $a = array() ) { return array( 'publish' => 1, 'pending' => 1, 'draft' => 1, 'future' => 1, 'private' => 1, 'trash' => 1 ); } }
if ( ! function_exists( 'wp_trim_words' ) ) { function wp_trim_words( $t, $n = 55, $more = '' ) { return implode( ' ', array_slice( preg_split( '/\s+/', trim( (string) $t ) ), 0, $n ) ); } }
if ( ! function_exists( 'force_balance_tags' ) ) { function force_balance_tags( $t ) { return $t; } }

$fails = array();
$n     = 0;
function rt( $why, $ok ) { global $fails, $n; $n++; if ( ! $ok ) { $fails[] = $why; } }

$e  = sf_world();
$sc = new SFAF_Shortcodes();
$names = array_flip( $e );
$who   = function ( $ids ) use ( $names ) { return array_map( function ( $id ) use ( $names ) { return isset( $names[ $id ] ) ? $names[ $id ] : (string) $id; }, array_values( $ids ) ); };

/* The list's query, as render_events() runs it. */
$list = function ( $groups, $extra = array() ) use ( $sc ) {
    $args = kit_call( 'SFAF_Shortcodes', 'build_query_args', $sc, array( 0, 1, array_merge( array( 'groups' => $groups ), $extra ) ) );
    $args['fields'] = 'ids';
    return array( sf_query( $args ), $args );
};
/* The month grid's events, every day, in order. */
$month = function ( $groups, $extra = array() ) use ( $sc ) {
    $d   = $sc->month_grid_data( current_time( 'Y-m' ), array_merge( array( 'groups' => $groups ), $extra ) );
    $ids = array();
    foreach ( $d['events'] as $day => $day_ids ) { foreach ( $day_ids as $id ) { $ids[] = (int) $id; } }
    return $ids;
};
$same = function ( $a, $b ) { sort( $a ); sort( $b ); return $a === $b; };

/* ---- Q ---- */
list( $l, $largs ) = $list( 'world-aids-day' );
rt( 'Q: World AIDS Day\'s list is ' . json_encode( $who( $l ) ) . ', not the vigil and the ride', $same( $l, array( $e['vigil'], $e['ride'] ) ) );
rt( 'Q: the list\'s clause is not a list of ids once an event is shown in World AIDS Day', ! empty( $largs['post__in'] ) );
$m = $month( 'world-aids-day' );
foreach ( array( 'vigil', 'ride' ) as $k ) { rt( "Q: World AIDS Day's month grid does not show the $k", in_array( $e[ $k ], $m, true ) ); }
foreach ( array( 'private', 'draft', 'hidden', 'social', 'alpha', 'spanish' ) as $k ) { rt( "Q: World AIDS Day's month grid shows the $k", ! in_array( $e[ $k ], $m, true ) ); }
rt( 'Q: the month grid treats the past event shown in World AIDS Day unlike its own past one: ' . json_encode( $who( $m ) ),
    in_array( $e['past'], $m, true ) === in_array( $e['pastown'], $m, true ) );
list( $l, ) = $list( 'cycle-to-zero' );
rt( 'Q: Cycle to Zero\'s list is ' . json_encode( $who( $l ) ), $same( $l, array( $e['ride'], $e['social'] ) ) );
list( $l, ) = $list( 'alpha,world-aids-day' );
rt( 'Q: Alpha and World AIDS Day\'s list is ' . json_encode( $who( $l ) ), $same( $l, array( $e['alpha'], $e['spanish'], $e['vigil'], $e['ride'] ) ) );
list( $l, ) = $list( 'world-aids-day', array( 'category' => 'beta' ) );
rt( 'Q: a category beside the series is not still applied: ' . json_encode( $who( $l ) ), array() === $l );

/* Nothing shown in Alpha that way: the clause it always was. */
list( $l, $largs ) = $list( 'alpha' );
$tax = isset( $largs['tax_query'] ) ? $largs['tax_query'] : array();
rt( 'Q: with no event shown in Alpha, its clause is not the taxonomy clause: ' . json_encode( $largs ),
    empty( $largs['post__in'] ) && in_array( array( 'taxonomy' => 'uc_series', 'field' => 'slug', 'terms' => array( 'alpha' ) ), $tax, true ) );
rt( 'Q: Alpha\'s list is ' . json_encode( $who( $l ) ), $same( $l, array( $e['alpha'], $e['spanish'] ) ) );

/* ---- S: a block scoped to World AIDS Day, unchanged ---- */
list( $l, ) = $list( '', array( 'series' => 14 ) );
rt( 'S: a block scoped to World AIDS Day lists ' . json_encode( $who( $l ) ) . ', not its own event only', $same( $l, array( $e['vigil'] ) ) );

/* ---- G: offered, kept, counted ---- */
$groups = $sc->available_groups( array() );
$slugs  = wp_list_pluck( $groups, 'slug' );
rt( 'G: the series offered are ' . json_encode( $slugs ), array( 'alpha', 'cycle-to-zero', 'world-aids-day' ) === $slugs );
$GLOBALS['kit_posts'][ $e['vigil'] ]->post_status = 'draft';
$groups = $sc->available_groups( array() );
rt( 'G: World AIDS Day, reached only through the ride, is not offered: ' . json_encode( wp_list_pluck( $groups, 'slug' ) ), in_array( 'world-aids-day', wp_list_pluck( $groups, 'slug' ), true ) );
rt( 'G: choosing World AIDS Day is dropped by the clamp', 'world-aids-day' === $sc->clamp_groups( array(), 'world-aids-day' ) );
$GLOBALS['kit_posts'][ $e['vigil'] ]->post_status = 'publish';
$counts = kit_call( 'SFAF_Shortcodes', 'who_counts', $sc, array( array(), $sc->available_groups( array() ), array( 'organizer' => '' ) ) );
rt( 'G: World AIDS Day counts ' . json_encode( $counts['group'] ) . ', not the vigil and the ride', isset( $counts['group']['world-aids-day'] ) && 2 === (int) $counts['group']['world-aids-day'] );

/* ---- L: the language on each title ---- */
$b    = $sc->render_calendar_block( array( 'view' => 'calendar', 'show_filters' => 'no' ) );
$html = is_array( $b ) ? (string) $b['html'] : (string) $b;
preg_match_all( '#<span class="uc-day-event-title" lang="([a-z]*)">([^<]*)</span>#', $html, $mm, PREG_SET_ORDER );
$langs = array();
foreach ( $mm as $x ) { $langs[ $x[2] ] = $x[1]; }
rt( 'L: no grid title carries a language: ' . substr( $html, 0, 200 ), count( $langs ) > 0 );
rt( 'L: Transformaciones is lang="' . ( isset( $langs['Transformaciones'] ) ? $langs['Transformaciones'] : '?' ) . '", not es', isset( $langs['Transformaciones'] ) && 'es' === $langs['Transformaciones'] );
foreach ( $langs as $t => $lg ) { if ( 'Transformaciones' !== $t ) { rt( "L: $t is lang=\"$lg\", not en", 'en' === $lg ); } }
rt( 'L: a grid title has no lang at all', 0 === substr_count( $html, '<span class="uc-day-event-title">' ) );

/* ---- K: calendar.css ---- */
$css = (string) file_get_contents( dirname( __DIR__ ) . '/public/css/calendar.css' );
$rule = function ( $sel ) use ( $css ) { return preg_match( '/(?:^|\})\s*' . preg_quote( $sel, '/' ) . '\s*\{([^}]*)\}/m', $css, $x ) ? $x[1] : ''; };
$bar   = $rule( '.uc-calendar .uc-ann-bar, .uc-sidebar .uc-ann-bar' );
$hover = $rule( ".uc-calendar button.uc-ann-bar:hover, .uc-sidebar button.uc-ann-bar:hover,\n.uc-calendar button.uc-ann-bar[aria-expanded=\"true\"], .uc-sidebar button.uc-ann-bar[aria-expanded=\"true\"]" );
rt( 'K: the bar is not Yellow\'s tint #FFFAE0 under 1px of #705F00 with Dark Gray words: ' . trim( $bar ),
    false !== strpos( $bar, 'border: 1px solid #705F00;' ) && false !== strpos( $bar, 'background: #FFFAE0; color: var(--uc-dark);' ) );
rt( 'K: the bar\'s hover is not #FFF8D1 with Dark Gray words: ' . trim( $hover ), false !== strpos( $hover, 'background: #FFF8D1; color: var(--uc-dark);' ) );
rt( 'K: the bar still names the yellow ink or the dark face', false === strpos( $bar . $hover, 'var(--uc-yellow)' ) && false === strpos( $bar . $hover, '#24211F' ) );
$shades = sfaf_category_shades( '#FFD900' );
rt( 'K: the three are not Yellow\'s approved stops: ' . json_encode( $shades ), array( '#FFFAE0', '#FFF8D1', '#705F00' ) === array( strtoupper( $shades['tint'] ), strtoupper( $shades['media'] ), strtoupper( $shades['ink'] ) ) );
$lum = function ( $h ) { $h = ltrim( $h, '#' ); $c = array(); foreach ( array( 0, 2, 4 ) as $i ) { $v = hexdec( substr( $h, $i, 2 ) ) / 255; $c[] = $v <= 0.03928 ? $v / 12.92 : pow( ( $v + 0.055 ) / 1.055, 2.4 ); } return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2]; };
$ratio = function ( $a, $b ) use ( $lum ) { $x = $lum( $a ); $y = $lum( $b ); return round( ( max( $x, $y ) + 0.05 ) / ( min( $x, $y ) + 0.05 ), 2 ); };
rt( 'K: Dark Gray on #FFFAE0 measures ' . $ratio( '#373433', '#FFFAE0' ), $ratio( '#373433', '#FFFAE0' ) >= 4.5 );
rt( 'K: Dark Gray on #FFF8D1 measures ' . $ratio( '#373433', '#FFF8D1' ), $ratio( '#373433', '#FFF8D1' ) >= 4.5 );
rt( 'K: the edge #705F00 on white measures ' . $ratio( '#705F00', '#FFFFFF' ), $ratio( '#705F00', '#FFFFFF' ) >= 3 );
rt( 'K: the cell\'s bars do not run over the td\'s 5px padding', '' !== $rule( '.uc-calendar td .uc-ann-bars' ) && false !== strpos( $rule( '.uc-calendar td .uc-ann-bars' ), 'margin: 0 -5px 4px;' )
    && (bool) preg_match( '/\.uc-calendar \.uc-month-grid td \{[^}]*padding: 5px;/', $css ) );
$strips = preg_match_all( '/\.uc-calendar \.uc-ann-strip \{ display: block;[^}]*border: 1px solid #705F00;[^}]*background: #FFFAE0; \}/', $css );
rt( "K: $strips of the strip's two copies are the bar's colors", 2 === $strips );
rt( 'K: a strip is still dark', false === strpos( $css, 'uc-ann-strip { display: block; height: 4px; margin: 0 3px 3px; border-radius: 2px; background: var(--uc-dark); }' ) );
$banner = $rule( '.uc-calendar .uc-ann-banner' );
rt( 'K: the banner sets its own colors: ' . $banner, false === stripos( $banner, 'background' ) && false === stripos( $banner, 'color' ) && false === stripos( $banner, 'border:' ) );
rt( 'K: the grid\'s titles do not hyphenate', (bool) preg_match( '/\n\.uc-day-event-title \{[^}]*\bhyphens: auto;/', $css ) );

echo "\n3.143.1: the series filter, the announcement colors, the cell's bar and the grid's hyphens\n" . str_repeat( '=', 72 ) . "\n";
if ( $fails ) { echo count( $fails ) . " of $n FAILED:\n  - " . implode( "\n  - ", $fails ) . "\n"; exit( 1 ); }
echo "$n checks passed.\n";
exit( 0 );
