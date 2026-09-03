<?php
function wxr_items( $file ) {
    $xml = simplexml_load_file( $file, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_PARSEHUGE );
    $ns  = $xml->getNamespaces( true );
    $out = array();
    foreach ( $xml->channel->item as $item ) {
        $wp = $item->children( $ns['wp'] );
        $c  = $item->children( $ns['content'] );
        $e  = $item->children( $ns['excerpt'] );
        $meta = array();
        foreach ( $wp->postmeta as $m ) { $meta[ (string) $m->meta_key ][] = (string) $m->meta_value; }
        $cats = array();
        foreach ( $item->category as $cc ) { $cats[ (string) $cc['domain'] ][] = html_entity_decode( (string) $cc, ENT_QUOTES, 'UTF-8' ); }
        $out[] = array(
            'id'      => (int) $wp->post_id,
            'title'   => html_entity_decode( (string) $item->title, ENT_QUOTES, 'UTF-8' ),
            'type'    => (string) $wp->post_type,
            'status'  => (string) $wp->status,
            'parent'  => (int) $wp->post_parent,
            'slug'    => (string) $wp->post_name,
            'link'    => (string) $item->link,
            'guid'    => (string) $item->guid,
            'attach'  => (string) $wp->attachment_url,
            'content' => (string) $c->encoded,
            'excerpt' => (string) $e->encoded,
            'meta'    => $meta,
            'cats'    => $cats,
        );
    }
    return $out;
}
function m1( $it, $k ) { return isset( $it['meta'][ $k ][0] ) ? $it['meta'][ $k ][0] : ''; }
/** Summarise a TEC _EventRecurrence blob into readable rules. */
function rec_summary( $blob ) {
    if ( '' === $blob ) return '';
    $d = @unserialize( $blob );
    if ( ! is_array( $d ) || empty( $d['rules'] ) ) return 'UNPARSED';
    $bits = array();
    foreach ( $d['rules'] as $r ) {
        $type = $r['type'] ?? '?';
        $cust = $r['custom'] ?? array();
        $ct   = $cust['type'] ?? '';
        $iv   = $cust['interval'] ?? '';
        $days = isset( $cust['week']['day'] ) ? implode( ',', (array) $cust['week']['day'] ) : '';
        $mon  = isset( $cust['month'] ) ? json_encode( $cust['month'] ) : '';
        $et   = $r['end-type'] ?? '';
        $end  = $r['end'] ?? '';
        $cnt  = $r['end-count'] ?? '';
        $bits[] = trim( "$type/$ct iv=$iv days=$days $mon end-type=$et end=$end count=$cnt" );
    }
    return implode( ' || ', $bits );
}
