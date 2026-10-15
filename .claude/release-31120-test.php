<?php
/**
 * 3.112.0, THE PARTS A TEST CAN SETTLE WITHOUT A BROWSER.
 *
 *     php .claude/release-31120-test.php
 *
 * Through wp-kit.php, which loads the whole plugin, with a query model that
 * honours the clauses these features add (a tag, a venue, post__in, the
 * status and the author), so a filter that does nothing fails here rather
 * than passing on a world that ignores it.
 *
 *   A   tags: retired leave the pickers and the filter; the series default;
 *       the pills and their Spanish name; the editor's field
 *   B   Location: venues and one-off addresses, the filter, See all events
 *   C   Multi-day: the range in the one formatter, every day on the grid,
 *       the publish rule
 *   D   closed days: the rows, one email per closure, the reminder once, and
 *       a link that never acts on GET
 *   E   Drafts and Scheduled: the person's own, nobody else's
 *   F   Replies go to: calendar users and Someone else
 *
 * PLANT A.3, PLANT B, PLANT C.2, PLANT D.1 and PLANT E name the assertions
 * plant-31120.php makes fail.
 */

require __DIR__ . '/wp-kit.php';
if ( ! function_exists( 'wpautop' ) ) { function wpautop( $s ) { return $s; } }
if ( ! function_exists( 'nocache_headers' ) ) { function nocache_headers() {} }
if ( ! function_exists( 'get_post_stati' ) ) { function get_post_stati( $a = array() ) { return array( 'publish' => 1, 'draft' => 1, 'future' => 1 ); } }
if ( ! function_exists( 'wp_trim_words' ) ) { function wp_trim_words( $t, $n = 55, $m = '' ) { return implode( ' ', array_slice( preg_split( '/\s+/', trim( (string) $t ) ), 0, $n ) ); } }
if ( ! function_exists( 'force_balance_tags' ) ) { function force_balance_tags( $t ) { return $t; } }
$GLOBALS['t_mail'] = array();
if ( ! function_exists( 'wp_mail' ) ) {
    function wp_mail( $to, $subject, $body, $headers = '', $att = array() ) { $GLOBALS['t_mail'][] = array( $to, $subject, $body ); return true; }
}

$fails = array();
$ran   = 0;
function t( $label, $got, $want ) {
    global $fails, $ran;
    $ran++;
    if ( $got !== $want ) { $fails[] = $label . ': got ' . var_export( $got, true ) . ', wanted ' . var_export( $want, true ); }
}
function t_cap( $fn ) {
    $d = ob_get_level(); ob_start();
    try { $fn(); } catch ( Throwable $e ) { while ( ob_get_level() > $d ) { ob_end_clean(); } return 'THREW ' . $e->getMessage() . ' at ' . basename( $e->getFile() ) . ':' . $e->getLine(); }
    $h = ''; while ( ob_get_level() > $d ) { $h = ob_get_clean() . $h; } return $h;
}

/* ---- The query model. ------------------------------------------------------ */
$GLOBALS['t_last_args'] = array();
function t_query( $a ) {
    $GLOBALS['t_last_args'][] = $a;
    $type   = isset( $a['post_type'] ) ? $a['post_type'] : 'uc_event';
    $status = isset( $a['post_status'] ) ? (array) $a['post_status'] : array( 'publish' );
    $out    = array();
    foreach ( $GLOBALS['kit_posts'] as $id => $p ) {
        if ( $type !== $p->post_type || ! in_array( $p->post_status, $status, true ) ) { continue; }
        if ( ! empty( $a['author'] ) && (int) $p->post_author !== (int) $a['author'] ) { continue; }
        if ( ! empty( $a['post__in'] ) && ! in_array( (int) $id, array_map( 'intval', (array) $a['post__in'] ), true ) ) { continue; }
        if ( ! empty( $a['post__not_in'] ) && in_array( (int) $id, array_map( 'intval', (array) $a['post__not_in'] ), true ) ) { continue; }
        $ok = true;
        foreach ( isset( $a['tax_query'] ) ? (array) $a['tax_query'] : array() as $c ) {
            if ( ! is_array( $c ) || empty( $c['taxonomy'] ) ) { continue; }
            $have  = isset( $GLOBALS['kit_terms'][ $id ][ $c['taxonomy'] ] ) ? $GLOBALS['kit_terms'][ $id ][ $c['taxonomy'] ] : array();
            $slugs = array();
            foreach ( $have as $tid ) { $slugs[] = ( 11 === (int) $tid ) ? 'alpha' : 'beta'; }
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
$GLOBALS['kit_query'] = 't_query'; $GLOBALS['kit_get_posts'] = 't_query'; $GLOBALS['kit_loop'] = true;

function t_event( $title, $date, $meta = array(), $terms = array(), $status = 'publish', $author = 1 ) {
    $id = kit_write_post( array( 'post_type' => 'uc_event', 'post_status' => $status, 'post_title' => $title, 'post_author' => $author, 'post_content' => '<p>Words.</p>' ) );
    foreach ( array_merge( array( '_uc_event_date' => $date, '_uc_start_time' => '18:00', '_uc_end_time' => '19:30' ), $meta ) as $k => $v ) { update_post_meta( $id, $k, $v ); }
    foreach ( $terms as $tax => $ids ) { $GLOBALS['kit_terms'][ $id ][ $tax ] = $ids; }
    return $id;
}
$soon = function ( $days ) { return date( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' +' . $days . ' days' ) ); };

/* ======================================================================== A */
// Alpha (11) is a live tag; Beta (12) is retired.
SFAF_Tags::set_retired( 12, true );
t( 'A: a retired tag is not offered', wp_list_pluck( SFAF_Tags::active(), 'slug' ), array( 'alpha' ) );
t( 'A: every tag is still listed for the Tags section', wp_list_pluck( SFAF_Tags::all(), 'slug' ), array( 'alpha', 'beta' ) );

$a_food = t_event( 'Dinner club', $soon( 3 ), array(), array( 'uc_tag' => array( 11 ), 'uc_event_category' => array( 11 ) ) );
$a_old  = t_event( 'Old night', $soon( 4 ), array(), array( 'uc_tag' => array( 12 ), 'uc_event_category' => array( 11 ) ) );

$sc    = new SFAF_Shortcodes();
$block = t_cap( function () use ( $sc ) { $b = $sc->render_calendar_block( array( 'view' => 'list', 'filters' => 'tag', 'toggle' => 'no' ) ); echo $b['html']; } );
t( 'A.3: the What\'s included dropdown is drawn', false !== strpos( $block, 'data-uc-pick="tag"' ), true );
t( 'A.3: it offers the live tag', false !== strpos( $block, 'name="uc_tag[]" value="alpha"' ), true );
t( 'PLANT A.3: a retired tag is in the filter', false !== strpos( $block, 'name="uc_tag[]" value="beta"' ), false );

$_GET  = array( 'uc_tag' => 'beta' );
$block = t_cap( function () use ( $sc ) { $b = $sc->render_calendar_block( array( 'view' => 'list', 'filters' => 'tag', 'toggle' => 'no' ) ); echo $b['html']; } );
$_GET  = array();
t( 'PLANT A.3: a retired tag in the address filters the calendar', (bool) preg_match( '/data-filter-tag="beta"/', $block ), false );

$_GET  = array( 'uc_tag' => 'alpha' );
$block = t_cap( function () use ( $sc ) { $b = $sc->render_calendar_block( array( 'view' => 'list', 'filters' => 'tag', 'toggle' => 'no' ) ); echo $b['html']; } );
$_GET  = array();
t( 'A.3: uc_tag narrows the list to its events', array( false !== strpos( $block, 'Dinner club' ), false !== strpos( $block, 'Old night' ) ), array( true, false ) );

update_term_meta( 11, SFAF_Tags::META_SERIES_DEFAULT, array( 11 ) );
$a_join = t_event( 'Joins a series', $soon( 5 ) );
$fill   = SFAF_Series::defaults_for_event( $a_join, 11 );
t( 'A.2: a series default fills an event with no tags', isset( $fill['tag'] ) ? $fill['tag'] : null, array( 11 ) );
$GLOBALS['kit_terms'][ $a_join ][ SFAF_Tags::TAXONOMY ] = array( 12 );
$fill   = SFAF_Series::defaults_for_event( $a_join, 11 );
t( 'A.2: an event with its own tag keeps it', isset( $fill['tag'] ), false );

$pills = SFAF_Tags::pills_html( $a_food, 'page' );
t( 'A.3: a pill links to the calendar filtered to its tag', (bool) preg_match( '#class="uc-tag-pill" href="[^"]*uc_tag=alpha"#', $pills ), true );
t( 'A.4: the pills are named with the public string', false !== strpos( $pills, 'aria-label="What&#039;s included"' ), true );
update_post_meta( $a_food, '_uc_language', 'es' );
t( 'A.4: in Spanish', false !== strpos( SFAF_Tags::pills_html( $a_food, 'page' ), 'aria-label="Qué incluye"' ), true );
delete_post_meta( $a_food, '_uc_language' );

$portal = ( new ReflectionClass( 'SFAF_Portal' ) )->newInstanceWithoutConstructor();
$editor = t_cap( function () use ( $portal, $a_old ) { kit_call( 'SFAF_Portal', 'render_event_form', $portal, array( new WP_User(), $a_old ) ); } );
t( 'A.2: the editor offers What\'s included', false !== strpos( $editor, 'name="uc_tag_present"' ), true );
t( 'A.2: a retired tag the event holds is offered, ticked', (bool) preg_match( '#name="tag\[\]" value="12"\s+checked#', $editor ), true );

/* ======================================================================== B */
foreach ( array_keys( $GLOBALS['kit_posts'] ) as $pid ) { wp_update_post( array( 'ID' => $pid, 'post_status' => 'draft' ) ); }
$b_venue = t_event( 'At the venue', $soon( 6 ), array(), array( 'uc_venue' => array( 11 ) ) );
$b_park  = t_event( 'In the park', $soon( 7 ), array( '_uc_location' => 'Dolores Park, San Francisco', '_uc_location_name' => 'Dolores Park' ) );
$block = t_cap( function () use ( $sc ) { $b = $sc->render_calendar_block( array( 'view' => 'list', 'filters' => 'location', 'toggle' => 'no' ) ); echo $b['html']; } );
$park_key = SFAF_Shortcodes::location_key_for_event( $b_park );
t( 'B: a venue is a location', false !== strpos( $block, 'name="uc_loc[]" value="v-alpha"' ), true );
t( 'B: a one-off address is a location', false !== strpos( $block, 'name="uc_loc[]" value="' . $park_key . '"' ), true );
t( 'B: a one-off reads by its name', false !== strpos( $block, 'Dolores Park' ), true );
$block = t_cap( function () use ( $sc ) { $b = $sc->render_calendar_block( array( 'view' => 'list', 'filters' => 'location', 'toggle' => 'no', 'active_locations' => 'v-alpha' ) ); echo $b['html']; } );
t( 'B: a location narrows the list', array( false !== strpos( $block, 'At the venue' ), false !== strpos( $block, 'In the park' ) ), array( true, false ) );
$GLOBALS['kit_options']['uc_settings'] = array_merge( (array) get_option( 'uc_settings', array() ), array( 'calendar_home_url' => 'https://www.sfaf.org/calendar/' ) );
$side = $sc->render_sidebar( array( 'venue' => 'alpha' ), 5 );
preg_match( '#class="uc-sidebar-all" href="([^"]*)"#', $side, $m );
t( 'PLANT B: See all events carries the venue', isset( $m[1] ) && false !== strpos( html_entity_decode( $m[1] ), 'uc_loc=v-alpha' ), true );

/* ======================================================================== C */
t( 'C.2: a range in one month', sfaf_ap_range( '2027-05-21', '2027-05-23' ), 'May 21 to 23, 2027' );
t( 'C.2: a range across months', sfaf_ap_range( '2027-04-30', '2027-05-02' ), 'Apr 30 to May 2, 2027' );
t( 'C.2: a range across years', sfaf_ap_range( '2026-12-30', '2027-01-02' ), 'Dec 30, 2026 to Jan 2, 2027' );
t( 'C.2: in Spanish', sfaf_ap_range( '2027-05-21', '2027-05-23', 'es' ), 'del 21 al 23 de mayo de 2027' );

$c_first = date( 'Y-m', strtotime( current_time( 'Y-m-d' ) . ' +1 month' ) ) . '-10';
$c_last  = date( 'Y-m-d', strtotime( $c_first . ' +2 days' ) );
$c_id    = t_event( 'Three-day retreat', $c_first, array( '_uc_multi_day' => '1', '_uc_last_date' => $c_last ) );
t( 'C.2: the event reads as its range', sfaf_ap_event_date( $c_id ), sfaf_ap_range( $c_first, $c_last ) );
$grid = $sc->month_grid_data( substr( $c_first, 0, 7 ), array() );
$on   = array();
foreach ( sfaf_event_days( $c_id ) as $day ) { $on[ $day ] = isset( $grid['events'][ $day ] ) && in_array( $c_id, $grid['events'][ $day ], true ); }
t( 'PLANT C.2: the event is on every day of its range in the grid', $on, array_fill_keys( sfaf_event_days( $c_id ), true ) );
t( 'C.2: three days', count( $on ), 3 );
$lead_first = date( 'Y-m-d', strtotime( substr( $c_first, 0, 7 ) . '-01 -2 days' ) );
$lead       = t_event( 'Started last month', $lead_first, array( '_uc_multi_day' => '1', '_uc_last_date' => substr( $c_first, 0, 7 ) . '-01' ) );
$grid       = $sc->month_grid_data( substr( $c_first, 0, 7 ), array() );
t( 'C.2: an event begun before the month is on the month\'s first day', isset( $grid['events'][ substr( $c_first, 0, 7 ) . '-01' ] ) && in_array( $lead, $grid['events'][ substr( $c_first, 0, 7 ) . '-01' ], true ), true );
t( 'C.2: the calendar file ends on the last day', sfaf_event_datetimes( $c_id )[1]->format( 'Y-m-d H:i' ), $c_last . ' 19:30' );
update_post_meta( $c_id, '_uc_last_date', '' );
t( 'C.1: Multi-day with no End date is held from publishing', in_array( 'last_date', SFAF_Sources::publish_missing( $c_id ), true ), true );
delete_post_meta( $c_id, '_uc_multi_day' );
t( 'C.1: without the tick it is not', in_array( 'last_date', SFAF_Sources::publish_missing( $c_id ), true ), false );

/* ======================================================================== D */
foreach ( array_keys( $GLOBALS['kit_posts'] ) as $pid ) { wp_update_post( array( 'ID' => $pid, 'post_status' => 'draft' ) ); }
$d_day   = $soon( 20 );
$d_id    = t_event( 'Coffee on the holiday', $d_day );
$d_other = t_event( 'Coffee the next week', $soon( 27 ) );
$cid     = SFAF_Closures::save( '', 'Thanksgiving', $d_day );
$items   = SFAF_Closure_Review::undecided( $cid );
t( 'D.1: the event on the closure is found, and only it', wp_list_pluck( $items, 'event_id' ), array( $d_id ) );
$GLOBALS['t_mail'] = array();
SFAF_Closure_Review::on_closure_saved( $cid );
t( 'D.1: one email, to the organizer', count( $GLOBALS['t_mail'] ), 1 );
t( 'D.1: it names the event', isset( $GLOBALS['t_mail'][0] ) && false !== strpos( $GLOBALS['t_mail'][0][1], 'Coffee on the holiday' ), true );
SFAF_Closure_Review::on_closure_saved( $cid );
t( 'D.1: saving the closure again sends nothing new', count( $GLOBALS['t_mail'] ), 1 );

$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET = array( 'closure' => $cid, 'do' => 'keep' );
$page = t_cap( function () use ( $portal, $d_id ) { $portal->handle( 'closed-day/' . $d_id ); } );
$_GET = array();
t( 'D.1: the page asks', false !== strpos( $page, 'name="uc_action" value="closed_keep"' ), true );
t( 'PLANT D.1: opening the Keep link keeps the event', SFAF_Closure_Review::is_kept( $d_id, $cid ), false );
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = array( 'uc_action' => 'closed_keep', 'uc_nonce' => 'n', 'event_id' => $d_id, 'closure_id' => $cid );
$GLOBALS['kit_redirect_throws'] = true;
t_cap( function () use ( $portal, $d_id ) { $portal->handle( 'closed-day/' . $d_id ); } );
$GLOBALS['kit_redirect_throws'] = false;
$_POST = array(); $_SERVER['REQUEST_METHOD'] = 'GET';
t( 'D.1: Keep, posted, keeps it', SFAF_Closure_Review::is_kept( $d_id, $cid ), true );
t( 'D.2: a kept date is not undecided', count( SFAF_Closure_Review::undecided( $cid ) ), 0 );

$d_two = t_event( 'Walk on the closure', $soon( 5 ) );
$cid2  = SFAF_Closures::save( '', 'Staff day', $soon( 5 ) );
$GLOBALS['t_mail'] = array();
SFAF_Closure_Review::run_reminders();
t( 'D.2: a week before, the organizer is reminded', count( $GLOBALS['t_mail'] ), 1 );
SFAF_Closure_Review::run_reminders();
t( 'D.2: once', count( $GLOBALS['t_mail'] ), 1 );
$tab = t_cap( function () use ( $portal ) { $_GET = array( 'kind' => 'closed' ); kit_call( 'SFAF_Portal', 'render_pending', $portal, array( new WP_User() ) ); } );
$_GET = array();
t( 'D.2: On closed days lists the undecided one', false !== strpos( $tab, 'data-uc-closed-row="' . $d_two . '"' ), true );
t( 'D.2: and not the kept one', false !== strpos( $tab, 'data-uc-closed-row="' . $d_id . '"' ), false );

/* ======================================================================== E */
$GLOBALS['t_last_args'] = array();
$mine = kit_call( 'SFAF_Portal', 'query_events', $portal, array( new WP_User(), array( 'scope' => 'drafts', 'per_page' => 25 ) ) );
$last = end( $GLOBALS['t_last_args'] );
t( 'PLANT E: Drafts is the person\'s own', isset( $last['author'] ) ? (int) $last['author'] : 0, 1 );
t( 'E: Drafts is drafts', isset( $last['post_status'] ) ? $last['post_status'] : null, 'draft' );
$GLOBALS['t_last_args'] = array();
kit_call( 'SFAF_Portal', 'query_events', $portal, array( new WP_User(), array( 'scope' => 'scheduled', 'per_page' => 25 ) ) );
$last = end( $GLOBALS['t_last_args'] );
t( 'E: Scheduled is the person\'s own, scheduled', array( isset( $last['author'] ) ? (int) $last['author'] : 0, isset( $last['post_status'] ) ? $last['post_status'] : null ), array( 1, 'future' ) );

/* ======================================================================== F */
t( 'F: Replies go to is a list with Someone else', array( false !== strpos( $editor, 'name="event_replyto_pick"' ), false !== strpos( $editor, 'value="other" data-uc-replyto-other' ) ), array( true, true ) );

if ( $fails ) {
    echo 'RELEASE 3.112.0: ' . count( $fails ) . " FAILURE(S)\n  - " . implode( "\n  - ", $fails ) . "\n";
    exit( 1 );
}
echo '3.112.0: ' . $ran . " checks. Retired tags leave the pickers and the filter and keep their pills; a series default fills an empty set; the pills and their Spanish name; locations by venue and by address, filtering and carried by See all events; the range in one formatter and on every day of the grid, the calendar file and the publish rule; one closed-day email per closure, the reminder once, a link that never acts; Drafts and Scheduled are the person's own; Replies go to offers people and Someone else.\n";
exit( 0 );
