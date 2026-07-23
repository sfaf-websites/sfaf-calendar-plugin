<?php
/**
 * Satellite sync: pull events from the main site and store them locally.
 * Receive-only — never pushes anything back.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class SFAF_Sat_Sync {

    const CRON = 'sfaf_sat_sync_cron';
    const OPT  = 'sfaf_sat_settings';

    /** Guard against re-entrancy during imports. */
    private static $importing = false;

    public function register() {
        add_filter( 'cron_schedules', array( $this, 'cron_schedules' ) );
        add_action( self::CRON, array( $this, 'run_sync' ) );
        add_action( 'wp_ajax_sfaf_sat_sync_now', array( $this, 'ajax_sync_now' ) );
        add_action( 'wp_ajax_sfaf_sat_reset_events', array( $this, 'ajax_reset_events' ) );
        add_action( 'update_option_' . self::OPT, array( $this, 'reschedule' ) );
        // register() runs on init, so schedule directly.
        self::ensure_cron_static();
    }

    /* ---------------------------------------------------------------------
     * Settings
     * ------------------------------------------------------------------- */

    public static function settings() {
        $s = get_option( self::OPT, array() );
        return is_array( $s ) ? $s : array();
    }

    private static function get( $key, $default = '' ) {
        $s = self::settings();
        return isset( $s[ $key ] ) && $s[ $key ] !== '' ? $s[ $key ] : $default;
    }

    /* ---------------------------------------------------------------------
     * Cron
     * ------------------------------------------------------------------- */

    public function cron_schedules( $schedules ) {
        $min = (int) self::get( 'sync_interval', 15 );
        if ( $min < 1 ) {
            $min = 15;
        }
        $schedules['sfaf_sat'] = array(
            'interval' => $min * 60,
            'display'  => 'SFAF Satellite sync interval',
        );
        return $schedules;
    }

    public static function ensure_cron_static() {
        if ( ! wp_next_scheduled( self::CRON ) ) {
            wp_schedule_event( time() + 60, 'sfaf_sat', self::CRON );
        }
    }

    public static function clear_cron() {
        $ts = wp_next_scheduled( self::CRON );
        if ( $ts ) {
            wp_unschedule_event( $ts, self::CRON );
        }
    }

    public function reschedule() {
        self::clear_cron();
        self::ensure_cron_static();
    }

    /* ---------------------------------------------------------------------
     * AJAX (Sync Now)
     * ------------------------------------------------------------------- */

    public function ajax_sync_now() {
        check_ajax_referer( 'sfaf_sat_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Denied' ), 403 );
        }
        $result = $this->run_sync();
        $result['last_sync'] = get_option( 'sfaf_sat_last_sync', '' );
        wp_send_json_success( $result );
    }

    /**
     * AJAX (Reset All Events): permanently delete every locally stored event and
     * its postmeta/term relationships, so the admin can clear stale data and pull
     * a fresh copy without touching the database directly. The main site is never
     * touched — the satellite only ever holds copies.
     */
    public function ajax_reset_events() {
        check_ajax_referer( 'sfaf_sat_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Denied' ), 403 );
        }

        $ids = get_posts( array(
            'post_type'      => 'uc_event',
            'post_status'    => array( 'publish', 'pending', 'draft', 'future', 'private', 'trash' ),
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ) );

        $deleted = 0;
        foreach ( $ids as $id ) {
            // force delete: removes the post, its postmeta and term relationships
            // (bypasses Trash so nothing lingers).
            $thumb = (int) get_post_thumbnail_id( $id );
            if ( wp_delete_post( (int) $id, true ) ) {
                $deleted++;
                // Remove the locally downloaded image too (once unreferenced).
                $this->maybe_delete_orphan_image( $thumb, 0 );
            }
        }

        // Clear the last-sync marker so the UI reflects the clean slate.
        delete_option( 'sfaf_sat_last_sync' );

        wp_send_json_success( array( 'deleted' => $deleted ) );
    }

    /* ---------------------------------------------------------------------
     * Pull
     * ------------------------------------------------------------------- */

    public function run_sync() {
        $main = self::get( 'main_site_url' );
        $key  = self::get( 'api_key' );
        if ( ! $main ) {
            return array( 'success' => false, 'message' => 'No main site URL configured.', 'synced' => 0 );
        }

        $source   = esc_url_raw( $main );
        $base     = trailingslashit( $main ) . 'wp-json/sfaf-calendar/v1/events';
        $per_page = 100;
        $page     = 1;
        $seen     = array();   // remote source_id => true, for every event in the feed
        $synced   = 0;
        $total    = 0;

        // Pull every page so the reconciliation below sees the complete feed. If
        // any request fails we abort BEFORE deleting anything, so a transient
        // network error can never wipe the local mirror.
        do {
            $url  = add_query_arg( array( 'per_page' => $per_page, 'page' => $page ), $base );
            $resp = wp_remote_get( $url, array(
                'timeout' => 20,
                'headers' => array( 'X-SFAF-API-Key' => $key ),
            ) );

            if ( is_wp_error( $resp ) ) {
                return array( 'success' => false, 'message' => $resp->get_error_message(), 'synced' => $synced );
            }
            $code = (int) wp_remote_retrieve_response_code( $resp );
            if ( $code !== 200 ) {
                return array( 'success' => false, 'message' => 'Main site returned HTTP ' . $code . ' (check the URL and API key).', 'synced' => $synced );
            }

            $data = json_decode( wp_remote_retrieve_body( $resp ), true );
            // A valid feed always carries the 'events' key (possibly an empty
            // array). Anything else means we hit the wrong endpoint — bail rather
            // than treat it as "no events" and delete the whole mirror.
            if ( ! is_array( $data ) || ! array_key_exists( 'events', $data ) ) {
                return array( 'success' => false, 'message' => 'Unexpected response from main site (is the URL correct?).', 'synced' => $synced );
            }

            $events = is_array( $data['events'] ) ? $data['events'] : array();
            $total  = isset( $data['total'] ) ? (int) $data['total'] : count( $events );

            foreach ( $events as $event ) {
                $sid = isset( $event['source_id'] ) ? (int) $event['source_id'] : ( isset( $event['id'] ) ? (int) $event['id'] : 0 );
                if ( $sid ) {
                    $seen[ $sid ] = true;
                }
                if ( $this->upsert_event( $event, $source ) ) {
                    $synced++;
                }
            }

            $got = count( $events );
            $page++;
        } while ( $got === $per_page && count( $seen ) < $total && $page <= 200 );

        // Reconcile: the feed is the source of truth, so any locally synced event
        // no longer present on the main (trashed, deleted, or a removed series
        // occurrence) is force-deleted along with its postmeta and term links.
        $deleted = $this->reconcile_deletions( $seen );

        $this->link_series( $source );

        update_option( 'sfaf_sat_last_sync', current_time( 'mysql' ) );
        return array( 'success' => true, 'synced' => $synced, 'deleted' => $deleted, 'total' => $total );
    }

    /**
     * Delete every locally synced event (one carrying _uc_source_site) whose
     * source id is absent from the latest feed. Force-deletes so postmeta and
     * term relationships go too.
     *
     * @param array $seen Map of remote source_id => true present in the feed.
     * @return int Number of events deleted.
     */
    private function reconcile_deletions( $seen ) {
        $ids = get_posts( array(
            'post_type'      => 'uc_event',
            'post_status'    => array( 'publish', 'pending', 'draft', 'future', 'private', 'trash' ),
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'meta_query'     => array( array( 'key' => '_uc_source_site', 'compare' => 'EXISTS' ) ),
        ) );

        $deleted = 0;
        foreach ( $ids as $id ) {
            $sid = (int) get_post_meta( $id, '_uc_source_id', true );
            if ( ! $sid || empty( $seen[ $sid ] ) ) {
                $thumb = (int) get_post_thumbnail_id( $id );
                if ( wp_delete_post( (int) $id, true ) ) {
                    $deleted++;
                    // Clean up the downloaded image once nothing else uses it.
                    $this->maybe_delete_orphan_image( $thumb, 0 );
                }
            }
        }
        return $deleted;
    }

    /**
     * Create or update a local event from a payload.
     *
     * @return int|false Local post ID or false on skip.
     */
    private function upsert_event( $data, $source_site ) {
        $source_id = isset( $data['source_id'] ) ? (int) $data['source_id'] : ( isset( $data['id'] ) ? (int) $data['id'] : 0 );
        if ( ! $source_id ) {
            return false;
        }

        $existing = get_posts( array(
            'post_type'      => 'uc_event',
            'post_status'    => 'any',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'meta_query'     => array(
                'relation' => 'AND',
                array( 'key' => '_uc_source_site', 'value' => $source_site ),
                array( 'key' => '_uc_source_id', 'value' => $source_id ),
            ),
        ) );

        $postarr = array(
            'post_type'    => 'uc_event',
            'post_status'  => 'publish',
            'post_title'   => isset( $data['title'] ) ? sanitize_text_field( $data['title'] ) : '(untitled event)',
            'post_content' => isset( $data['content'] ) ? wp_kses_post( $data['content'] ) : '',
            'post_excerpt' => isset( $data['excerpt'] ) ? wp_kses_post( $data['excerpt'] ) : '',
        );

        // Mirror the main site's slug so satellite URLs match (including the dated
        // "{parent-slug}-YYYY-MM-DD" form for recurring occurrences). Set on both
        // insert and update so a slug change on the main propagates here.
        if ( isset( $data['slug'] ) && $data['slug'] !== '' ) {
            $postarr['post_name'] = sanitize_title( $data['slug'] );
        }

        self::$importing = true;
        if ( ! empty( $existing ) ) {
            $post_id = (int) $existing[0];
            $postarr['ID'] = $post_id;
            wp_update_post( $postarr );
        } else {
            $post_id = wp_insert_post( $postarr, true );
        }
        if ( is_wp_error( $post_id ) ) {
            self::$importing = false;
            return false;
        }

        // Scalar meta (no _uc_end_date — the main already expanded recurrences
        // into individual events, so the satellite never generates a series).
        $scalars = array(
            '_uc_event_date'   => 'date',
            '_uc_start_time'   => 'start_time',
            '_uc_end_time'     => 'end_time',
            '_uc_location'     => 'location',
            '_uc_recurrence'   => 'recurrence',
            '_uc_capacity'     => 'capacity',
            '_uc_rsvp_enabled' => 'rsvp_enabled',
            '_uc_gofundme_goal'=> 'gofundme_goal',
        );
        foreach ( $scalars as $key => $field ) {
            if ( isset( $data[ $field ] ) ) {
                update_post_meta( $post_id, $key, sanitize_text_field( $data[ $field ] ) );
            }
        }
        if ( isset( $data['gofundme_url'] ) ) {
            update_post_meta( $post_id, '_uc_gofundme_url', esc_url_raw( $data['gofundme_url'] ) );
        }

        // Display toggles.
        if ( isset( $data['show'] ) && is_array( $data['show'] ) ) {
            foreach ( array( 'rsvp', 'donate', 'social', 'calendar', 'reminders' ) as $f ) {
                if ( isset( $data['show'][ $f ] ) ) {
                    update_post_meta( $post_id, '_uc_show_' . $f, sanitize_text_field( $data['show'][ $f ] ) );
                }
            }
        }

        // FAQ. The main already resolved the effective FAQ per event. Store it so
        // the shared sfaf_get_faqs() returns exactly that: on the series parent it
        // reads _uc_series_faq; on every other event it reads _uc_event_faq (with
        // the replace override on) — either way the displayed FAQ matches the main.
        $eff_faq = array();
        if ( isset( $data['faq'] ) && is_array( $data['faq'] ) ) {
            foreach ( $data['faq'] as $row ) {
                $eff_faq[] = array(
                    'question' => isset( $row['question'] ) ? sanitize_text_field( $row['question'] ) : '',
                    'answer'   => isset( $row['answer'] ) ? sanitize_textarea_field( $row['answer'] ) : '',
                );
            }
        }
        $is_parent = ( isset( $data['series_id'] ) && $data['series_id'] !== '' && (int) $data['series_id'] === $source_id );
        if ( $is_parent ) {
            update_post_meta( $post_id, '_uc_series_faq', $eff_faq );
            delete_post_meta( $post_id, '_uc_event_faq' );
            delete_post_meta( $post_id, '_uc_faq_override' );
        } else {
            update_post_meta( $post_id, '_uc_event_faq', $eff_faq );
            update_post_meta( $post_id, '_uc_faq_override', '1' );
            delete_post_meta( $post_id, '_uc_series_faq' );
        }

        // Image: download a local copy so it survives the main site's hotlink
        // protection. The legacy hotlink URL is no longer used for display.
        $this->sync_event_image( $post_id, ! empty( $data['image'] ) ? esc_url_raw( $data['image'] ) : '' );

        if ( ! empty( $data['permalink'] ) ) {
            update_post_meta( $post_id, '_uc_source_permalink', esc_url_raw( $data['permalink'] ) );
        }

        // Source + remote series id (linked to local parents in link_series()).
        update_post_meta( $post_id, '_uc_source_site', $source_site );
        update_post_meta( $post_id, '_uc_source_id', $source_id );
        update_post_meta( $post_id, '_uc_remote_series_id', isset( $data['series_id'] ) ? sanitize_text_field( $data['series_id'] ) : '' );

        // Taxonomies: sync term identity (name/slug/colour) and assignment.
        $this->sync_terms( $post_id, $data );

        self::$importing = false;
        return $post_id;
    }

    /**
     * Keep an event's featured image in step with the main by hosting a local
     * copy (so the main's hotlink protection can't break it).
     *
     * - No image on the main: remove the local copy and fall back to the SVG
     *   placeholder.
     * - New or changed source URL: download once and set it as the featured image.
     * - Unchanged URL with a copy already present: do nothing.
     * - Download failure: leave any existing copy in place, don't record the URL
     *   (so the next sync retries), and show the SVG placeholder if there's none.
     *
     * @param int    $post_id   Local event id.
     * @param string $image_url Effective image URL from the main (may be empty).
     */
    private function sync_event_image( $post_id, $image_url ) {
        // The downloaded copy supersedes the old hotlink-URL approach entirely.
        delete_post_meta( $post_id, '_uc_remote_image_url' );

        if ( $image_url === '' ) {
            $old = (int) get_post_thumbnail_id( $post_id );
            delete_post_thumbnail( $post_id );
            delete_post_meta( $post_id, '_uc_source_image_url' );
            $this->maybe_delete_orphan_image( $old, 0 );
            return;
        }

        $current = get_post_meta( $post_id, '_uc_source_image_url', true );
        if ( $current === $image_url && has_post_thumbnail( $post_id ) ) {
            return; // already hosting this exact image
        }

        $attach_id = $this->get_local_image( $image_url, $post_id );
        if ( ! $attach_id ) {
            // Download failed — keep any existing image; otherwise the display
            // chain falls through to the SVG placeholder. Leave the source URL
            // unrecorded so the next sync retries.
            return;
        }

        $old = (int) get_post_thumbnail_id( $post_id );
        set_post_thumbnail( $post_id, $attach_id );
        update_post_meta( $post_id, '_uc_source_image_url', $image_url );
        $this->maybe_delete_orphan_image( $old, $attach_id );
    }

    /**
     * Local attachment id for a source image URL: reuse an already-downloaded
     * copy of the exact same URL (recurring occurrences all share the series
     * image), otherwise sideload it once. Returns 0 on failure.
     */
    private function get_local_image( $url, $post_id ) {
        $found = get_posts( array(
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'numberposts'    => 1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'meta_key'       => '_uc_source_image_url',
            'meta_value'     => $url,
        ) );
        if ( ! empty( $found ) ) {
            return (int) $found[0];
        }

        if ( ! function_exists( 'media_sideload_image' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }

        $attach_id = media_sideload_image( $url, $post_id, null, 'id' );
        if ( is_wp_error( $attach_id ) || ! $attach_id ) {
            return 0;
        }
        // Tag the attachment so future events sharing this URL can reuse it.
        update_post_meta( (int) $attach_id, '_uc_source_image_url', $url );
        return (int) $attach_id;
    }

    /**
     * Delete a satellite-downloaded attachment once nothing references it, so the
     * media library doesn't accumulate orphans when images change or events are
     * removed. Only touches attachments we downloaded; never the one being kept.
     */
    private function maybe_delete_orphan_image( $attach_id, $keep_id ) {
        $attach_id = (int) $attach_id;
        if ( ! $attach_id || $attach_id === (int) $keep_id ) {
            return;
        }
        // Only ever remove images we downloaded ourselves.
        if ( ! get_post_meta( $attach_id, '_uc_source_image_url', true ) ) {
            return;
        }
        // Keep it if any other event still uses it as a featured image.
        $still_used = get_posts( array(
            'post_type'      => 'uc_event',
            'post_status'    => 'any',
            'numberposts'    => 1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'meta_key'       => '_thumbnail_id',
            'meta_value'     => $attach_id,
        ) );
        if ( empty( $still_used ) ) {
            wp_delete_attachment( $attach_id, true );
        }
    }

    /**
     * Sync an event's taxonomy assignments AND keep the local terms themselves in
     * step with the main (name, slug, category colour) by matching on a stable
     * source term id. Prefers the rich `terms` payload; falls back to the legacy
     * names-only fields for events served by an older main site.
     */
    private function sync_terms( $post_id, $data ) {
        $taxes = array( 'uc_event_category', 'uc_organizer', 'uc_venue' );

        if ( isset( $data['terms'] ) && is_array( $data['terms'] ) ) {
            foreach ( $taxes as $tax ) {
                $remote    = ( isset( $data['terms'][ $tax ] ) && is_array( $data['terms'][ $tax ] ) ) ? $data['terms'][ $tax ] : array();
                $local_ids = array();
                foreach ( $remote as $rt ) {
                    $tid = $this->upsert_term( $tax, $rt );
                    if ( $tid ) {
                        $local_ids[] = $tid;
                    }
                }
                wp_set_object_terms( $post_id, $local_ids, $tax, false );
            }
            return;
        }

        // Legacy fallback (names + colour map) for older main sites.
        if ( isset( $data['categories'] ) && is_array( $data['categories'] ) ) {
            $names = array_map( 'sanitize_text_field', $data['categories'] );
            wp_set_object_terms( $post_id, $names, 'uc_event_category' );
            $colors = isset( $data['category_colors'] ) && is_array( $data['category_colors'] ) ? $data['category_colors'] : array();
            foreach ( $names as $name ) {
                if ( isset( $colors[ $name ] ) ) {
                    $term = get_term_by( 'name', $name, 'uc_event_category' );
                    if ( $term ) {
                        update_term_meta( $term->term_id, '_uc_category_color', sanitize_hex_color( $colors[ $name ] ) );
                    }
                }
            }
        }
        if ( isset( $data['organizer'] ) && is_array( $data['organizer'] ) ) {
            wp_set_object_terms( $post_id, array_map( 'sanitize_text_field', $data['organizer'] ), 'uc_organizer' );
        }
        if ( isset( $data['venue'] ) && is_array( $data['venue'] ) ) {
            wp_set_object_terms( $post_id, array_map( 'sanitize_text_field', $data['venue'] ), 'uc_venue' );
        }
    }

    /**
     * Find-or-create a local term mirroring one remote term, returning its local
     * id. Matches first on the stored source term id (so renames update in place),
     * then on slug/name (adopting an existing untagged term), creating only if
     * nothing matches. Updates name/slug and category colour to match the main.
     *
     * @param string $tax Taxonomy.
     * @param array  $rt  Remote term: id, name, slug, [color].
     * @return int Local term id (0 on failure).
     */
    private function upsert_term( $tax, $rt ) {
        $remote_id = isset( $rt['id'] ) ? (int) $rt['id'] : 0;
        $name      = isset( $rt['name'] ) ? sanitize_text_field( $rt['name'] ) : '';
        $slug      = isset( $rt['slug'] ) ? sanitize_title( $rt['slug'] ) : '';
        if ( $name === '' && $slug === '' ) {
            return 0;
        }

        // Resolve each remote term once per sync run (events in a series share the
        // same category/organizer); the first occurrence applies any rename/colour
        // change, the rest just reuse the local id.
        static $memo = array();
        $ck = $tax . '|' . $remote_id;
        if ( $remote_id && isset( $memo[ $ck ] ) ) {
            return $memo[ $ck ];
        }

        $term_id = 0;

        // 1) Match by the source term id stored on a previous sync.
        if ( $remote_id ) {
            $found = get_terms( array(
                'taxonomy'   => $tax,
                'hide_empty' => false,
                'number'     => 1,
                'fields'     => 'ids',
                'meta_query' => array( array( 'key' => '_uc_source_term_id', 'value' => $remote_id ) ),
            ) );
            if ( ! is_wp_error( $found ) && ! empty( $found ) ) {
                $term_id = (int) $found[0];
            }
        }

        // 2) Adopt an existing untagged local term by slug, then name.
        if ( ! $term_id && $slug ) {
            $t = get_term_by( 'slug', $slug, $tax );
            if ( $t ) {
                $term_id = (int) $t->term_id;
            }
        }
        if ( ! $term_id && $name ) {
            $t = get_term_by( 'name', $name, $tax );
            if ( $t ) {
                $term_id = (int) $t->term_id;
            }
        }

        // 3) Create it.
        if ( ! $term_id ) {
            $args = $slug ? array( 'slug' => $slug ) : array();
            $res  = wp_insert_term( $name !== '' ? $name : $slug, $tax, $args );
            if ( is_wp_error( $res ) ) {
                // Likely a slug/name collision — adopt the colliding term instead.
                $t = ( $slug ? get_term_by( 'slug', $slug, $tax ) : false );
                if ( ! $t && $name ) {
                    $t = get_term_by( 'name', $name, $tax );
                }
                if ( ! $t ) {
                    return 0;
                }
                $term_id = (int) $t->term_id;
            } else {
                $term_id = (int) $res['term_id'];
            }
        } else {
            // 4) Keep the matched term's name/slug aligned with the main (rename).
            $cur = get_term( $term_id, $tax );
            if ( $cur && ! is_wp_error( $cur ) ) {
                $upd = array();
                if ( $name !== '' && $cur->name !== $name ) {
                    $upd['name'] = $name;
                }
                if ( $slug !== '' && $cur->slug !== $slug ) {
                    $upd['slug'] = $slug;
                }
                if ( $upd ) {
                    $r = wp_update_term( $term_id, $tax, $upd );
                    if ( is_wp_error( $r ) && isset( $upd['slug'] ) ) {
                        // Slug taken by another term — apply the name change alone.
                        unset( $upd['slug'] );
                        if ( $upd ) {
                            wp_update_term( $term_id, $tax, $upd );
                        }
                    }
                }
            }
        }

        if ( $remote_id ) {
            update_term_meta( $term_id, '_uc_source_term_id', $remote_id );
        }
        if ( $tax === 'uc_event_category' && array_key_exists( 'color', $rt ) ) {
            $color = sanitize_hex_color( (string) $rt['color'] );
            if ( $color ) {
                update_term_meta( $term_id, '_uc_category_color', $color );
            } else {
                delete_term_meta( $term_id, '_uc_category_color' );
            }
        }

        if ( $remote_id ) {
            $memo[ $ck ] = $term_id;
        }
        return $term_id;
    }

    /**
     * Re-map the main site's series relationships onto local post IDs so the
     * "Part of series" link and "Upcoming in this series" list work locally.
     */
    private function link_series( $source_site ) {
        $ids = get_posts( array(
            'post_type'      => 'uc_event',
            'post_status'    => 'any',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'meta_query'     => array( array( 'key' => '_uc_source_site', 'value' => $source_site ) ),
        ) );

        // Map remote source_id -> local post id.
        $by_source = array();
        foreach ( $ids as $id ) {
            $by_source[ (int) get_post_meta( $id, '_uc_source_id', true ) ] = $id;
        }

        foreach ( $ids as $id ) {
            $remote_series = (int) get_post_meta( $id, '_uc_remote_series_id', true );
            if ( $remote_series && isset( $by_source[ $remote_series ] ) ) {
                $local_parent = $by_source[ $remote_series ];
                update_post_meta( $id, '_uc_series_parent', $local_parent );
                update_post_meta( $id, '_uc_series_id', $local_parent );
            } else {
                // Standalone (or parent missing): clear any stale linkage.
                delete_post_meta( $id, '_uc_series_parent' );
                delete_post_meta( $id, '_uc_series_id' );
            }
        }
    }
}
