<?php
/**
 * 3.143.0: ALSO SHOW THIS EVENT IN SERIES.
 *
 *     php .claude/release-31430-test.php
 *
 * On wp-kit.php, with the kit's queries answered by a small evaluator of the
 * tax_query and meta_query WP_Query is given (AND and OR, =, !=, >=, <, IN,
 * NOT IN, EXISTS, NOT EXISTS, a multi-row key matching on any row), so the
 * real SFAF_Series::events() and also_events() decide what is listed.
 *
 *   S  the store: one row per extra series, never its own, never twice,
 *      never one that does not exist, in name order; removing a series takes
 *      it off every event
 *   V  the real editor save (save_event_from_post): several extras stored;
 *      the own series dropped even when posted; moving into an extra series
 *      takes it off; all unticked clears; a form without the control leaves
 *      them; and nothing else on the event comes from an extra series
 *   F  the field, from render_series_prefill() on Edit and on Add: right
 *      under Series, the brief's label, a checkbox per series, the own one
 *      hidden and unticked, the marker
 *   P  the event page: "Upcoming in this series" on an event of the extra
 *      series lists it in date order with the others, heading unchanged; the
 *      ended page's dates too; private, hidden cancelled and past ones stay
 *      out; the event's own page lists only its own series; with no extras
 *      every list is exactly what 3.142.1 listed
 *   C  the series page: "Also shown here" after Upcoming, each row tagged
 *      "also shown here" and naming its own series, no Remove, not in the
 *      Upcoming list
 *   D  PROJECT.md records that series followers will count these events
 *
 * PLANT in plant-31430.php.
 */

require __DIR__ . '/wp-kit.php';

$fails  = array();
$checks = 0;
function at( $ok, $why ) { global $fails, $checks; $checks++; if ( ! $ok ) { $fails[] = $why; } }

/* ---- a WP_Query evaluator over the kit's posts, terms and meta ---- */
function at_meta_rows( $id, $key ) {
    $m = isset( $GLOBALS['kit_meta'][ $id ] ) ? $GLOBALS['kit_meta'][ $id ] : array();
    if ( ! array_key_exists( $key, $m ) ) { return null; }
    return kit_multi( $key ) ? array_values( (array) $m[ $key ] ) : array( $m[ $key ] );
}
function at_clause( $id, $c ) {
    if ( isset( $c['key'] ) ) {
        $rows = at_meta_rows( $id, $c['key'] );
        $cmp  = isset( $c['compare'] ) ? strtoupper( $c['compare'] ) : ( isset( $c['value'] ) && is_array( $c['value'] ) ? 'IN' : '=' );
        if ( 'EXISTS' === $cmp ) { return null !== $rows; }
        if ( 'NOT EXISTS' === $cmp ) { return null === $rows; }
        if ( null === $rows ) { return false; }
        foreach ( $rows as $r ) {
            $r = (string) $r; $v = isset( $c['value'] ) ? $c['value'] : '';
            switch ( $cmp ) {
                case '=':      if ( $r === (string) $v ) { return true; } break;
                case '!=':     if ( $r !== (string) $v ) { return true; } break;
                case '>=':     if ( $r >= (string) $v ) { return true; } break;
                case '<':      if ( $r < (string) $v ) { return true; } break;
                case 'IN':     if ( in_array( $r, array_map( 'strval', (array) $v ), true ) ) { return true; } break;
                case 'NOT IN': if ( ! in_array( $r, array_map( 'strval', (array) $v ), true ) ) { return true; } break;
            }
        }
        return false;
    }
    return at_group( $id, $c );
}
function at_group( $id, $g ) {
    $rel = isset( $g['relation'] ) ? strtoupper( $g['relation'] ) : 'AND';
    $any = false; $all = true; $n = 0;
    foreach ( $g as $k => $c ) {
        if ( 'relation' === $k || ! is_array( $c ) ) { continue; }
        $n++; $ok = at_clause( $id, $c );
        $any = $any || $ok; $all = $all && $ok;
    }
    return 0 === $n ? true : ( 'OR' === $rel ? $any : $all );
}
function at_query( $a ) {
    $out = array();
    foreach ( $GLOBALS['kit_posts'] as $id => $p ) {
        if ( isset( $a['post_type'] ) && $p->post_type !== $a['post_type'] ) { continue; }
        if ( isset( $a['post_status'] ) && ! in_array( $p->post_status, (array) $a['post_status'], true ) ) { continue; }
        $ok = true;
        foreach ( isset( $a['tax_query'] ) ? (array) $a['tax_query'] : array() as $t ) {
            if ( ! is_array( $t ) || ! isset( $t['taxonomy'] ) ) { continue; }
            $has = isset( $GLOBALS['kit_terms'][ $id ][ $t['taxonomy'] ] ) ? (array) $GLOBALS['kit_terms'][ $id ][ $t['taxonomy'] ] : array();
            $hit = (bool) array_intersect( array_map( 'intval', $has ), array_map( 'intval', (array) $t['terms'] ) );
            $op  = isset( $t['operator'] ) ? strtoupper( $t['operator'] ) : 'IN';
            if ( ( 'NOT IN' === $op ) === $hit ) { $ok = false; }
        }
        if ( $ok && isset( $a['meta_query'] ) && ! at_group( $id, (array) $a['meta_query'] ) ) { $ok = false; }
        if ( $ok ) { $out[] = (int) $id; }
    }
    usort( $out, function ( $x, $y ) {
        $dx = (string) get_post_meta( $x, '_uc_event_date', true ); $dy = (string) get_post_meta( $y, '_uc_event_date', true );
        return $dx === $dy ? $x - $y : strcmp( $dx, $dy );
    } );
    $lim = isset( $a['posts_per_page'] ) ? (int) $a['posts_per_page'] : -1;
    return $lim > 0 ? array_slice( $out, 0, $lim ) : $out;
}

function at_world() {
    kit_reset();
    $GLOBALS['kit_query']           = 'at_query';
    $GLOBALS['kit_redirect_throws'] = true;
    $GLOBALS['kit_more_terms']      = array( 13 => 'Gamma' );
    $GLOBALS['kit_multi_meta']      = array( SFAF_Series::META_ALSO );
}
function at_event( $title, $date, $series, $status = 'publish', $meta = array() ) {
    $id = kit_write_post( array( 'post_type' => 'uc_event', 'post_status' => $status, 'post_title' => $title, 'post_content' => '' ) );
    update_post_meta( $id, '_uc_event_date', $date );
    update_post_meta( $id, '_uc_start_time', '18:00' );
    foreach ( $meta as $k => $v ) { update_post_meta( $id, $k, $v ); }
    if ( $series ) { wp_set_object_terms( $id, array( $series ), SFAF_Series::TAXONOMY ); }
    return $id;
}
$day = function ( $n ) { return date( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' ' . ( $n >= 0 ? '+' : '' ) . $n . ' days' ) ); };
$cap = function ( $fn ) { ob_start(); try { $fn(); } catch ( Throwable $t ) { ob_end_clean(); return 'THREW ' . $t->getMessage() . ' at ' . basename( $t->getFile() ) . ':' . $t->getLine(); } return ob_get_clean(); };

/* ==== S: the store ===================================================== */
at_world();
$e = at_event( 'Beta night', $day( 5 ), 12 );
$got = SFAF_Series::set_also_for_event( $e, array( 13, 12, 11, 13, 99, 0 ) );
at( array( 13, 11 ) === $got, 'S: set_also_for_event stored ' . json_encode( $got ) . ', not Gamma and Alpha without its own, a duplicate or a missing one' );
at( array( 11, 13 ) === SFAF_Series::also_for_event( $e ), 'S: also_for_event is not Alpha, Gamma in name order: ' . json_encode( SFAF_Series::also_for_event( $e ) ) );
at( array( '13', '11' ) === array_map( 'strval', get_post_meta( $e, SFAF_Series::META_ALSO, false ) ), 'S: not one meta row per extra series: ' . json_encode( get_post_meta( $e, SFAF_Series::META_ALSO, false ) ) );
SFAF_Series::set_also_for_event( $e, array() );
at( array() === get_post_meta( $e, SFAF_Series::META_ALSO, false ), 'S: an empty set does not clear the rows' );
SFAF_Series::set_also_for_event( $e, array( 11, 13 ) );
$e2 = at_event( 'Gamma day', $day( 6 ), 13 );
SFAF_Series::set_also_for_event( $e2, array( 11 ) );
if ( ! function_exists( 'wp_delete_term' ) ) { function wp_delete_term( $id, $tax ) { return true; } }
SFAF_Series::delete( 11 );
at( array( 13 ) === array_map( 'intval', get_post_meta( $e, SFAF_Series::META_ALSO, false ) ) && array() === get_post_meta( $e2, SFAF_Series::META_ALSO, false ),
    'S: removing a series did not take it off every event shown in it' );

/* ==== V: the real editor save ========================================== */
at_world();
$portal = ( new ReflectionClass( 'SFAF_Portal' ) )->newInstanceWithoutConstructor();
function at_save( $over ) {
    global $portal;
    $_GET  = array();
    $_POST = array_merge( array(
        'event_id' => 0, 'save_mode' => 'publish', 'title' => 'Beta night', 'date' => date( 'Y-m-d', strtotime( '+5 days' ) ),
        'start_time' => '18:00', 'end_time' => '19:30', 'uc_organizer_present' => '1',
        'uc_category_present' => '1', 'description' => '', 'uc_online_present' => '1', 'uc_online' => '0',
        'location_mode' => 'custom', 'location_street' => '1035 Market St', 'location_city' => 'San Francisco',
        'series' => '12',
    ), $over );
    try { $r = kit_call( 'SFAF_Portal', 'save_event_from_post', $portal, array( new WP_User() ) ); } catch ( Throwable $t ) { return 'THREW ' . $t->getMessage() . ' at ' . basename( $t->getFile() ) . ':' . $t->getLine(); }
    return (int) $r['id'];
}
update_term_meta( 11, SFAF_Series::META_LANGUAGE, 'es' );
update_term_meta( 11, SFAF_Series::META_CATEGORIES, array( 12 ) );
update_term_meta( 11, SFAF_Series::META_ORGANIZERS, array( 11 ) );
update_term_meta( 13, SFAF_Series::META_CATEGORIES, array( 11 ) );
$v = at_save( array( 'uc_also_series_present' => '1', 'also_series' => array( '11', '13', '12' ) ) );
at( is_int( $v ) && $v > 0, 'V: the save did not run: ' . var_export( $v, true ) );
if ( is_int( $v ) && $v > 0 ) {
    at( array( 11, 13 ) === SFAF_Series::also_for_event( $v ), 'V: the save did not store Alpha and Gamma without its own Beta: ' . json_encode( SFAF_Series::also_for_event( $v ) ) );
    at( 12 === SFAF_Series::id_for_event( $v ), 'V: the event left its own series' );
    at( array() === array_map( 'intval', (array) wp_get_post_terms( $v, 'uc_event_category', array( 'fields' => 'ids' ) ) ), 'V: an extra series filled the categories' );
    at( '' === (string) get_post_meta( $v, '_uc_language', true ) && 'en' === sfaf_event_language( $v ), 'V: an extra series gave it a language' );
    at( array() === array_map( 'intval', (array) wp_get_post_terms( $v, 'uc_organizer', array( 'fields' => 'ids' ) ) ), 'V: an extra series filled the organizers' );

    $v2 = at_save( array( 'event_id' => (string) $v, 'series' => '13', 'uc_also_series_present' => '1', 'also_series' => array( '11', '13' ) ) );
    at( array( 11 ) === SFAF_Series::also_for_event( $v ), 'V: moving into Gamma did not take Gamma off its extras: ' . json_encode( SFAF_Series::also_for_event( $v ) ) );
    at_save( array( 'event_id' => (string) $v, 'series' => '13' ) );
    at( array( 11 ) === SFAF_Series::also_for_event( $v ), 'V: a form without the control changed the extras' );
    at_save( array( 'event_id' => (string) $v, 'series' => '13', 'uc_also_series_present' => '1' ) );
    at( array() === SFAF_Series::also_for_event( $v ), 'V: unticking every one did not clear them' );
}

/* ==== F: the field ===================================================== */
at_world();
$fe = at_event( 'Beta night', $day( 5 ), 12 );
SFAF_Series::set_also_for_event( $fe, array( 11 ) );
foreach ( array( 'Edit' => $fe, 'Add' => 0 ) as $which => $eid ) {
    $h = $cap( function () use ( $portal, $eid ) { kit_call( 'SFAF_Portal', 'render_series_prefill', $portal, array( SFAF_Series::all(), $eid ? 12 : 12, $eid ) ); } );
    at( 0 !== strpos( $h, 'THREW' ), "F: $which did not render: " . substr( $h, 0, 200 ) );
    $sel = strpos( $h, 'data-uc-series-select' ); $fld = strpos( $h, 'data-uc-also-series' ); $lang = strpos( $h, 'data-uc-event-language' );
    at( false !== $sel && false !== $fld && $fld > $sel && ( false === $lang || $fld < $lang ), "F: $which: the field is not right under Series" );
    at( false !== strpos( $h, 'Also show this event in series&hellip;' ), "F: $which: no \"Also show this event in series...\" label" );
    at( false !== strpos( $h, 'name="uc_also_series_present"' ), "F: $which: no marker" );
    $doc = new DOMDocument(); @$doc->loadHTML( '<?xml encoding="utf-8"?><div>' . $h . '</div>' );
    $x = new DOMXPath( $doc );
    $boxes = array();
    foreach ( $x->query( '//*[@data-uc-also-series]//input[@name="also_series[]"]' ) as $b ) {
        $boxes[ $b->getAttribute( 'value' ) ] = array( 'checked' => $b->hasAttribute( 'checked' ), 'hidden' => $b->parentNode->hasAttribute( 'hidden' ) );
    }
    at( array( '11', '12', '13' ) === array_map( 'strval', array_keys( $boxes ) ), "F: $which: not a box for Alpha, Beta and Gamma: " . json_encode( array_keys( $boxes ) ) );
    if ( isset( $boxes['12'] ) ) { at( $boxes['12']['hidden'] && ! $boxes['12']['checked'], "F: $which: the event's own series can be picked" ); }
    if ( 'Edit' === $which && isset( $boxes['11'] ) ) { at( $boxes['11']['checked'] && ! $boxes['11']['hidden'], 'F: Edit: Alpha, stored, is not ticked' ); }
    if ( 'Add' === $which && isset( $boxes['11'] ) ) { at( ! $boxes['11']['checked'], 'F: Add: a box is ticked on a new event' ); }
    at( 1 === $x->query( '//*[@data-uc-also-series]//*[@data-uc-chips-toggle]' )->length, "F: $which: not the chip picker's + Add" );
}
$js = (string) file_get_contents( dirname( __DIR__ ) . '/public/js/portal.js' );
at( false !== strpos( $js, "run('alsoSeries', initAlsoSeries);\n        run('categoryChips', initCategoryChips);" ), 'F: initAlsoSeries does not run before the chips are drawn' );
$css = (string) file_get_contents( dirname( __DIR__ ) . '/public/css/portal.css' );
at( false !== strpos( $css, '.uc-also-field .uc-check[hidden] { display: none; }' ), 'F: .uc-check\'s flex beats the own series\' hidden' );

/* ==== P: the event page ================================================ */
at_world();
$a1 = at_event( 'Alpha one', $day( 2 ), 11 );
$a2 = at_event( 'Alpha two', $day( 9 ), 11 );
$a3 = at_event( 'Alpha three', $day( 4 ), 11 );
$before_list  = sfaf_series_list_html( $a1 );
$before_ended = sfaf_event_upcoming_dates( $a1 );
$before_dates = sfaf_get_series_events( 11, true );
at( sfaf_series_dates_shown( 11 ) === $before_dates, 'P: with no extras the dates are not exactly sfaf_get_series_events()' );

$b1 = at_event( 'Beta night', $day( 5 ), 12 );
$bp = at_event( 'Beta private', $day( 6 ), 12, 'publish', array( SFAF_Privacy::META => '1' ) );
$bx = at_event( 'Beta past', $day( -3 ), 12 );
$bd = at_event( 'Beta draft', $day( 7 ), 12, 'draft' );
$bc = at_event( 'Beta cancelled', $day( 8 ), 12, 'publish', array( SFAF_Cancellation::VISIBILITY_META => 'hide' ) );
$g1 = at_event( 'Gamma day', $day( 3 ), 13 );
// Before anything lists Gamma: sfaf_get_series_events() keeps its answer for the request.
$noext = at_event( 'Gamma two', $day( 12 ), 13 );
foreach ( array( $b1, $bp, $bx, $bd, $bc ) as $id ) { SFAF_Series::set_also_for_event( $id, array( 11 ) ); }
SFAF_Series::set_also_for_event( $g1, array( 11, 12 ) );

$shown = sfaf_series_dates_shown( 11 );
at( array( $a1, $g1, $a3, $b1, $a2 ) === $shown, 'P: Alpha\'s dates are not its own and Beta night and Gamma day in date order: ' . json_encode( $shown ) . ' wanted ' . json_encode( array( $a1, $g1, $a3, $b1, $a2 ) ) );
at( ! in_array( $bp, $shown, true ), 'P: a private event is listed' );
at( ! in_array( $bx, $shown, true ), 'P: a past event is listed' );
at( ! in_array( $bd, $shown, true ), 'P: a draft is listed' );
$page = sfaf_series_list_html( $a3 );
at( false !== strpos( $page, 'Upcoming in this series' ), 'P: the heading is not "Upcoming in this series"' );
$order = array();
foreach ( array( 'Alpha one', 'Gamma day', 'Beta night', 'Alpha two' ) as $t ) { $order[ $t ] = strpos( $page, '>' . $t . '<' ); }
at( false === strpos( $page, '>Alpha three<' ), 'P: the event lists itself' );
at( ! in_array( false, $order, true ) && $order['Alpha one'] < $order['Gamma day'] && $order['Gamma day'] < $order['Beta night'] && $order['Beta night'] < $order['Alpha two'],
    'P: Alpha three\'s page does not list Alpha one, Gamma day, Beta night, Alpha two in that order: ' . json_encode( $order ) );
at( in_array( $b1, sfaf_event_upcoming_dates( $a1 ), true ), 'P: the ended page\'s dates leave out Beta night' );
$own = sfaf_series_list_html( $b1 );
at( false === strpos( $own, 'Alpha one' ) && false !== strpos( $own, 'Gamma day' ), 'P: Beta night\'s own page does not list Beta\'s dates and Gamma day, or lists Alpha\'s' );
at( false === strpos( (string) sfaf_series_list_html( $g1 ), 'Alpha one' ), 'P: Gamma day\'s page lists the series it is only shown in' );
at( array( $g1, $noext ) === sfaf_series_dates_shown( 13 ), 'P: Gamma, with nobody shown in it, does not list exactly its own' );
/* And 3.142.1's output, where nothing is shown in Alpha: */
foreach ( array( $b1, $bp, $bx, $bd, $bc, $g1 ) as $id ) { SFAF_Series::set_also_for_event( $id, array() ); }
at( $before_list === sfaf_series_list_html( $a1 ) && $before_ended === sfaf_event_upcoming_dates( $a1 ), 'P: with no extras the event page\'s list is not what it was' );

/* ==== C: the series page =============================================== */
at_world();
$c1 = at_event( 'Alpha one', $day( 2 ), 11 );
$c2 = at_event( 'Beta night', $day( 5 ), 12 );
SFAF_Series::set_also_for_event( $c2, array( 11 ) );
$h = $cap( function () use ( $portal ) { kit_call( 'SFAF_Portal', 'render_schedule', $portal, array( 11 ) ); } );
at( 0 !== strpos( $h, 'THREW' ), 'C: the schedule did not render: ' . substr( $h, 0, 300 ) );
$doc = new DOMDocument(); @$doc->loadHTML( '<?xml encoding="utf-8"?><div>' . $h . '</div>' );
$x = new DOMXPath( $doc );
$rows = $x->query( '//ul[@data-uc-also-list]/li' );
at( 1 === $rows->length, 'C: "Also shown here" does not list Beta night once: ' . $rows->length );
if ( $rows->length ) {
    $t = preg_replace( '/\s+/', ' ', $rows->item( 0 )->textContent );
    at( false !== strpos( $t, 'also shown here' ) && false !== strpos( $t, 'in Beta' ) && false !== strpos( $t, 'Beta night' ), 'C: the row is not tagged "also shown here" with its own series: ' . trim( $t ) );
    at( 0 === $x->query( './/form|.//button', $rows->item( 0 ) )->length, 'C: the row has a Remove' );
}
$heads = array();
foreach ( $x->query( '//h4[contains(@class,"uc-schedule-head")]' ) as $hh ) { $heads[] = trim( $hh->textContent ); }
$iu = array_search( 'Upcoming', $heads, true ); $ia = array_search( 'Also shown here', $heads, true );
at( false !== $iu && false !== $ia && $ia > $iu, 'C: "Also shown here" is not after Upcoming: ' . json_encode( $heads ) );
$up = $x->query( '//ul[contains(@class,"uc-schedule-list") and not(@data-uc-also-list)]//li' );
$upt = ''; foreach ( $up as $li ) { $upt .= $li->textContent; }
at( false === strpos( $upt, 'Beta night' ), 'C: Beta night is in this series\' own Upcoming list' );
$h0 = $cap( function () use ( $portal ) { kit_call( 'SFAF_Portal', 'render_schedule', $portal, array( 12 ) ); } );
at( false === strpos( $h0, 'Also shown here' ), 'C: a series nobody is shown in has an "Also shown here" list' );

/* ==== D: the docs ====================================================== */
$pm = (string) file_get_contents( dirname( __DIR__ ) . '/PROJECT.md' );
$agreed = substr( $pm, (int) strpos( $pm, '## 8. Agreed, not built' ) );
at( false !== stripos( $agreed, 'Also show this event in series' ) && false !== stripos( $agreed, 'follower' ), 'D: PROJECT.md "Agreed, not built" does not say series followers count these events' );

if ( $fails ) {
    echo str_repeat( '=', 72 ) . "\n" . count( $fails ) . " of $checks FAILED:\n  - " . implode( "\n  - ", $fails ) . "\n";
    exit( 1 );
}
echo str_repeat( '=', 72 ) . "\n$checks checks passed: the store, the save, the field, the event page and the series page.\n";
