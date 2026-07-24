<?php
/**
 * Recurring events: generate one real uc_event post per occurrence and keep
 * the series in sync while preserving individually edited occurrences.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class SFAF_Recurrence {

    /** Guard against re-entrancy while we insert/update child posts. */
    private static $generating = false;

    /** Template meta copied from the parent down to each occurrence. */
    private static $template_meta = array(
        '_uc_start_time', '_uc_end_time', '_uc_location', '_uc_recurrence',
        '_uc_capacity', '_uc_rsvp_enabled', '_uc_gofundme_url', '_uc_gofundme_goal',
        '_uc_pardot_campaigns', '_uc_organizer_email', '_uc_notify_organizer',
        '_uc_email_subject', '_uc_email_body', '_uc_email_replyto',
        '_uc_show_rsvp', '_uc_show_donate', '_uc_show_social', '_uc_show_calendar', '_uc_show_reminders',
    );
    // Note: images are NOT copied to children. Each occurrence shows its own
    // image if set (_uc_image_override), otherwise inherits the series image
    // (_uc_series_image_*) at display time. See sfaf_event_image_url().

    public function register() {
        // Runs after SFAF_Post_Types::save_meta() (priority 10) has stored meta.
        add_action( 'save_post_uc_event', array( $this, 'on_save' ), 20, 3 );
        add_action( 'add_meta_boxes', array( $this, 'add_series_meta_box' ) );
        // One-time fix for occurrence slugs created before the date-suffix scheme.
        add_action( 'admin_init', array( $this, 'maybe_migrate_child_slugs' ) );
    }

    /* ---------------------------------------------------------------------
     * Save handling
     * ------------------------------------------------------------------- */

    public function on_save( $post_id, $post, $update ) {
        if ( self::$generating ) {
            return;
        }
        if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
            return;
        }
        if ( $post->post_status === 'auto-draft' || $post->post_type !== 'uc_event' ) {
            return;
        }
        // The /caladmin portal sets meta then triggers generation explicitly,
        // so skip the hook path for portal requests to avoid double runs.
        if ( isset( $_POST['uc_action'] ) ) {
            return;
        }

        $series_parent = (int) get_post_meta( $post_id, '_uc_series_parent', true );

        // Editing a child: flag it as manually edited (only on a real editor save)
        // and never let a child spawn its own series.
        if ( $series_parent && $series_parent !== (int) $post_id ) {
            if ( isset( $_POST['uc_event_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['uc_event_nonce'] ) ), 'uc_save_event' ) ) {
                update_post_meta( $post_id, '_uc_manually_edited', '1' );
            }
            return;
        }

        $recurrence = get_post_meta( $post_id, '_uc_recurrence', true );
        $end_date   = get_post_meta( $post_id, '_uc_end_date', true );
        $start_date = get_post_meta( $post_id, '_uc_event_date', true );

        if ( ! $recurrence || ! $end_date || ! $start_date ) {
            return; // not a (valid) series
        }

        // "Apply to all future" only honoured on a verified series-box submit.
        $apply_all = false;
        if ( isset( $_POST['uc_series_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['uc_series_nonce'] ) ), 'uc_series' ) ) {
            $apply_all = isset( $_POST['uc_series_apply'] ) && $_POST['uc_series_apply'] === 'all';
        }

        self::$generating = true;
        $this->generate_series( $post_id, $apply_all );
        self::$generating = false;
    }

    /**
     * Public entry point for non-admin saves (e.g. the /caladmin portal),
     * where there is no $_POST nonce to read.
     */
    public function maybe_generate( $post_id, $apply_all = false ) {
        if ( self::$generating ) {
            return;
        }
        $series_parent = (int) get_post_meta( $post_id, '_uc_series_parent', true );
        if ( $series_parent && $series_parent !== (int) $post_id ) {
            return;
        }
        $recurrence = get_post_meta( $post_id, '_uc_recurrence', true );
        $end_date   = get_post_meta( $post_id, '_uc_end_date', true );
        $start_date = get_post_meta( $post_id, '_uc_event_date', true );
        if ( ! $recurrence || ! $end_date || ! $start_date ) {
            return;
        }
        self::$generating = true;
        $this->generate_series( $post_id, $apply_all );
        self::$generating = false;
    }

    /* ---------------------------------------------------------------------
     * Generation
     * ------------------------------------------------------------------- */

    private function generate_series( $parent_id, $apply_all ) {
        $recurrence = get_post_meta( $parent_id, '_uc_recurrence', true );
        $start_date = get_post_meta( $parent_id, '_uc_event_date', true );
        $end_date   = get_post_meta( $parent_id, '_uc_end_date', true );

        // Anchor the parent.
        update_post_meta( $parent_id, '_uc_series_id', $parent_id );
        update_post_meta( $parent_id, '_uc_series_parent', $parent_id );

        $dates = $this->occurrence_dates( $start_date, $end_date, $recurrence );

        // Prime the parent + all existing children in a couple of queries so the
        // per-child comparison/copy below reads from cache instead of the DB.
        $children = $this->get_series_children( $parent_id );
        _prime_post_caches( array_merge( array( $parent_id ), $children ), true, true );

        // Index existing children by their occurrence date.
        $by_date = array();
        foreach ( $children as $cid ) {
            $by_date[ get_post_meta( $cid, '_uc_event_date', true ) ][] = $cid;
        }

        foreach ( $dates as $d ) {
            if ( ! empty( $by_date[ $d ] ) ) {
                $cid = array_shift( $by_date[ $d ] );
                $manual = get_post_meta( $cid, '_uc_manually_edited', true ) === '1';
                if ( $apply_all || ! $manual ) {
                    $this->sync_child( $parent_id, $cid, $d, $apply_all );
                }
            } else {
                $this->create_child( $parent_id, $d );
            }
        }

        // Leftover children no longer in the date set (end date shortened, cadence
        // changed). Remove the auto-generated ones; keep manually edited unless
        // the admin chose "apply to all".
        foreach ( $by_date as $cids ) {
            foreach ( $cids as $cid ) {
                $manual = get_post_meta( $cid, '_uc_manually_edited', true ) === '1';
                if ( $apply_all || ! $manual ) {
                    wp_trash_post( $cid );
                }
            }
        }
    }

    /**
     * Occurrence dates strictly after the start date, through the end date.
     * The parent post itself is the first occurrence.
     */
    private function occurrence_dates( $start, $end, $recurrence ) {
        $steps = array(
            'daily'    => '+1 day',
            'weekly'   => '+1 week',
            'biweekly' => '+2 weeks',
            'monthly'  => '+1 month',
        );
        if ( ! isset( $steps[ $recurrence ] ) ) {
            return array();
        }

        $tz = wp_timezone();
        try {
            $cur = new DateTime( $start, $tz );
            $end = new DateTime( $end, $tz );
        } catch ( Exception $e ) {
            return array();
        }

        $dates = array();
        $guard = 0;
        $cur->modify( $steps[ $recurrence ] );
        while ( $cur <= $end && $guard < 366 ) {
            $dates[] = $cur->format( 'Y-m-d' );
            $cur->modify( $steps[ $recurrence ] );
            $guard++;
        }
        return $dates;
    }

    /**
     * Slug for an occurrence: the parent's slug plus the occurrence date, e.g.
     * "prop-contingency-management-2026-05-26". Falls back to the title when the
     * parent has no slug yet (e.g. still a draft). WordPress will append a numeric
     * suffix only on a genuine collision, which is fine.
     */
    private function child_slug( $parent, $date ) {
        $base = $parent->post_name ? $parent->post_name : sanitize_title( $parent->post_title );
        return $base . '-' . $date;
    }

    private function create_child( $parent_id, $date ) {
        $parent = get_post( $parent_id );
        if ( ! $parent ) {
            return 0;
        }

        $child_id = wp_insert_post( array(
            'post_type'    => 'uc_event',
            'post_status'  => $parent->post_status,
            'post_title'   => $parent->post_title,
            'post_name'    => $this->child_slug( $parent, $date ),
            'post_content' => $parent->post_content,
            'post_excerpt' => $parent->post_excerpt,
            'post_author'  => $parent->post_author,
        ), true );

        if ( is_wp_error( $child_id ) ) {
            return 0;
        }

        $this->copy_meta( $parent_id, $child_id );
        $this->copy_terms( $parent_id, $child_id );
        update_post_meta( $child_id, '_uc_event_date', $date );
        update_post_meta( $child_id, '_uc_end_date', '' );
        update_post_meta( $child_id, '_uc_series_parent', $parent_id );
        update_post_meta( $child_id, '_uc_series_id', $parent_id );
        update_post_meta( $child_id, '_uc_manually_edited', '0' );

        return $child_id;
    }

    private function sync_child( $parent_id, $child_id, $date, $apply_all ) {
        $parent = get_post( $parent_id );
        if ( ! $parent ) {
            return;
        }

        // Skip occurrences that already match the parent — re-saving a series with
        // no changed shared fields would otherwise rewrite every child's post row,
        // meta and terms on each save. "Apply to all" always runs (it also resets
        // the manual-edit flag, an intentional, infrequent action).
        if ( ! $apply_all && $this->child_in_sync( $parent, $child_id, $date ) ) {
            return;
        }

        wp_update_post( array(
            'ID'           => $child_id,
            'post_title'   => $parent->post_title,
            'post_name'    => $this->child_slug( $parent, $date ),
            'post_content' => $parent->post_content,
            'post_excerpt' => $parent->post_excerpt,
            'post_status'  => $parent->post_status,
        ) );

        $this->copy_meta( $parent_id, $child_id );
        $this->copy_terms( $parent_id, $child_id );
        update_post_meta( $child_id, '_uc_event_date', $date );
        update_post_meta( $child_id, '_uc_end_date', '' );
        update_post_meta( $child_id, '_uc_series_parent', $parent_id );
        update_post_meta( $child_id, '_uc_series_id', $parent_id );

        // Image inheritance: clear this occurrence's own image so it inherits the
        // series image — unless it carries an explicit per-event override.
        if ( get_post_meta( $child_id, '_uc_image_override', true ) !== '1' ) {
            delete_post_meta( $child_id, '_uc_image_url' );
            delete_post_meta( $child_id, '_thumbnail_id' );
        }

        // "Apply to all" overwrote any manual edits, so reset the flag.
        if ( $apply_all ) {
            update_post_meta( $child_id, '_uc_manually_edited', '0' );
        }
    }

    /**
     * True when a child already matches its parent (so sync_child can skip the
     * writes). Compares the occurrence date, series anchors, post fields, every
     * template meta key, the inherited-image state and the shared taxonomies.
     */
    private function child_in_sync( $parent, $child_id, $date ) {
        if ( get_post_meta( $child_id, '_uc_event_date', true ) !== $date ) {
            return false;
        }
        if ( (int) get_post_meta( $child_id, '_uc_series_parent', true ) !== (int) $parent->ID ) {
            return false;
        }
        if ( (int) get_post_meta( $child_id, '_uc_series_id', true ) !== (int) $parent->ID ) {
            return false;
        }
        if ( get_post_meta( $child_id, '_uc_end_date', true ) !== '' ) {
            return false;
        }

        $child = get_post( $child_id );
        if ( ! $child
            || $child->post_title !== $parent->post_title
            || $child->post_content !== $parent->post_content
            || $child->post_excerpt !== $parent->post_excerpt
            || $child->post_status !== $parent->post_status ) {
            return false;
        }

        // Slug must follow {parent-slug}-{date}. Treat a WP-added collision suffix
        // ("…-2026-05-26-2") as already-correct so we don't loop fixing it forever.
        $desired_slug = $this->child_slug( $parent, $date );
        if ( $child->post_name !== $desired_slug && strpos( $child->post_name, $desired_slug . '-' ) !== 0 ) {
            return false;
        }

        foreach ( self::$template_meta as $key ) {
            if ( get_post_meta( $child_id, $key, true ) !== get_post_meta( $parent->ID, $key, true ) ) {
                return false;
            }
        }

        // Non-overridden children must carry no own image (they inherit the series).
        if ( get_post_meta( $child_id, '_uc_image_override', true ) !== '1' ) {
            if ( get_post_meta( $child_id, '_uc_image_url', true ) || get_post_meta( $child_id, '_thumbnail_id', true ) ) {
                return false;
            }
        }

        foreach ( array( 'uc_event_category', 'uc_organizer', 'uc_venue' ) as $tax ) {
            $cterms = wp_get_object_terms( $child_id, $tax, array( 'fields' => 'ids' ) );
            $pterms = wp_get_object_terms( $parent->ID, $tax, array( 'fields' => 'ids' ) );
            if ( is_wp_error( $cterms ) || is_wp_error( $pterms ) ) {
                return false;
            }
            sort( $cterms );
            sort( $pterms );
            if ( $cterms !== $pterms ) {
                return false;
            }
        }

        return true;
    }

    private function copy_meta( $from, $to ) {
        foreach ( self::$template_meta as $key ) {
            update_post_meta( $to, $key, get_post_meta( $from, $key, true ) );
        }
    }

    private function copy_terms( $from, $to ) {
        foreach ( array( 'uc_event_category', 'uc_organizer', 'uc_venue' ) as $tax ) {
            $terms = wp_get_object_terms( $from, $tax, array( 'fields' => 'ids' ) );
            if ( ! is_wp_error( $terms ) ) {
                wp_set_object_terms( $to, $terms, $tax );
            }
        }
    }

    private function get_series_children( $parent_id ) {
        $q = new WP_Query( array(
            'post_type'      => 'uc_event',
            'post_status'    => array( 'publish', 'pending', 'draft', 'future', 'private' ),
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'post__not_in'   => array( $parent_id ),
            'no_found_rows'  => true,
            'meta_query'     => array(
                array( 'key' => '_uc_series_parent', 'value' => $parent_id ),
            ),
        ) );
        return $q->posts;
    }

    /**
     * One-time data fix: rename occurrence slugs created before the date-suffix
     * scheme (e.g. "…-2", "…-3") to "{parent-slug}-{YYYY-MM-DD}". Runs once,
     * gated by an option, and only touches children (never the series parent).
     */
    public function maybe_migrate_child_slugs() {
        if ( get_option( 'sfaf_child_slugs_dated' ) === '1' ) {
            return;
        }

        $children = get_posts( array(
            'post_type'      => 'uc_event',
            'post_status'    => array( 'publish', 'pending', 'draft', 'future', 'private' ),
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'meta_query'     => array(
                array( 'key' => '_uc_series_parent', 'compare' => 'EXISTS' ),
            ),
        ) );

        foreach ( $children as $cid ) {
            $parent_id = (int) get_post_meta( $cid, '_uc_series_parent', true );
            // Skip series parents (they point at themselves) and standalone events.
            if ( ! $parent_id || $parent_id === (int) $cid ) {
                continue;
            }
            $parent = get_post( $parent_id );
            $date   = get_post_meta( $cid, '_uc_event_date', true );
            $child  = get_post( $cid );
            if ( ! $parent || ! $child || ! $date ) {
                continue;
            }
            $desired = $this->child_slug( $parent, $date );
            if ( $child->post_name !== $desired && strpos( $child->post_name, $desired . '-' ) !== 0 ) {
                wp_update_post( array( 'ID' => $cid, 'post_name' => $desired ) );
            }
        }

        update_option( 'sfaf_child_slugs_dated', '1' );
    }

    /* ---------------------------------------------------------------------
     * Series meta box
     * ------------------------------------------------------------------- */

    public function add_series_meta_box() {
        add_meta_box(
            'uc_event_series',
            'Recurring Series',
            array( $this, 'render_series_meta_box' ),
            'uc_event',
            'side',
            'high'
        );
    }

    public function render_series_meta_box( $post ) {
        wp_nonce_field( 'uc_series', 'uc_series_nonce' );

        $series_parent = (int) get_post_meta( $post->ID, '_uc_series_parent', true );
        $recurrence    = get_post_meta( $post->ID, '_uc_recurrence', true );
        $end_date      = get_post_meta( $post->ID, '_uc_end_date', true );

        $is_child  = $series_parent && $series_parent !== (int) $post->ID;
        $is_parent = $series_parent && $series_parent === (int) $post->ID;
        ?>
        <div class="uc-meta-box">
            <?php if ( $is_child ) : ?>
                <p>This is one occurrence in a series.</p>
                <p><a href="<?php echo esc_url( get_edit_post_link( $series_parent ) ); ?>">Edit the series parent &rarr;</a></p>
                <p class="description">Saving here changes only this occurrence and locks it from future series updates.</p>
                <?php if ( get_post_meta( $post->ID, '_uc_manually_edited', true ) === '1' ) : ?>
                    <p class="uc-series-flag">✏️ This occurrence has been individually edited.</p>
                <?php endif; ?>
            <?php elseif ( $recurrence && $end_date ) : ?>
                <?php $count = count( $this->get_series_children( $post->ID ) ); ?>
                <?php if ( $is_parent && $count ) : ?>
                    <p>This event repeats <strong><?php echo esc_html( $recurrence ); ?></strong> &mdash; <strong><?php echo (int) ( $count + 1 ); ?></strong> events through <?php echo esc_html( $end_date ); ?>.</p>
                <?php else : ?>
                    <p>On save this will generate a repeating series through <?php echo esc_html( $end_date ); ?>.</p>
                <?php endif; ?>
                <p class="description">Apply changes to:</p>
                <label class="uc-display-toggle"><input type="radio" name="uc_series_apply" value="this" checked /> Only this event</label>
                <label class="uc-display-toggle"><input type="radio" name="uc_series_apply" value="all" /> All future events in this series</label>
                <p class="description">Occurrences you've individually edited are preserved unless you choose "all".</p>
            <?php else : ?>
                <p class="description">Set a <strong>Recurrence</strong> and <strong>Series End Date</strong> in Event Details, then publish to generate a repeating series.</p>
            <?php endif; ?>
        </div>
        <?php
    }
}
