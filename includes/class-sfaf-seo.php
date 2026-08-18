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

        /*
         * NOTHING AT ALL ON A PRIVATE EVENT. Not the JSON-LD, not the Open
         * Graph tags, not the Twitter card.
         *
         * The page itself still renders in full: this is the direct link and it
         * has to work. What is suppressed is everything on it whose only
         * audience is a machine that catalogues pages. Event JSON-LD is an
         * invitation to a search engine to list the event with its date, place
         * and registration link, which is precisely what a private event is
         * not for, and the Open Graph tags are what makes a forwarded link
         * unfurl into a titled preview card in a public channel.
         *
         * SFAF_Privacy::robots() marks the page noindex and nofollow, and a
         * crawler that honours that will not read this block anyway. This is
         * the half that does not depend on anybody honouring anything.
         */
        if ( SFAF_Privacy::is_private( $id ) ) {
            return;
        }

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

    /**
     * The organizers, as schema.org expects them.
     *
     * AN ARRAY WHEN THERE ARE SEVERAL, not a string with "and" in it.
     * schema.org/Event declares `organizer` as accepting one value or many, and
     * a search engine reading "A and B" gets one organisation with a strange
     * name. The prose joining is for people; this is for machines, and they
     * want the list.
     *
     * One organizer still emits exactly what it always did: a single
     * Organization object, not an array of one.
     *
     * @param int $id
     * @return array|null
     */
    private function organizer_schema( $id ) {
        $names = SFAF_Organizers::names_for_event( $id );
        if ( empty( $names ) ) {
            return null;
        }

        $one = function ( $name ) {
            return array( '@type' => 'Organization', 'name' => $name );
        };

        if ( 1 === count( $names ) ) {
            return $one( $names[0] );
        }
        return array_map( $one, $names );
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
        $loc   = sfaf_event_location( $id );
        $parts = array_map( 'trim', explode( ',', (string) $loc ) );
        $street   = isset( $parts[0] ) ? $parts[0] : (string) $loc;
        $locality = ( isset( $parts[1] ) && $parts[1] !== '' ) ? $parts[1] : 'San Francisco';
        $region   = ( isset( $parts[2] ) && $parts[2] !== '' ) ? $parts[2] : 'CA';
        $org      = $this->organizer_schema( $id );

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
            $schema['organizer'] = $org;
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

        // Part of a series → reference it as a superEvent. The series is a term
        // now, so the URL is its archive: the same address the "Part of series"
        // badge links to, which is what schema.org means by a superEvent's url.
        $series = SFAF_Series::for_event( $id );
        if ( $series ) {
            $schema['superEvent'] = array(
                '@type' => 'EventSeries',
                'name'  => $series->name,
                'url'   => SFAF_Series::url( $series->term_id ),
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

        // A breadcrumb is a single trail, so it names the first category, using
        // the same "first" every other surface uses. See sfaf_event_categories().
        $cats = sfaf_event_categories( $id );
        if ( ! empty( $cats ) ) {
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
