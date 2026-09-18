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

    /**
     * The event, in one line, for a meta tag and for JSON-LD.
     *
     * FLATTENED, NOT STRIPPED, AND THIS WAS WRONG UNTIL 3.44.0.
     *
     * Both halves had the fault 3.38.0 found in wp_trim_words(): it calls
     * wp_strip_all_tags(), which joins the text either side of a tag with
     * nothing between, so a description of two paragraphs came out as
     * "...the first oneThe second one...". get_the_excerpt() has it too when
     * WordPress builds the excerpt itself, because it runs the content through
     * the_content, which wraps it in paragraphs, and then trims it the same way.
     *
     * The description has been rich text since 3.38.0, so this has been
     * producing joined words in the page's meta description and in the
     * schema.org payload for several releases. Nothing on screen showed it.
     *
     * FLATTEN FIRST, THEN TRIM. wp_trim_words() is kept for the length, and it
     * is safe once there are no tags left for it to join across.
     *
     * @param int $id
     * @return string
     */
    private function description( $id ) {
        /*
         * ONE RESOLVER, AND NOT THE EXCERPT (3.98.0). Same fault as the card
         * summary: on a generated occurrence get_the_excerpt() is the SEED's,
         * so the meta description and the JSON-LD described the series while
         * the page described the event. Google reads this one.
         */
        return wp_trim_words( sfaf_event_description_text( $id ), 40 );
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

        /*
         * ONLINE: A VirtualLocation, AND ITS url IS THE EVENT PAGE.
         *
         * schema.org says a VirtualLocation's url is where the event happens,
         * which for us is the meeting link, and that is exactly why it is not
         * put there. This block is emitted into the public head, is read by
         * every crawler that visits and is quoted back in search results. It is
         * one of the surfaces SFAF_Online exists to keep the link off.
         *
         * The event page is the honest substitute rather than a fudge: it is
         * where somebody finds out how to attend, which is what a reader of
         * this property is trying to establish. The PostalAddress above is
         * REPLACED and not merely blanked, because a Place with an empty street
         * and a defaulted "San Francisco, CA" would assert an address for an
         * event that has none.
         */
        if ( SFAF_Online::is_online( $id ) ) {
            $schema['eventAttendanceMode'] = 'https://schema.org/OnlineEventAttendanceMode';
            $schema['location']            = array(
                '@type' => 'VirtualLocation',
                'name'  => SFAF_Online::LABEL,
                'url'   => get_permalink( $id ),
            );
        }

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
                /*
                 * PLAIN TEXT. An answer is rich text since 3.44.0, and
                 * schema.org wants the words: markup here is either ignored or
                 * shown verbatim in a search result. Flattened, never stripped,
                 * so two paragraphs do not arrive joined into one word.
                 */
                'acceptedAnswer' => array(
                    '@type' => 'Answer',
                    'text'  => SFAF_Rich_Text::to_plain( $f['answer'] ),
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
