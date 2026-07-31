<?php
/**
 * SEO / AEO / GEO output for single event pages:
 * Event + FAQ + Breadcrumb JSON-LD, Open Graph, Twitter cards, sitemap.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class SFAF_SEO {

    public function register() {
        add_action( 'wp_head', array( $this, 'output' ), 5 );
        add_filter( 'wp_sitemaps_post_types', array( $this, 'sitemap_post_types' ) );
    }

    /** Ensure events appear in WP's native XML sitemap. */
    public function sitemap_post_types( $post_types ) {
        if ( ! isset( $post_types['uc_event'] ) ) {
            $obj = get_post_type_object( 'uc_event' );
            if ( $obj ) {
                $post_types['uc_event'] = $obj;
            }
        }
        return $post_types;
    }

    public function output() {
        if ( ! is_singular( 'uc_event' ) ) {
            return;
        }
        $id = get_queried_object_id();

        $this->open_graph( $id );
        $this->twitter_card( $id );
        $this->json_ld( '/* Event */', $this->event_schema( $id ) );

        $faq = $this->faq_schema( $id );
        if ( $faq ) {
            $this->json_ld( '/* FAQ */', $faq );
        }
        $this->json_ld( '/* Breadcrumb */', $this->breadcrumb_schema( $id ) );
    }

    /* ---------------------------------------------------------------------
     * Shared bits
     * ------------------------------------------------------------------- */

    private function description( $id ) {
        $excerpt = get_the_excerpt( $id );
        return wp_strip_all_tags( $excerpt ? $excerpt : wp_trim_words( get_post_field( 'post_content', $id ), 40 ) );
    }

    private function image_url( $id ) {
        // Featured image → _uc_image_url → _uc_remote_image_url (synced).
        return sfaf_event_image_url( $id );
    }

    private function organizer_name( $id ) {
        $orgs = wp_get_post_terms( $id, 'uc_organizer', array( 'fields' => 'names' ) );
        return ( ! is_wp_error( $orgs ) && ! empty( $orgs ) ) ? $orgs[0] : '';
    }

    private function json_ld( $label, $data ) {
        echo "\n<script type=\"application/ld+json\">";
        echo wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP );
        echo "</script>\n";
    }

    /* ---------------------------------------------------------------------
     * Event schema
     * ------------------------------------------------------------------- */

    private function event_schema( $id ) {
        $dt    = sfaf_event_datetimes( $id );
        $loc   = get_post_meta( $id, '_uc_location', true );
        $parts = array_map( 'trim', explode( ',', (string) $loc ) );
        $street   = isset( $parts[0] ) ? $parts[0] : (string) $loc;
        $locality = ( isset( $parts[1] ) && $parts[1] !== '' ) ? $parts[1] : 'San Francisco';
        $region   = ( isset( $parts[2] ) && $parts[2] !== '' ) ? $parts[2] : 'CA';
        $org      = $this->organizer_name( $id );

        $schema = array(
            '@context'            => 'https://schema.org',
            '@type'               => 'Event',
            'name'                => get_the_title( $id ),
            'description'         => $this->description( $id ),
            'eventStatus'         => 'https://schema.org/EventScheduled',
            'eventAttendanceMode' => 'https://schema.org/OfflineEventAttendanceMode',
            'url'                 => get_permalink( $id ),
            'location'            => array(
                '@type'   => 'Place',
                'name'    => $loc ? $loc : 'San Francisco AIDS Foundation',
                'address' => array(
                    '@type'           => 'PostalAddress',
                    'streetAddress'   => $street,
                    'addressLocality' => $locality,
                    'addressRegion'   => $region,
                    'postalCode'      => '',
                    'addressCountry'  => 'US',
                ),
            ),
        );

        if ( $dt ) {
            $schema['startDate'] = $dt[0]->format( 'c' );
            $schema['endDate']   = $dt[1]->format( 'c' );
        }

        if ( $org ) {
            $schema['organizer'] = array( '@type' => 'Organization', 'name' => $org );
            $schema['performer'] = array( '@type' => 'Organization', 'name' => $org );
        }

        $image = $this->image_url( $id );
        if ( $image ) {
            $schema['image'] = $image;
        }

        // Offers when RSVP is enabled.
        if ( get_post_meta( $id, '_uc_rsvp_enabled', true ) === '1' ) {
            $capacity = (int) get_post_meta( $id, '_uc_capacity', true );
            $count    = sfaf_get_rsvp_count( $id );
            $sold_out = $capacity > 0 && $count >= $capacity;
            $schema['offers'] = array(
                '@type'         => 'Offer',
                'price'         => '0',
                'priceCurrency' => 'USD',
                'availability'  => $sold_out ? 'https://schema.org/SoldOut' : 'https://schema.org/InStock',
                'url'           => get_permalink( $id ),
                'validFrom'     => get_post_time( 'c', true, $id ),
            );
        }

        // Recurring series → reference the parent as a superEvent.
        if ( sfaf_is_in_series( $id ) ) {
            $parent = sfaf_series_parent_id( $id );
            $schema['superEvent'] = array(
                '@type' => 'EventSeries',
                'name'  => get_the_title( $parent ),
                'url'   => get_permalink( $parent ),
            );
        }

        return $schema;
    }

    /* ---------------------------------------------------------------------
     * FAQ schema
     * ------------------------------------------------------------------- */

    private function faq_schema( $id ) {
        $faqs = sfaf_get_faqs( $id );
        if ( empty( $faqs ) ) {
            return null;
        }
        $entities = array();
        foreach ( $faqs as $f ) {
            $entities[] = array(
                '@type'          => 'Question',
                'name'           => $f['question'],
                'acceptedAnswer' => array(
                    '@type' => 'Answer',
                    'text'  => $f['answer'],
                ),
            );
        }
        return array(
            '@context'   => 'https://schema.org',
            '@type'      => 'FAQPage',
            'mainEntity' => $entities,
        );
    }

    /* ---------------------------------------------------------------------
     * Breadcrumb schema
     * ------------------------------------------------------------------- */

    private function breadcrumb_schema( $id ) {
        $items = array();
        $pos   = 1;

        $items[] = array( '@type' => 'ListItem', 'position' => $pos++, 'name' => 'Home', 'item' => home_url( '/' ) );

        $archive = get_post_type_archive_link( 'uc_event' );
        if ( $archive ) {
            $items[] = array( '@type' => 'ListItem', 'position' => $pos++, 'name' => 'Events', 'item' => $archive );
        }

        $cats = wp_get_post_terms( $id, 'uc_event_category' );
        if ( ! is_wp_error( $cats ) && ! empty( $cats ) ) {
            $link = get_term_link( $cats[0] );
            $items[] = array(
                '@type'    => 'ListItem',
                'position' => $pos++,
                'name'     => $cats[0]->name,
                'item'     => is_wp_error( $link ) ? get_permalink( $id ) : $link,
            );
        }

        $items[] = array( '@type' => 'ListItem', 'position' => $pos++, 'name' => get_the_title( $id ), 'item' => get_permalink( $id ) );

        return array(
            '@context'        => 'https://schema.org',
            '@type'           => 'BreadcrumbList',
            'itemListElement' => $items,
        );
    }

    /* ---------------------------------------------------------------------
     * Open Graph + Twitter
     * ------------------------------------------------------------------- */

    private function open_graph( $id ) {
        $dt    = sfaf_event_datetimes( $id );
        $image = $this->image_url( $id );

        $tags = array(
            'og:title'       => get_the_title( $id ),
            'og:description' => $this->description( $id ),
            'og:type'        => 'event',
            'og:url'         => get_permalink( $id ),
            'og:site_name'   => get_bloginfo( 'name' ),
        );
        if ( $image ) {
            $tags['og:image'] = $image;
        }
        if ( $dt ) {
            $tags['event:start_time'] = $dt[0]->format( 'c' );
            $tags['event:end_time']   = $dt[1]->format( 'c' );
        }

        foreach ( $tags as $property => $content ) {
            echo '<meta property="' . esc_attr( $property ) . '" content="' . esc_attr( $content ) . '" />' . "\n";
        }
    }

    private function twitter_card( $id ) {
        $image = $this->image_url( $id );
        $tags  = array(
            'twitter:card'        => 'summary_large_image',
            'twitter:title'       => get_the_title( $id ),
            'twitter:description' => $this->description( $id ),
        );
        if ( $image ) {
            $tags['twitter:image'] = $image;
        }
        foreach ( $tags as $name => $content ) {
            echo '<meta name="' . esc_attr( $name ) . '" content="' . esc_attr( $content ) . '" />' . "\n";
        }
    }
}
