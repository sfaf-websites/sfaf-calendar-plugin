<?php
/**
 * Multi-site API server.
 *
 * The main site exposes the read-only GET /events endpoint (registered in the
 * main plugin file) and generates the shared API key. It does NOT pull from or
 * push to anywhere — satellites pull from it via the SFAF Calendar Satellite
 * plugin.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class SFAF_Sync {

    public function register() {
        add_action( 'wp_ajax_uc_generate_key', array( $this, 'ajax_generate_key' ) );
    }

    /**
     * Generate + store a 32-char API key (AJAX, no page reload).
     */
    public function ajax_generate_key() {
        check_ajax_referer( 'uc_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Denied' ), 403 );
        }
        $key = wp_generate_password( 32, false );

        $settings = get_option( 'uc_settings', array() );
        $settings['multisite_api_key'] = $key;
        update_option( 'uc_settings', $settings );

        wp_send_json_success( array( 'key' => $key ) );
    }

    /**
     * Full event payload for the REST GET /events endpoint (consumed by
     * satellite sites). Includes every field a satellite needs to display the
     * event identically.
     */
    public function event_to_array( $post_id ) {
        $post = get_post( $post_id );

        // Category names + colour map.
        $cat_terms = wp_get_post_terms( $post_id, 'uc_event_category' );
        $categories = array();
        $cat_colors = array();
        if ( ! is_wp_error( $cat_terms ) ) {
            foreach ( $cat_terms as $t ) {
                $categories[] = $t->name;
                $color = get_term_meta( $t->term_id, '_uc_category_color', true );
                if ( $color ) {
                    $cat_colors[ $t->name ] = $color;
                }
            }
        }

        return array(
            'id'              => $post_id,
            'source_id'       => (int) ( get_post_meta( $post_id, '_uc_source_id', true ) ?: $post_id ),
            'source_site'     => get_post_meta( $post_id, '_uc_source_site', true ) ?: home_url(),
            'title'           => $post->post_title,
            'slug'            => $post->post_name,
            'content'         => $post->post_content,
            'excerpt'         => $post->post_excerpt,
            'status'          => $post->post_status,
            'date'            => get_post_meta( $post_id, '_uc_event_date', true ),
            'start_time'      => get_post_meta( $post_id, '_uc_start_time', true ),
            'end_time'        => get_post_meta( $post_id, '_uc_end_time', true ),
            'location'        => get_post_meta( $post_id, '_uc_location', true ),
            'recurrence'      => get_post_meta( $post_id, '_uc_recurrence', true ),
            'capacity'        => get_post_meta( $post_id, '_uc_capacity', true ),
            'rsvp_enabled'    => get_post_meta( $post_id, '_uc_rsvp_enabled', true ),
            'gofundme_url'    => get_post_meta( $post_id, '_uc_gofundme_url', true ),
            'gofundme_goal'   => get_post_meta( $post_id, '_uc_gofundme_goal', true ),
            'series_id'       => get_post_meta( $post_id, '_uc_series_id', true ),
            'series_parent'   => get_post_meta( $post_id, '_uc_series_parent', true ),
            'series_name'     => ( $sp = (int) get_post_meta( $post_id, '_uc_series_parent', true ) ) ? get_the_title( $sp ) : '',
            'series_image'    => ( $sp = (int) get_post_meta( $post_id, '_uc_series_parent', true ) ) ? sfaf_series_image_url( $sp ) : '',
            'show'            => array(
                'rsvp'      => get_post_meta( $post_id, '_uc_show_rsvp', true ),
                'donate'    => get_post_meta( $post_id, '_uc_show_donate', true ),
                'social'    => get_post_meta( $post_id, '_uc_show_social', true ),
                'calendar'  => get_post_meta( $post_id, '_uc_show_calendar', true ),
                'reminders' => get_post_meta( $post_id, '_uc_show_reminders', true ),
            ),
            'faq'             => sfaf_get_faqs( $post_id ),
            'rsvp_count'      => sfaf_get_rsvp_count( $post_id ),
            // Legacy (names + colour map) kept for older satellites.
            'categories'      => $categories,
            'category_colors' => $cat_colors,
            'organizer'       => wp_get_post_terms( $post_id, 'uc_organizer', array( 'fields' => 'names' ) ),
            'venue'           => wp_get_post_terms( $post_id, 'uc_venue', array( 'fields' => 'names' ) ),
            // Full term identity (stable id + slug + colour) so satellites can sync
            // renames, slug changes and colour changes onto their local terms.
            'terms'           => array(
                'uc_event_category' => $this->term_payload( $post_id, 'uc_event_category', true ),
                'uc_organizer'      => $this->term_payload( $post_id, 'uc_organizer', false ),
                'uc_venue'          => $this->term_payload( $post_id, 'uc_venue', false ),
            ),
            'image'           => sfaf_event_image_url( $post_id ),
            'permalink'       => get_permalink( $post_id ),
        );
    }

    /**
     * Term identity for one taxonomy on an event: id, name, slug (+ colour for
     * categories). Lets satellites match terms by a stable id and update name/
     * slug/colour instead of silently creating duplicates on a rename.
     */
    private function term_payload( $post_id, $taxonomy, $with_color ) {
        $terms = wp_get_post_terms( $post_id, $taxonomy );
        $out   = array();
        if ( is_wp_error( $terms ) ) {
            return $out;
        }
        foreach ( $terms as $t ) {
            $row = array(
                'id'   => (int) $t->term_id,
                'name' => $t->name,
                'slug' => $t->slug,
            );
            if ( $with_color ) {
                $row['color'] = get_term_meta( $t->term_id, '_uc_category_color', true );
            }
            $out[] = $row;
        }
        return $out;
    }
}
