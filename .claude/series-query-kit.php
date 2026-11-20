<?php
/**
 * THE SERIES QUERIES, ANSWERED (3.143.0). Loaded after wp-kit.php by the
 * 3.143.0 checks: at_query() evaluates the tax_query and meta_query WP_Query
 * is given over the kit's posts, terms and meta, so SFAF_Series::events() and
 * also_events() really decide what is listed. at_world() resets the kit with
 * three series and multi-row _uc_also_series; at_event() writes an event.
 */

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
                case '<=':     if ( $r <= (string) $v ) { return true; } break;
                case '>':      if ( $r > (string) $v ) { return true; } break;
                case 'BETWEEN': $v = (array) $v; if ( $r >= (string) $v[0] && $r <= (string) $v[1] ) { return true; } break;
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
