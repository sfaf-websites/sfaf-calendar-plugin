<?php
/**
 * THE CALENDAR'S SERIES FILTER, ANSWERED (3.143.1). Loaded after wp-kit.php
 * and series-query-kit.php by the 3.143.1 checks.
 *
 * sf_query() evaluates what build_query_args() and month_grid_data() hand
 * WP_Query over the kit's posts, terms and meta: post__in (and post__not_in,
 * ignored beside it as WP_Query does), tax clauses by slug or term id, the
 * date window, and the privacy and cancellation clauses. So the series filter
 * really decides what the calendar lists.
 *
 * sf_world() writes the brief's case: World AIDS Day (14), Cycle to Zero (13),
 * Alpha (11); a Cycle to Zero event also shown in World AIDS Day, and one each
 * of the kinds that must stay out (private, draft, hidden cancelled, past).
 */

function sf_tax( $id, $g ) {
    $rel = isset( $g['relation'] ) ? strtoupper( $g['relation'] ) : 'AND';
    $any = false; $all = true; $n = 0;
    foreach ( $g as $k => $t ) {
        if ( 'relation' === $k || ! is_array( $t ) ) { continue; }
        $n++;
        if ( ! isset( $t['taxonomy'] ) ) {
            $ok = sf_tax( $id, $t );
        } else {
            $has  = isset( $GLOBALS['kit_terms'][ $id ][ $t['taxonomy'] ] ) ? array_map( 'intval', (array) $GLOBALS['kit_terms'][ $id ][ $t['taxonomy'] ] ) : array();
            $want = array();
            foreach ( (array) $t['terms'] as $w ) {
                if ( isset( $t['field'] ) && 'slug' === $t['field'] ) {
                    $term = get_term_by( 'slug', (string) $w, $t['taxonomy'] );
                    if ( $term ) { $want[] = (int) $term->term_id; }
                } else {
                    $want[] = (int) $w;
                }
            }
            $hit = (bool) array_intersect( $has, $want );
            $op  = isset( $t['operator'] ) ? strtoupper( $t['operator'] ) : 'IN';
            $ok  = ( 'NOT IN' === $op ) ? ! $hit : $hit;
        }
        $any = $any || $ok; $all = $all && $ok;
    }
    return 0 === $n ? true : ( 'OR' === $rel ? $any : $all );
}

function sf_query( $a ) {
    $type   = isset( $a['post_type'] ) ? (array) $a['post_type'] : array( 'uc_event' );
    $status = isset( $a['post_status'] ) ? (array) $a['post_status'] : array( 'publish' );
    $in     = ! empty( $a['post__in'] ) ? array_map( 'intval', (array) $a['post__in'] ) : null;
    $not    = ( null === $in && ! empty( $a['post__not_in'] ) ) ? array_map( 'intval', (array) $a['post__not_in'] ) : array();
    $GLOBALS['sf_queries'][] = $a;
    $out = array();
    foreach ( $GLOBALS['kit_posts'] as $id => $p ) {
        if ( ! in_array( $p->post_type, $type, true ) ) { continue; }
        if ( ! in_array( 'any', $status, true ) && ! in_array( $p->post_status, $status, true ) ) { continue; }
        if ( null !== $in && ! in_array( (int) $id, $in, true ) ) { continue; }
        if ( in_array( (int) $id, $not, true ) ) { continue; }
        if ( isset( $a['tax_query'] ) && ! sf_tax( (int) $id, (array) $a['tax_query'] ) ) { continue; }
        if ( isset( $a['meta_query'] ) && ! at_group( (int) $id, (array) $a['meta_query'] ) ) { continue; }
        $out[] = (int) $id;
    }
    usort( $out, function ( $x, $y ) {
        $dx = (string) get_post_meta( $x, '_uc_event_date', true ); $dy = (string) get_post_meta( $y, '_uc_event_date', true );
        return $dx === $dy ? $x - $y : strcmp( $dx, $dy );
    } );
    $per = isset( $a['posts_per_page'] ) ? (int) $a['posts_per_page'] : 10;
    if ( $per > 0 ) {
        $page = max( 1, isset( $a['paged'] ) ? (int) $a['paged'] : 1 );
        $out  = array_slice( $out, ( $page - 1 ) * $per, $per );
    }
    if ( isset( $a['fields'] ) && 'ids' === $a['fields'] ) { return $out; }
    return array_map( 'get_post', $out );
}

/** The world. Returns the ids by name. */
function sf_world() {
    at_world();
    $GLOBALS['kit_query']      = 'sf_query';
    $GLOBALS['kit_get_posts']  = 'sf_query';
    $GLOBALS['kit_loop']       = true;
    $GLOBALS['kit_object_ids'] = true;
    $GLOBALS['kit_more_terms'] = array( 13 => 'Cycle to Zero', 14 => 'World AIDS Day' );
    $GLOBALS['kit_term_slugs'] = array( 13 => 'cycle-to-zero', 14 => 'world-aids-day' );
    $GLOBALS['sf_queries']     = array();

    $today = current_time( 'Y-m-d' );
    $yday  = date( 'Y-m-d', strtotime( $today . ' -1 day' ) );
    $e = array();
    $e['vigil']   = at_event( 'World AIDS Day vigil', $today, 14 );
    $e['ride']    = at_event( 'Cycle to Zero ride', $today, 13 );
    $e['social']  = at_event( 'Cycle to Zero social', $today, 13 );
    $e['alpha']   = at_event( 'Alpha meetup', $today, 11 );
    $e['private'] = at_event( 'Cycle to Zero private', $today, 13, 'publish', array( SFAF_Privacy::META => '1' ) );
    $e['draft']   = at_event( 'Cycle to Zero draft', $today, 13, 'draft' );
    $e['hidden']  = at_event( 'Cycle to Zero hidden', $today, 13, 'publish', array( SFAF_Cancellation::META => '1', SFAF_Cancellation::VISIBILITY_META => 'hide' ) );
    $e['past']    = at_event( 'Cycle to Zero past', $yday, 13 );
    $e['pastown'] = at_event( 'World AIDS Day past', $yday, 14 );
    $e['spanish'] = at_event( 'Transformaciones', $today, 11, 'publish', array( '_uc_language' => 'es' ) );
    foreach ( array( 'ride', 'private', 'draft', 'hidden', 'past' ) as $k ) {
        SFAF_Series::set_also_for_event( $e[ $k ], array( 14 ) );
    }
    foreach ( $e as $id ) {
        update_post_meta( $id, '_uc_end_time', '19:30' );
        update_post_meta( $id, '_uc_location', '1035 Market St' );
    }
    return $e;
}
