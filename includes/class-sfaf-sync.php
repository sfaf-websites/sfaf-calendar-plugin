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

        // The key lives in the dedicated credential store, not in uc_settings —
        // that option is rebuilt wholesale on every settings save, which is how
        // credentials kept going missing. See class-sfaf-credentials.php.
        SFAF_Credentials::set( 'multisite_api_key', $key );

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
            'location'        => sfaf_event_location( $post_id ),
            'recurrence'      => SFAF_Recurrence::pattern_of( $post_id ),
            'capacity'        => get_post_meta( $post_id, '_uc_capacity', true ),
            'rsvp_enabled'    => get_post_meta( $post_id, '_uc_rsvp_enabled', true ),
            'gofundme_url'    => get_post_meta( $post_id, '_uc_gofundme_url', true ),
            'gofundme_goal'   => get_post_meta( $post_id, '_uc_gofundme_goal', true ),
            /*
             * SERIES, AND THE KEYS KEPT FOR SATELLITES THAT PREDATE 3.0.0.
             *
             * series_id and series_parent used to be post IDs; a series is a
             * term now and there is no parent. Both keys stay in the payload,
             * carrying the term ID, because a satellite reading them only ever
             * used them to answer "are these two events part of the same
             * thing", and the term ID answers that identically. Dropping the
             * keys would have broken every satellite at once; changing what
             * they mean under the same names would be worse.
             *
             * recurrence_group is the new, separate fact: which events were
             * generated together. See SFAF_Recurrence.
             */
            'series_id'       => SFAF_Series::id_for_event( $post_id ),
            'series_parent'   => SFAF_Series::id_for_event( $post_id ),
            'series_name'     => SFAF_Series::name_for_event( $post_id ),
            'series_image'    => sfaf_series_image_url( SFAF_Series::id_for_event( $post_id ) ),
            'recurrence_group'=> SFAF_Recurrence::group_of( $post_id ),
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
