<?php
/**
 * Recurring events: generate one real uc_event post per occurrence and keep
 * the series in sync while preserving individually edited occurrences.
 *
 * THE THREE THINGS THIS FILE HAS TO GET RIGHT
 * ---------------------------------------------------------------------------
 *
 * 1. A CANCELLED OCCURRENCE STAYS CANCELLED. Removing one occurrence used to
 *    delete a post and nothing else, so the next parent save saw a gap in the
 *    date set and helpfully filled it back in. A weekly group cancelled for a
 *    public holiday un-cancelled itself the next time anybody touched the
 *    series. Cancellations are now recorded ON THE PARENT, as dates, so they
 *    survive the child post being gone, any number of parent saves, and a
 *    change to the cadence or the end date. See $CANCELLED_META.
 *
 * 2. A SERIES PARENT IS NEVER REMOVED SILENTLY. The parent is also the first
 *    occurrence and the template every other occurrence is generated from, so
 *    deleting it used to leave the rest published, pointing at a post that no
 *    longer existed, invisible to the Series Manager and impossible to edit
 *    back into shape. Removal is now intercepted and has to be a choice
 *    between removing the whole series and promoting the next occurrence.
 *
 * 3. EDIT SCOPE MEANS WHAT IT SAYS. "All future events in this series" used to
 *    run over the entire date range, past occurrences included. Scope is now a
 *    real three-way choice with the cutoff date printed in the label, so it
 *    cannot quietly drift back into meaning something else.
 *
 * THE PARENT IS BOTH THE TEMPLATE AND THE FIRST OCCURRENCE, which is the
 * awkward fact underneath most of this. Editing the parent record edits the
 * first occurrence; there is no separate template row to change instead. The
 * scope labels are written to be honest about that rather than to hide it, and
 * promote_to_parent() exists so a series can move its template forward without
 * losing what already happened.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class SFAF_Recurrence {

    /**
     * Dates deliberately cancelled, stored on the SERIES PARENT as an array of
     * Y-m-d strings.
     *
     * On the parent and not on the child, because the whole point is that it
     * outlives the child. A flag on the occurrence would vanish with the post
     * it was attached to, which is exactly the bug.
     */
    const CANCELLED_META = '_uc_series_cancelled_dates';

    /** Guard against re-entrancy while we insert/update child posts. */
    private static $generating = false;

    /**
     * Set while this class is itself removing posts, so its own housekeeping
     * is not mistaken for somebody cancelling a week.
     *
     * generate_series() trashes occurrences that have fallen out of the date
     * set, delete_series() trashes everything on purpose, and promote_to_parent()
     * rearranges the family. None of those are cancellations.
     */
    private static $suppress_removal_record = false;

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

    /**
     * Series-level settings that travel with the parent role when it moves to
     * another occurrence. Everything a series owns rather than an occurrence.
     */
    private static $series_meta = array(
        '_uc_series_image_id', '_uc_series_image_url', '_uc_series_faq',
        // SFAF_FAQ_Sets::SERIES_META. Written out rather than referenced so
        // this property does not depend on another class having loaded first.
        '_uc_series_default_faq_set',
        self::CANCELLED_META,
    );

    public function register() {
        // Runs after SFAF_Post_Types::save_meta() (priority 10) has stored meta.
        add_action( 'save_post_uc_event', array( $this, 'on_save' ), 20, 3 );
        add_action( 'add_meta_boxes', array( $this, 'add_series_meta_box' ) );
        // One-time fix for occurrence slugs created before the date-suffix scheme.
        add_action( 'admin_init', array( $this, 'maybe_migrate_child_slugs' ) );

        // Removing a series parent must be a decision, not an accident.
        add_filter( 'pre_trash_post', array( $this, 'guard_trash' ), 10, 2 );
        add_filter( 'pre_delete_post', array( $this, 'guard_delete' ), 10, 3 );

        // Removing one occurrence is a cancellation, and it has to stick.
        // Both hooks, because trash and permanent delete are different paths
        // and a manager reaching for either means the same thing.
        add_action( 'trashed_post', array( $this, 'record_removed_occurrence' ) );
        add_action( 'before_delete_post', array( $this, 'record_removed_occurrence' ) );
    }

    /* =====================================================================
     * Edit scope
     * ================================================================== */

    /**
     * The three scopes, in one place so the portal and WP admin cannot offer
     * different ones. Both editors build their radio buttons from this.
     *
     * @return string[] scope key => short label
     */
    public static function scopes() {
        return array(
            'this'   => 'Only this event',
            'future' => 'This event and later occurrences',
            'all'    => 'All occurrences, including past ones',
        );
    }

    /**
     * The scope label with its cutoff date spelled out.
     *
     * PRINTING THE DATE IS THE POINT. The previous label said "All future
     * events in this series" and applied to every occurrence there had ever
     * been. A label that names the actual cutoff cannot drift away from the
     * behaviour without somebody noticing.
     *
     * @param string $scope
     * @param string $from_date Y-m-d cutoff used for 'future'.
     * @return string
     */
    public static function scope_label( $scope, $from_date = '' ) {
        $labels = self::scopes();
        if ( ! isset( $labels[ $scope ] ) ) {
            return '';
        }
        if ( 'future' === $scope && $from_date ) {
            $stamp = strtotime( $from_date );
            return sprintf(
                'This event and occurrences from %s onward',
                $stamp ? date_i18n( 'M j, Y', $stamp ) : $from_date
            );
        }
        return $labels[ $scope ];
    }

    /** Coerce anything submitted into a scope we recognise. */
    public static function clean_scope( $raw ) {
        // 2.11.0 and earlier passed a boolean "apply to all". Accepted so an
        // old call site cannot silently become "this only".
        if ( true === $raw ) {
            return 'all';
        }
        $raw = is_string( $raw ) ? sanitize_key( $raw ) : '';
        return array_key_exists( $raw, self::scopes() ) ? $raw : 'this';
    }

    /**
     * Whether an occurrence date is inside the chosen scope, i.e. whether this
     * save is allowed to overwrite it even if somebody edited it by hand.
     */
    private static function date_in_scope( $date, $scope, $from_date ) {
        if ( 'all' === $scope ) {
            return true;
        }
        if ( 'future' === $scope ) {
            return ( '' !== $from_date && $date >= $from_date );
        }
        return false;
    }

    /* =====================================================================
     * Cancelled occurrences
     * ================================================================== */

    /**
     * The dates cancelled on a series, oldest first.
     *
     * @param int $parent_id
     * @return string[] Y-m-d
     */
    public static function cancelled_dates( $parent_id ) {
        $raw = get_post_meta( (int) $parent_id, self::CANCELLED_META, true );
        if ( ! is_array( $raw ) ) {
            return array();
        }
        $out = array();
        foreach ( $raw as $d ) {
            $d = trim( (string) $d );
            // Validated on read as well as on write: this list decides whether
            // an occurrence is allowed to exist, so a malformed entry must not
            // be able to sit in it silently matching nothing.
            if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $d ) ) {
                $out[ $d ] = true;
            }
        }
        $out = array_keys( $out );
        sort( $out );
        return $out;
    }

    /** Whether a given date is cancelled on this series. */
    public static function is_cancelled( $parent_id, $date ) {
        return in_array( (string) $date, self::cancelled_dates( $parent_id ), true );
    }

    /** Record a cancellation. Idempotent. */
    public static function cancel_date( $parent_id, $date ) {
        $date = (string) $date;
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
            return false;
        }
        $dates = self::cancelled_dates( $parent_id );
        if ( in_array( $date, $dates, true ) ) {
            return true;
        }
        $dates[] = $date;
        sort( $dates );
        update_post_meta( (int) $parent_id, self::CANCELLED_META, $dates );
        return true;
    }

    /**
     * Undo a cancellation.
     *
     * Removes the date from the list and regenerates, which recreates the
     * occurrence when the date is still inside the current pattern. A date
     * that has since fallen outside the pattern (the end date was pulled in,
     * or the cadence changed) is simply forgotten, because there is nothing
     * left to restore it to.
     *
     * @return bool Whether the occurrence was actually recreated.
     */
    public static function restore_date( $parent_id, $date ) {
        $parent_id = (int) $parent_id;
        $dates     = self::cancelled_dates( $parent_id );
        $index     = array_search( (string) $date, $dates, true );
        if ( false === $index ) {
            return false;
        }
        unset( $dates[ $index ] );
        update_post_meta( $parent_id, self::CANCELLED_META, array_values( $dates ) );

        $rec = new self();
        $rec->maybe_generate( $parent_id, 'this' );

        return (bool) self::child_on_date( $parent_id, $date );
    }

    /**
     * Whether a cancelled date still falls inside the series' current pattern,
     * i.e. whether restoring it would actually bring an occurrence back.
     */
    public static function date_in_pattern( $parent_id, $date ) {
        $start = get_post_meta( $parent_id, '_uc_event_date', true );
        if ( (string) $date === (string) $start ) {
            return true;
        }
        $rec   = new self();
        $dates = $rec->occurrence_dates(
            $start,
            get_post_meta( $parent_id, '_uc_end_date', true ),
            get_post_meta( $parent_id, '_uc_recurrence', true )
        );
        return in_array( (string) $date, $dates, true );
    }

    /** The live occurrence on a given date in a series, if any. */
    public static function child_on_date( $parent_id, $date ) {
        $q = new WP_Query( array(
            'post_type'      => 'uc_event',
            'post_status'    => array( 'publish', 'pending', 'draft', 'future', 'private' ),
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'meta_query'     => array(
                'relation' => 'AND',
                array( 'key' => '_uc_series_parent', 'value' => (int) $parent_id ),
                array( 'key' => '_uc_event_date', 'value' => (string) $date ),
            ),
        ) );
        return ! empty( $q->posts ) ? (int) $q->posts[0] : 0;
    }

    /**
     * Cancel one occurrence by post ID, whoever asked.
     *
     * The occurrence is trashed rather than destroyed, so its RSVPs and its
     * content are still there if somebody restores the date. The cancellation
     * itself is what the recording hook picks up.
     *
     * @return array{ok:bool,message:string,parent:int,date:string}
     */
    public static function cancel_occurrence( $post_id ) {
        $post_id = (int) $post_id;
        $post    = get_post( $post_id );
        if ( ! $post || 'uc_event' !== $post->post_type ) {
            return array( 'ok' => false, 'message' => 'That event could not be found.', 'parent' => 0, 'date' => '' );
        }
        $parent = (int) get_post_meta( $post_id, '_uc_series_parent', true );
        $date   = (string) get_post_meta( $post_id, '_uc_event_date', true );

        if ( ! $parent ) {
            return array( 'ok' => false, 'message' => 'That event is not part of a series.', 'parent' => 0, 'date' => $date );
        }

        // Cancelling the series' own first occurrence would orphan everything
        // else, so the parent role moves forward first and the old parent is
        // then an ordinary occurrence that can be cancelled like any other.
        if ( $parent === $post_id ) {
            $promoted = self::promote_next( $post_id );
            if ( ! $promoted ) {
                return array(
                    'ok'      => false,
                    'message' => 'This is the only occurrence left in the series. Remove the series instead.',
                    'parent'  => $parent,
                    'date'    => $date,
                );
            }
            $parent = $promoted;
        }

        self::cancel_date( $parent, $date );

        // Suppressed: the date is already recorded above, and the trash hook
        // would otherwise write it to whichever parent the post now points at.
        self::$suppress_removal_record = true;
        wp_trash_post( $post_id );
        self::$suppress_removal_record = false;

        return array( 'ok' => true, 'message' => '', 'parent' => $parent, 'date' => $date );
    }

    /**
     * Record a removal that came from anywhere else: the WordPress list table,
     * a quick-delete, another plugin, WP-CLI.
     *
     * This is the safety net that makes the fix hold no matter which route
     * somebody took, which is why it lives on the hooks rather than only in
     * our own delete handlers.
     */
    public function record_removed_occurrence( $post_id ) {
        if ( self::$suppress_removal_record || self::$generating ) {
            return;
        }
        $post = get_post( $post_id );
        if ( ! $post || 'uc_event' !== $post->post_type ) {
            return;
        }
        $parent = (int) get_post_meta( $post_id, '_uc_series_parent', true );
        if ( ! $parent || $parent === (int) $post_id ) {
            return; // standalone event, or a parent (handled by the guards)
        }
        // Only against a series that still exists. Emptying the trash of a
        // series that was removed whole would otherwise write cancellations
        // onto a parent that is itself on its way out, and an orphan's missing
        // parent has nowhere to write them at all.
        if ( ! self::parent_exists( $parent ) ) {
            return;
        }
        $date = (string) get_post_meta( $post_id, '_uc_event_date', true );
        if ( '' === $date ) {
            return;
        }
        self::cancel_date( $parent, $date );
    }

    /* =====================================================================
     * Series parent removal guards
     * ================================================================== */

    /** @see guard_removal() */
    public function guard_trash( $check, $post ) {
        return $this->guard_removal( $check, $post );
    }

    /** @see guard_removal() */
    public function guard_delete( $check, $post, $force_delete = false ) {
        return $this->guard_removal( $check, $post );
    }

    /**
     * Refuse to remove a series parent that still has occurrences.
     *
     * Returning a non-null value from pre_trash_post / pre_delete_post aborts
     * the removal. Nothing is lost and nothing is decided here: the manager is
     * sent to the series removal screen, which states the consequence and
     * offers the two things they might actually have meant.
     *
     * A parent with no occurrences left is an ordinary event and is allowed
     * through untouched.
     */
    private function guard_removal( $check, $post ) {
        if ( null !== $check ) {
            return $check; // somebody else already decided
        }
        if ( self::$suppress_removal_record ) {
            return $check; // our own series operations
        }
        if ( ! $post || 'uc_event' !== $post->post_type ) {
            return $check;
        }
        if ( ! self::is_series_parent( $post->ID ) ) {
            return $check;
        }
        if ( ! self::child_count( $post->ID ) ) {
            return $check; // a series of one; nothing to orphan
        }

        // In the WordPress admin, send them somewhere that can explain it.
        if ( is_admin() && ! wp_doing_ajax() && ! wp_doing_cron() ) {
            wp_safe_redirect( self::admin_remove_url( $post->ID ) );
            exit;
        }

        return false;
    }

    /** The WP admin screen that offers the two removal choices. */
    public static function admin_remove_url( $parent_id ) {
        return add_query_arg(
            array(
                'post_type' => 'uc_event',
                'page'      => 'uc-series',
                'series'    => (int) $parent_id,
                'remove'    => '1',
            ),
            admin_url( 'edit.php' )
        );
    }

    /**
     * Remove a whole series: the parent and every occurrence.
     *
     * Trash, not destroy, so it is recoverable from the WordPress trash for as
     * long as WordPress keeps it. Nothing here records cancellations, because
     * the series is going, not one week of it.
     *
     * @return int Number of posts trashed.
     */
    public static function delete_series( $parent_id ) {
        $parent_id = (int) $parent_id;
        $rec       = new self();
        $ids       = $rec->get_series_children( $parent_id );
        $ids[]     = $parent_id;

        self::$suppress_removal_record = true;
        $count = 0;
        foreach ( $ids as $id ) {
            if ( wp_trash_post( $id ) ) {
                $count++;
            }
        }
        self::$suppress_removal_record = false;

        return $count;
    }

    /**
     * Move the parent role to a specific occurrence.
     *
     * The old parent is NOT thrown away. It becomes an ordinary occurrence
     * pointing at the new parent and is marked individually edited, so it
     * keeps its own details, keeps saying which series it belongs to, and is
     * never dragged back into line by a later series save. That is what stops
     * "the group moves to a new time from September" from rewriting January.
     *
     * @param int  $child_id     The occurrence taking over.
     * @param bool $demote_old   Keep the old parent as an occurrence (true) or
     *                           trash it (false).
     * @return bool
     */
    public static function promote_to_parent( $child_id, $demote_old = true ) {
        $child_id = (int) $child_id;
        $child    = get_post( $child_id );
        if ( ! $child || 'uc_event' !== $child->post_type ) {
            return false;
        }
        $old_parent = (int) get_post_meta( $child_id, '_uc_series_parent', true );
        if ( ! $old_parent || $old_parent === $child_id ) {
            return false; // already the parent, or not in a series
        }

        self::$suppress_removal_record = true;
        self::$generating = true;

        $old_post = get_post( $old_parent );

        // Series-level settings follow the role. Read before anything moves,
        // because the old parent may be on its way out.
        foreach ( self::$series_meta as $key ) {
            $value = $old_post ? get_post_meta( $old_parent, $key, true ) : '';
            if ( '' === $value || array() === $value ) {
                delete_post_meta( $child_id, $key );
            } else {
                update_post_meta( $child_id, $key, $value );
            }
        }

        // The pattern itself. A child stores no end date, so the new parent
        // has to be given the series' one or generation would stop dead.
        if ( $old_post ) {
            update_post_meta( $child_id, '_uc_end_date', get_post_meta( $old_parent, '_uc_end_date', true ) );
            update_post_meta( $child_id, '_uc_recurrence', get_post_meta( $old_parent, '_uc_recurrence', true ) );
        }

        update_post_meta( $child_id, '_uc_series_parent', $child_id );
        update_post_meta( $child_id, '_uc_series_id', $child_id );
        delete_post_meta( $child_id, '_uc_manually_edited' );

        // Re-point every other occurrence, the old parent included.
        $siblings = self::children_of( $old_parent );
        foreach ( $siblings as $sid ) {
            if ( (int) $sid === $child_id ) {
                continue;
            }
            update_post_meta( $sid, '_uc_series_parent', $child_id );
            update_post_meta( $sid, '_uc_series_id', $child_id );
        }

        if ( $old_post ) {
            if ( $demote_old ) {
                update_post_meta( $old_parent, '_uc_series_parent', $child_id );
                update_post_meta( $old_parent, '_uc_series_id', $child_id );
                update_post_meta( $old_parent, '_uc_end_date', '' );
                // Frozen on purpose. It is history now.
                update_post_meta( $old_parent, '_uc_manually_edited', '1' );
                foreach ( self::$series_meta as $key ) {
                    delete_post_meta( $old_parent, $key );
                }
            } else {
                wp_trash_post( $old_parent );
            }
        }

        self::$generating = false;
        self::$suppress_removal_record = false;

        return true;
    }

    /**
     * Promote the earliest remaining occurrence of a series.
     *
     * @param int $parent_id  Current parent.
     * @param bool $demote_old
     * @return int New parent ID, or 0 when there is nothing to promote to.
     */
    public static function promote_next( $parent_id, $demote_old = true ) {
        $parent_id = (int) $parent_id;
        $children  = self::children_of( $parent_id );
        if ( empty( $children ) ) {
            return 0;
        }

        // Earliest by occurrence date, so the series keeps running in order.
        $dated = array();
        foreach ( $children as $cid ) {
            $dated[ $cid ] = (string) get_post_meta( $cid, '_uc_event_date', true );
        }
        asort( $dated );
        $next = (int) key( $dated );

        return self::promote_to_parent( $next, $demote_old ) ? $next : 0;
    }

    /* =====================================================================
     * Orphans
     * ================================================================== */

    /**
     * Occurrences whose series parent no longer exists.
     *
     * NOT REPAIRED AUTOMATICALLY. An orphan is the visible remains of
     * something somebody did, and quietly rebuilding a series out of it — or
     * quietly cutting the last thread that says these events belong together —
     * is a decision, not housekeeping. They are listed and a person chooses.
     *
     * @return array<int,int[]> missing parent ID => occurrence IDs
     */
    public static function find_orphans() {
        $q = new WP_Query( array(
            'post_type'      => 'uc_event',
            'post_status'    => array( 'publish', 'pending', 'draft', 'future', 'private' ),
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'meta_query'     => array( array( 'key' => '_uc_series_parent', 'compare' => 'EXISTS' ) ),
        ) );

        $groups = array();
        foreach ( $q->posts as $id ) {
            $parent = (int) get_post_meta( $id, '_uc_series_parent', true );
            if ( ! $parent || $parent === (int) $id ) {
                continue; // standalone, or a healthy parent
            }
            if ( self::parent_exists( $parent ) ) {
                continue;
            }
            $groups[ $parent ][] = (int) $id;
        }

        foreach ( $groups as $parent => $ids ) {
            usort( $ids, function ( $a, $b ) {
                return strcmp(
                    (string) get_post_meta( $a, '_uc_event_date', true ),
                    (string) get_post_meta( $b, '_uc_event_date', true )
                );
            } );
            $groups[ $parent ] = $ids;
        }

        return $groups;
    }

    /**
     * Rebuild a series from a group of orphans by promoting the earliest.
     *
     * @param int $missing_parent The ID they all point at, which is gone.
     * @return int New parent ID, or 0.
     */
    public static function rebuild_orphan_group( $missing_parent ) {
        $groups = self::find_orphans();
        if ( empty( $groups[ (int) $missing_parent ] ) ) {
            return 0;
        }
        $ids  = $groups[ (int) $missing_parent ];
        $head = (int) array_shift( $ids );

        self::$suppress_removal_record = true;
        self::$generating = true;

        update_post_meta( $head, '_uc_series_parent', $head );
        update_post_meta( $head, '_uc_series_id', $head );
        delete_post_meta( $head, '_uc_manually_edited' );

        foreach ( $ids as $sid ) {
            update_post_meta( $sid, '_uc_series_parent', $head );
            update_post_meta( $sid, '_uc_series_id', $head );
            // Every one of these has been living on its own; none of them
            // should be rewritten by the first save of the rebuilt series.
            update_post_meta( $sid, '_uc_manually_edited', '1' );
        }

        self::$generating = false;
        self::$suppress_removal_record = false;

        return $head;
    }

    /**
     * Cut a group of orphans loose as ordinary standalone events.
     *
     * @param int $missing_parent
     * @return int Number converted.
     */
    public static function release_orphan_group( $missing_parent ) {
        $groups = self::find_orphans();
        if ( empty( $groups[ (int) $missing_parent ] ) ) {
            return 0;
        }
        $count = 0;
        foreach ( $groups[ (int) $missing_parent ] as $id ) {
            delete_post_meta( $id, '_uc_series_parent' );
            delete_post_meta( $id, '_uc_series_id' );
            delete_post_meta( $id, '_uc_manually_edited' );
            $count++;
        }
        return $count;
    }

    /* =====================================================================
     * Small predicates, used by both editors and the display helpers
     * ================================================================== */

    /** Whether a post exists and is a usable uc_event. */
    public static function parent_exists( $post_id ) {
        $post = get_post( (int) $post_id );
        return ( $post && 'uc_event' === $post->post_type && 'trash' !== $post->post_status );
    }

    /** Whether this post is its own series parent. */
    public static function is_series_parent( $post_id ) {
        return (int) get_post_meta( (int) $post_id, '_uc_series_parent', true ) === (int) $post_id;
    }

    /** Occurrence IDs belonging to a parent, excluding the parent itself. */
    public static function children_of( $parent_id ) {
        $rec = new self();
        return $rec->get_series_children( (int) $parent_id );
    }

    /** How many occurrences a parent has, excluding itself. */
    public static function child_count( $parent_id ) {
        return count( self::children_of( $parent_id ) );
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

        // The scope chosen in the series meta box, on a verified submit.
        $scope = 'this';
        if ( isset( $_POST['uc_series_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['uc_series_nonce'] ) ), 'uc_series' ) ) {
            $scope = self::clean_scope( isset( $_POST['uc_series_apply'] ) ? wp_unslash( $_POST['uc_series_apply'] ) : 'this' );
        }

        // Editing an occurrence. It is flagged as individually edited so later
        // series saves leave it alone — and, when the manager asked for it,
        // its changes are pushed out to the rest of the series.
        if ( $series_parent && $series_parent !== (int) $post_id ) {
            if ( isset( $_POST['uc_event_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['uc_event_nonce'] ) ), 'uc_save_event' ) ) {
                update_post_meta( $post_id, '_uc_manually_edited', '1' );
            }
            if ( 'this' !== $scope && self::parent_exists( $series_parent ) ) {
                $this->apply_occurrence_to_series( $post_id, $scope );
            }
            return;
        }

        $recurrence = get_post_meta( $post_id, '_uc_recurrence', true );
        $end_date   = get_post_meta( $post_id, '_uc_end_date', true );
        $start_date = get_post_meta( $post_id, '_uc_event_date', true );

        if ( ! $recurrence || ! $end_date || ! $start_date ) {
            return; // not a (valid) series
        }

        self::$generating = true;
        $this->generate_series( $post_id, $scope, self::parent_cutoff( $post_id, $scope ) );
        self::$generating = false;
    }

    /**
     * The cutoff a 'future' scope uses when the record being saved is the
     * series parent: today, in the site's timezone.
     *
     * From the parent editor "later occurrences" can only sensibly mean "the
     * ones that have not happened yet" — the parent itself is always written,
     * because it is the record being saved, and the label says so.
     */
    private static function parent_cutoff( $parent_id, $scope ) {
        return ( 'future' === $scope ) ? current_time( 'Y-m-d' ) : '';
    }

    /**
     * Public entry point for non-admin saves (e.g. the /caladmin portal),
     * where there is no $_POST nonce to read.
     *
     * @param int    $post_id
     * @param string $scope     'this' | 'future' | 'all'
     * @param string $from_date Y-m-d cutoff for 'future'. Defaults to today.
     */
    public function maybe_generate( $post_id, $scope = 'this', $from_date = '' ) {
        if ( self::$generating ) {
            return;
        }
        $scope = self::clean_scope( $scope );
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
        if ( 'future' === $scope && '' === $from_date ) {
            $from_date = current_time( 'Y-m-d' );
        }
        self::$generating = true;
        $this->generate_series( $post_id, $scope, $from_date );
        self::$generating = false;
    }

    /**
     * Push one occurrence's details out to the rest of its series.
     *
     * WHY THIS CAN MOVE THE PARENT. New occurrences are generated from the
     * parent's meta, so a change that does not reach the parent would be
     * undone the moment the end date is extended. But the parent is also the
     * first occurrence, so overwriting it would rewrite a session that has
     * already happened. When the cutoff is later than the parent's own date,
     * the parent role is therefore moved forward to the occurrence being
     * edited: everything before it keeps its real details and stays in the
     * series, and everything from it onward follows the new template.
     *
     * @param int    $child_id
     * @param string $scope 'future' | 'all'
     * @return array{moved:bool,parent:int}
     */
    public function apply_occurrence_to_series( $child_id, $scope ) {
        $child_id = (int) $child_id;
        $scope    = self::clean_scope( $scope );
        $result   = array( 'moved' => false, 'parent' => 0 );

        if ( 'future' !== $scope && 'all' !== $scope ) {
            return $result;
        }
        $parent_id = (int) get_post_meta( $child_id, '_uc_series_parent', true );
        if ( ! $parent_id || $parent_id === $child_id || ! self::parent_exists( $parent_id ) ) {
            return $result;
        }

        $cutoff        = (string) get_post_meta( $child_id, '_uc_event_date', true );
        $parent_date   = (string) get_post_meta( $parent_id, '_uc_event_date', true );
        $needs_promote = ( 'future' === $scope && '' !== $cutoff && '' !== $parent_date && $parent_date < $cutoff );

        if ( $needs_promote ) {
            if ( ! self::promote_to_parent( $child_id, true ) ) {
                return $result;
            }
            $parent_id       = $child_id;
            $result['moved'] = true;
        } else {
            // The parent stays where it is and takes on the new details.
            self::$generating = true;
            $this->copy_meta( $child_id, $parent_id );
            $this->copy_terms( $child_id, $parent_id );
            $child = get_post( $child_id );
            if ( $child ) {
                wp_update_post( array(
                    'ID'           => $parent_id,
                    'post_title'   => $child->post_title,
                    'post_content' => $child->post_content,
                    'post_excerpt' => $child->post_excerpt,
                ) );
            }
            self::$generating = false;
        }

        $result['parent'] = $parent_id;

        // Now propagate from the parent, which is the only thing that ever
        // writes an occurrence, so there is one code path and not two.
        $this->maybe_generate( $parent_id, $scope, $cutoff );

        return $result;
    }

    /* ---------------------------------------------------------------------
     * Generation
     * ------------------------------------------------------------------- */

    private function generate_series( $parent_id, $scope = 'this', $from_date = '' ) {
        $recurrence = get_post_meta( $parent_id, '_uc_recurrence', true );
        $start_date = get_post_meta( $parent_id, '_uc_event_date', true );
        $end_date   = get_post_meta( $parent_id, '_uc_end_date', true );

        // Anchor the parent.
        update_post_meta( $parent_id, '_uc_series_id', $parent_id );
        update_post_meta( $parent_id, '_uc_series_parent', $parent_id );

        $dates     = $this->occurrence_dates( $start_date, $end_date, $recurrence );
        $cancelled = self::cancelled_dates( $parent_id );

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
            // A CANCELLED DATE IS NOT A GAP TO BE FILLED IN. This is the whole
            // fix: without it, generation cheerfully recreated the week
            // somebody had just cancelled, every time the series was saved.
            if ( in_array( $d, $cancelled, true ) ) {
                if ( ! empty( $by_date[ $d ] ) ) {
                    foreach ( $by_date[ $d ] as $cid ) {
                        wp_trash_post( $cid );
                    }
                    unset( $by_date[ $d ] );
                }
                continue;
            }

            $force = self::date_in_scope( $d, $scope, $from_date );

            if ( ! empty( $by_date[ $d ] ) ) {
                $cid = array_shift( $by_date[ $d ] );
                $manual = get_post_meta( $cid, '_uc_manually_edited', true ) === '1';
                if ( $force || ! $manual ) {
                    $this->sync_child( $parent_id, $cid, $d, $force );
                }
            } else {
                $this->create_child( $parent_id, $d );
            }
        }

        // Leftover children no longer in the date set (end date shortened, cadence
        // changed). Remove the auto-generated ones; keep manually edited unless
        // this save's scope covers them.
        foreach ( $by_date as $date => $cids ) {
            // NEVER TOUCH AN OCCURRENCE DATED BEFORE THE SERIES STARTS. After a
            // promotion the earlier occurrences are deliberately outside the
            // pattern — they are the series' history, not stale rows.
            if ( $start_date && (string) $date < (string) $start_date ) {
                continue;
            }
            foreach ( $cids as $cid ) {
                $manual = get_post_meta( $cid, '_uc_manually_edited', true ) === '1';
                $force  = self::date_in_scope( (string) $date, $scope, $from_date );
                if ( $force || ! $manual ) {
                    wp_trash_post( $cid );
                }
            }
        }
    }

    /**
     * Occurrence dates strictly after the start date, through the end date.
     * The parent post itself is the first occurrence.
     */
    public function occurrence_dates( $start, $end, $recurrence ) {
        $steps = array(
            'daily'    => '+1 day',
            'weekly'   => '+1 week',
            'biweekly' => '+2 weeks',
            'monthly'  => '+1 month',
        );
        if ( ! isset( $steps[ $recurrence ] ) ) {
            return array();
        }
        if ( ! $start || ! $end ) {
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
     * Every date the series covers, including the parent's own, minus nothing.
     * Used by the editors to show what a series contains.
     *
     * @return string[] Y-m-d
     */
    public function all_pattern_dates( $parent_id ) {
        $start = (string) get_post_meta( $parent_id, '_uc_event_date', true );
        $dates = $this->occurrence_dates(
            $start,
            get_post_meta( $parent_id, '_uc_end_date', true ),
            get_post_meta( $parent_id, '_uc_recurrence', true )
        );
        if ( $start ) {
            array_unshift( $dates, $start );
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

        // If the series carries a default FAQ set, copy it onto this brand-new
        // occurrence. This is the point of the feature: the manager sets it
        // once on the series instead of remembering to pick a set for the
        // fifty-second weekly occurrence. Only ever applied to an occurrence
        // with no FAQs of its own, so re-generating never stacks duplicates.
        SFAF_FAQ_Sets::apply_series_default( $child_id, $parent_id );

        return $child_id;
    }

    private function sync_child( $parent_id, $child_id, $date, $force ) {
        $parent = get_post( $parent_id );
        if ( ! $parent ) {
            return;
        }

        // Skip occurrences that already match the parent — re-saving a series with
        // no changed shared fields would otherwise rewrite every child's post row,
        // meta and terms on each save. A forced save always runs (it also resets
        // the manual-edit flag, an intentional, infrequent action).
        if ( ! $force && $this->child_in_sync( $parent, $child_id, $date ) ) {
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

        // A forced save overwrote any manual edits, so reset the flag.
        if ( $force ) {
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

    public function get_series_children( $parent_id ) {
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
        $orphaned  = $is_child && ! self::parent_exists( $series_parent );
        ?>
        <div class="uc-meta-box">
            <?php if ( $orphaned ) : ?>
                <?php // The broken state, named rather than rendered as a dead link. ?>
                <p><strong>This occurrence has lost its series.</strong> The event it belonged to no longer exists, so it cannot be edited as part of a series.</p>
                <p><a href="<?php echo esc_url( add_query_arg( array( 'post_type' => 'uc_event', 'page' => 'uc-series' ), admin_url( 'edit.php' ) ) ); ?>">Repair it in the Series Manager &rarr;</a></p>
            <?php elseif ( $is_child ) : ?>
                <p>This is one occurrence in a series.</p>
                <p><a href="<?php echo esc_url( get_edit_post_link( $series_parent ) ); ?>">Edit the series parent &rarr;</a></p>
                <?php if ( get_post_meta( $post->ID, '_uc_manually_edited', true ) === '1' ) : ?>
                    <p class="uc-series-flag">This occurrence has been individually edited.</p>
                <?php endif; ?>
                <?php $this->render_scope_control( get_post_meta( $post->ID, '_uc_event_date', true ), true ); ?>
            <?php elseif ( $recurrence && $end_date ) : ?>
                <?php $count = count( $this->get_series_children( $post->ID ) ); ?>
                <?php if ( $is_parent && $count ) : ?>
                    <p>This event repeats <strong><?php echo esc_html( $recurrence ); ?></strong>: <strong><?php echo (int) ( $count + 1 ); ?></strong> events through <?php echo esc_html( $end_date ); ?>.</p>
                <?php else : ?>
                    <p>On save this will generate a repeating series through <?php echo esc_html( $end_date ); ?>.</p>
                <?php endif; ?>
                <?php $this->render_scope_control( current_time( 'Y-m-d' ), false ); ?>
                <?php
                $cancelled = self::cancelled_dates( $post->ID );
                if ( ! empty( $cancelled ) ) : ?>
                    <p class="uc-series-flag"><strong><?php echo (int) count( $cancelled ); ?></strong> cancelled <?php echo esc_html( _n( 'occurrence', 'occurrences', count( $cancelled ) ) ); ?>. These dates are not regenerated.</p>
                    <p><a href="<?php echo esc_url( add_query_arg( array( 'post_type' => 'uc_event', 'page' => 'uc-series', 'series' => $post->ID ), admin_url( 'edit.php' ) ) ); ?>">See and restore them &rarr;</a></p>
                <?php endif; ?>
            <?php else : ?>
                <p class="description">Set a <strong>Recurrence</strong> and <strong>Series End Date</strong> in Event Details, then publish to generate a repeating series.</p>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * The scope radios, shared with the portal through scopes()/scope_label().
     *
     * @param string $cutoff     The date "future" starts from.
     * @param bool   $occurrence Whether this is an occurrence, not the parent.
     */
    private function render_scope_control( $cutoff, $occurrence ) {
        ?>
        <p class="description">Apply changes to:</p>
        <?php foreach ( array_keys( self::scopes() ) as $scope ) : ?>
            <label class="uc-display-toggle">
                <input type="radio" name="uc_series_apply" value="<?php echo esc_attr( $scope ); ?>" <?php checked( 'this' === $scope ); ?> />
                <?php echo esc_html( self::scope_label( $scope, $cutoff ) ); ?>
            </label>
        <?php endforeach; ?>
        <p class="description">
            <?php if ( $occurrence ) : ?>
                Choosing anything other than &ldquo;only this event&rdquo; copies this occurrence's details onto the others and moves the series template to this date, so earlier occurrences keep exactly what they have now.
            <?php else : ?>
                This event is always saved, because it is the record you are editing. The choice is how far the change travels down the series. Occurrences you have edited individually are left alone unless they fall inside the scope you pick.
            <?php endif; ?>
        </p>
        <?php
    }
}
