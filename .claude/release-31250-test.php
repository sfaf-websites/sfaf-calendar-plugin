<?php
/**
 * 3.125.0: THE STAFF FORM'S LIMIT IS PER PERSON, AND MY EVENTS' FILTERS.
 *
 *     php .claude/release-31250-test.php
 *
 * The real SFAF_Request, SFAF_Submissions and SFAF_Portal through wp-kit.php.
 *
 *   A  the staff form counts saved requests per verified address, 30 an hour,
 *      in a fixed window; nothing per connection or per link; a refusal says
 *      how many minutes and keeps what was typed; the community form's
 *      numbers are untouched (5 per connection and 60 per series an hour)
 *   B  My events: Series and Organizer dropdowns beside Categories, each
 *      added to the query with the category instead of replacing it; the
 *      date range reaches an event by any of its days; a range reaching
 *      outside Upcoming or Archived shows All; the tabs, the sort, the pager
 *      and the scope switch carry both new filters
 *   C  an empty My events with filters offers the same search over all events
 *   D  the gate's list primes the posts and their terms, not only the meta
 *
 * PLANTs in plant-31250.php.
 */

require __DIR__ . '/wp-kit.php';
if ( ! function_exists( 'wpautop' ) ) { function wpautop( $s ) { return $s; } }

$fails = array();
$ran   = 0;
function rt( $ok, $why ) { global $fails, $ran; $ran++; if ( ! $ok ) { $fails[] = $why; } }
function rt_code( $path ) {
    $out = '';
    foreach ( token_get_all( file_get_contents( $path ) ) as $t ) {
        if ( is_array( $t ) ) { if ( T_COMMENT === $t[0] || T_DOC_COMMENT === $t[0] ) { continue; } $out .= $t[1]; } else { $out .= $t; }
    }
    return $out;
}
function rt_method( $src, $name ) {
    $at = strpos( $src, 'function ' . $name . '(' );
    if ( false === $at ) { return ''; }
    $open = strpos( $src, '{', $at ); $depth = 0;
    for ( $i = $open, $n = strlen( $src ); $i < $n; $i++ ) {
        if ( '{' === $src[ $i ] ) { $depth++; }
        if ( '}' === $src[ $i ] ) { $depth--; if ( 0 === $depth ) { return substr( $src, $at, $i - $at + 1 ); } }
    }
    return '';
}
$root = dirname( __DIR__ );

/* ---- A: the staff form ------------------------------------------------- */
$req  = rt_code( $root . '/includes/class-sfaf-request.php' );
$hs   = rt_method( $req, 'handle_submission' );
rt( '' !== $hs, 'A: handle_submission() not found' );
rt( false === strpos( $hs, "'submit_ip'" ) && false === strpos( $hs, "'submit_tok'" ) && false === strpos( $hs, 'client()' ),
    'PLANT A: the staff form still counts per connection or per link' );
rt( 30 === SFAF_Request::SUBMIT_LIMIT, 'PLANT A: the staff limit is ' . SFAF_Request::SUBMIT_LIMIT . ', not 30' );
rt( false !== strpos( $hs, "window_wait( 'request_addr', \$email, self::SUBMIT_LIMIT )" ), 'PLANT A: the limit is not asked of the verified address' );
$count_at  = strpos( $hs, "window_count( 'request_addr', \$email, HOUR_IN_SECONDS )" );
$create_at = strpos( $hs, 'self::create_event(' );
$fail_at   = strpos( $hs, 'if ( ! $event_id ) {' );
rt( false !== $count_at && false !== $fail_at && $count_at > $fail_at && $count_at > $create_at,
    'PLANT A: a request is counted before it is saved, so a refused or failed one uses up the allowance' );

kit_reset();
$GLOBALS['kit_trans'] = array();
$who = 'emily@sfaf.org';
for ( $i = 0; $i < 29; $i++ ) { SFAF_Submissions::window_count( 'request_addr', $who, HOUR_IN_SECONDS ); }
rt( 0 === SFAF_Submissions::window_wait( 'request_addr', $who, 30 ), 'A: the 30th request in an hour was refused' );
SFAF_Submissions::window_count( 'request_addr', $who, HOUR_IN_SECONDS );
$wait = SFAF_Submissions::window_wait( 'request_addr', $who, 30 );
rt( $wait > 3500 && $wait <= 3600, 'A: after 30 the wait is ' . $wait . 's, not the rest of the hour' );
rt( 0 === SFAF_Submissions::window_wait( 'request_addr', 'mark@sfaf.org', 30 ), 'PLANT A: one person\'s requests counted against a colleague' );
// The window is fixed: a stored window that opened 50 minutes ago has 10 left.
$key = SFAF_Submissions::RATE_PREFIX . 'request_addr_' . hash( 'sha256', $who );
$GLOBALS['kit_trans'][ $key ] = array( 'n' => 30, 'until' => time() + 600 );
SFAF_Submissions::window_count( 'request_addr', $who, HOUR_IN_SECONDS );
$v = $GLOBALS['kit_trans'][ $key ];
rt( is_array( $v ) && abs( $v['until'] - ( time() + 600 ) ) <= 2, 'PLANT A: counting moved the window\'s end' );

// The refusal, rendered: minutes said, the title kept.
$GLOBALS['kit_trans'][ $key ] = array( 'n' => 30, 'until' => time() + 725 );
$_POST = array( 'uc_request_action' => 'submit_request', 'title' => 'Harm reduction training', 'requester_name' => 'Emily' );
$d = ob_get_level(); ob_start();
try { kit_call( 'SFAF_Request', 'handle_submission', null, array( 'tok', $who ) ); } catch ( Throwable $e ) { echo 'THREW ' . $e->getMessage(); }
$h = ''; while ( ob_get_level() > $d ) { $h = ob_get_clean() . $h; }
rt( false !== strpos( $h, 'You have sent 30 requests this hour. You can send this one in 13 minutes.' ),
    'PLANT A: the refusal does not say how long: ' . ( preg_match( '/You have sent[^<]*/', $h, $m ) ? $m[0] : substr( strip_tags( $h ), 0, 200 ) ) );
rt( false !== strpos( $h, 'value="Harm reduction training"' ), 'PLANT A: the refusal threw away what was typed' );
rt( false === strpos( $h, 'several requests in a short time' ), 'A: the old message is still drawn' );
$_POST = array();

// The community form, unchanged.
$sub = rt_code( $root . '/includes/class-sfaf-submit.php' );
rt( false !== strpos( $sub, "SFAF_Submissions::allow( 'submit_ip', SFAF_Submissions::client(), 5, HOUR_IN_SECONDS )" )
    && false !== strpos( $sub, "SFAF_Submissions::allow( 'submit_series', (string) \$series->term_id, 60, HOUR_IN_SECONDS )" ),
    'A: the community form\'s limits changed' );

/* ---- B and C: My events ------------------------------------------------ */
kit_reset();
$GLOBALS['kit_redirect_throws'] = true;
$GLOBALS['rt_q'] = array();
$GLOBALS['rt_rows'] = true;
$GLOBALS['kit_query'] = function ( $a ) {
    if ( isset( $a['posts_per_page'] ) && 25 === (int) $a['posts_per_page'] ) { $GLOBALS['rt_q'][] = $a; }
    if ( ! $GLOBALS['rt_rows'] ) { return array(); }
    $o = array();
    foreach ( $GLOBALS['kit_posts'] as $id => $p ) { if ( 'uc_event' === $p->post_type ) { $o[] = ( isset( $a['fields'] ) && 'ids' === $a['fields'] ) ? (int) $id : $p; } }
    return $o;
};
$E = kit_write_post( array( 'post_type' => 'uc_event', 'post_status' => 'publish', 'post_title' => 'Coffee social', 'post_author' => 1 ) );
update_post_meta( $E, '_uc_event_date', date( 'Y-m-d', strtotime( '+3 days' ) ) );
$portal = ( new ReflectionClass( 'SFAF_Portal' ) )->newInstanceWithoutConstructor();
$run = function ( $get ) use ( $portal ) {
    $GLOBALS['rt_q'] = array(); $_GET = $get;
    $d = ob_get_level(); ob_start();
    try { kit_call( 'SFAF_Portal', 'render_events', $portal, array( new WP_User() ) ); } catch ( Throwable $e ) { echo 'THREW ' . $e->getMessage() . ' ' . basename( $e->getFile() ) . ':' . $e->getLine(); }
    $h = ''; while ( ob_get_level() > $d ) { $h = ob_get_clean() . $h; }
    $x = new DOMDocument(); libxml_use_internal_errors( true ); $x->loadHTML( '<?xml encoding="utf-8"?>' . $h ); libxml_clear_errors();
    return array( $h, new DOMXPath( $x ), end( $GLOBALS['rt_q'] ) );
};

$past = date( 'Y-m-d', strtotime( '-20 days' ) );
list( $h, $x, $q ) = $run( array( 'cat' => '11', 'series' => '12', 'org' => '11', 'from' => $past, 'to' => $past, 'orderby' => 'date' ) );
rt( false === strpos( $h, 'THREW' ), 'B: render_events threw: ' . substr( $h, strpos( $h, 'THREW' ), 160 ) );
$bar = $x->query( '//form[contains(@class,"uc-filters-bar")]' )->item( 0 );
$names = array();
if ( $bar ) { foreach ( $x->query( './/select', $bar ) as $s ) { $names[] = $s->getAttribute( 'name' ); } }
rt( array( 'cat', 'series', 'org', 'status' ) === $names, 'PLANT B: the bar\'s dropdowns are ' . json_encode( $names ) . ', not Categories, Series, Organizer, Status' );
$first = function ( $name ) use ( $x, $bar ) { $o = $bar ? $x->query( './/select[@name="' . $name . '"]/option', $bar ) : null; return $o && $o->length ? trim( $o->item( 0 )->textContent ) : ''; };
rt( 'All series' === $first( 'series' ) && 'All organizers' === $first( 'org' ), 'B: the empty choices read ' . json_encode( array( $first( 'series' ), $first( 'org' ) ) ) );
$sel = function ( $name ) use ( $x, $bar ) { $o = $bar ? $x->query( './/select[@name="' . $name . '"]/option[@selected]', $bar ) : null; return $o && $o->length ? $o->item( 0 )->getAttribute( 'value' ) : ''; };
rt( '12' === $sel( 'series' ) && '11' === $sel( 'org' ), 'PLANT B: the chosen series and organizer are not shown chosen' );

$tax = array();
foreach ( (array) ( isset( $q['tax_query'] ) ? $q['tax_query'] : array() ) as $c ) { if ( is_array( $c ) && isset( $c['taxonomy'] ) ) { $tax[ $c['taxonomy'] ] = (int) $c['terms']; } }
rt( array( 'uc_event_category' => 11, 'uc_series' => 12, 'uc_organizer' => 11 ) === $tax, 'PLANT B: the query narrows by ' . json_encode( $tax ) );

$from_ok = false;
foreach ( (array) $q['meta_query'] as $c ) {
    if ( is_array( $c ) && isset( $c['relation'] ) && 'OR' === $c['relation'] ) {
        $keys = array();
        foreach ( $c as $k => $sub ) { if ( is_array( $sub ) ) { $keys[ $sub['key'] ] = $sub['compare'] . ' ' . $sub['value']; } }
        $from_ok = ( array( '_uc_event_date' => '>= ' . $past, '_uc_end_date' => '>= ' . $past ) === $keys );
    }
}
rt( $from_ok, 'PLANT B: From compares the first day only, so a multi-day event is missed on its later days' );
$up = false;
foreach ( (array) $q['meta_query'] as $c ) { if ( is_array( $c ) && isset( $c['key'] ) && '_uc_event_date' === $c['key'] && '>=' === $c['compare'] && current_time( 'Y-m-d' ) === $c['value'] ) { $up = true; } }
rt( ! $up, 'PLANT B: a past date searched from Upcoming is still held to Upcoming' );
$view = $bar ? $x->query( './/input[@name="view"]', $bar )->item( 0 ) : null;
rt( $view && 'all' === $view->getAttribute( 'value' ), 'PLANT B: the form does not say All for a past range' );
$tab = $x->query( '//a[contains(@class,"uc-view-tab-active")]' )->item( 0 );
rt( $tab && 'All' === trim( $tab->textContent ), 'B: the active tab is ' . ( $tab ? trim( $tab->textContent ) : 'none' ) );
foreach ( $x->query( '//a[contains(@class,"uc-view-tab")]' ) as $a ) {
    $href = html_entity_decode( $a->getAttribute( 'href' ) );
    $t = trim( $a->textContent );
    rt( false !== strpos( $href, 'series=12' ) && false !== strpos( $href, 'org=11' ), "PLANT B: the $t tab drops the series or organizer" );
    if ( 'Upcoming' === $t ) { rt( false === strpos( $href, 'from=' ), 'B: the Upcoming tab carries a past range, which would show All again' ); }
    if ( 'All' === $t ) { rt( false !== strpos( $href, 'from=' . $past ), 'B: the All tab drops the range' ); }
}
foreach ( array( 'scope switch' => '//div[contains(@class,"uc-scope-switch")]//a', 'sort' => '//th//a' ) as $what => $path ) {
    $l = $x->query( $path );
    rt( $l->length > 0, "B: no $what link drawn" );
    foreach ( $l as $a ) {
        $href = html_entity_decode( $a->getAttribute( 'href' ) );
        rt( false !== strpos( $href, 'series=12' ) && false !== strpos( $href, 'org=11' ), "PLANT B: the $what drops the series or organizer: $href" );
    }
}
$pg = rt_method( rt_code( $root . '/includes/class-sfaf-portal.php' ), 'events_pagination' );
rt( false !== strpos( $pg, "'series'" ) && false !== strpos( $pg, "'org'" ), 'PLANT B: the pager drops the series or organizer' );

// A future range from Upcoming stays Upcoming.
$soon = date( 'Y-m-d', strtotime( '+2 days' ) );
list( $h, $x, $q ) = $run( array( 'from' => $soon ) );
$tab = $x->query( '//a[contains(@class,"uc-view-tab-active")]' )->item( 0 );
rt( $tab && 'Upcoming' === trim( $tab->textContent ), 'B: a future range left Upcoming' );
// A malformed date is no date.
list( $h, $x, $q ) = $run( array( 'from' => '2026-1-5; DROP' ) );
$fv = $x->query( '//form[contains(@class,"uc-filters-bar")]//input[@name="from"]' )->item( 0 );
rt( $fv && '' === $fv->getAttribute( 'value' ), 'B: a malformed From was kept' );

// C: nothing found in My events, with filters.
$GLOBALS['rt_rows'] = false;
list( $h, $x, $q ) = $run( array( 'series' => '12', 'from' => $soon ) );
$wide = $x->query( '//*[@data-uc-search-all]//a' )->item( 0 );
$href = $wide ? html_entity_decode( $wide->getAttribute( 'href' ) ) : '';
rt( $wide && 'Search all events' === trim( $wide->textContent ) && false !== strpos( $href, 'scope=all' ) && false !== strpos( $href, 'series=12' ) && false !== strpos( $href, 'from=' . $soon ),
    'PLANT C: an empty My events does not offer the same search over all events: ' . $href );
list( $h, $x, $q ) = $run( array() );
rt( 0 === $x->query( '//*[@data-uc-search-all]' )->length, 'C: the offer is drawn with no filters' );
list( $h, $x, $q ) = $run( array( 'series' => '12', 'scope' => 'all' ) );
rt( 0 === $x->query( '//*[@data-uc-search-all]' )->length, 'C: the offer is drawn on All events' );
$GLOBALS['rt_rows'] = true;

/* ---- D: the gate's list ------------------------------------------------ */
$uei = rt_method( rt_code( $root . '/includes/class-sfaf-portal.php' ), 'user_event_ids' );
rt( false !== strpos( $uei, '_prime_post_caches( $ids, true, true )' ), 'PLANT D: user_event_ids() primes only the meta, so the gate fetches each post and its series one query at a time' );
rt( strpos( $uei, '_prime_post_caches' ) < strpos( $uei, 'self::user_can_edit_event(' ), 'D: the caches are primed after the gate has run' );

if ( $fails ) {
    echo 'RELEASE 3.125.0: ' . count( $fails ) . " of $ran FAILED\n  - " . implode( "\n  - ", $fails ) . "\n";
    exit( 1 );
}
echo "release 3.125.0: $ran checks: the staff form counts saved requests per address, 30 an hour, and says the wait; My events filters by series and organizer, finds a multi-day event on any of its days, shows All for a range outside the tab, and primes the gate's posts.\n";
