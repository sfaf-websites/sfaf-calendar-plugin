<?php
/**
 * The 3.0.0 data migration: series posts become series terms.
 *
 * WHAT IT HAS TO GET RIGHT, in order of how bad it would be to get wrong:
 *
 * 1. NO POST ID CHANGES. RSVP rows and reminder-log rows point at post IDs, in
 *    this plugin's own tables, and nothing joins back to anything that would
 *    let a changed ID be repaired. So no post is recreated, ever. The old
 *    series parent KEEPS its ID: it stops being a parent and becomes an
 *    ordinary event, in place, with a term attached. That is also what keeps
 *    its permalink working.
 *
 * 2. NO SLUG CHANGES. Occurrence slugs are {parent-slug}-{YYYY-MM-DD} and those
 *    URLs are live. Nothing here writes post_name. The old parent keeps its own
 *    slug too, so its public address is untouched.
 *
 * 3. THE PARENT'S OWN DATE MUST NOT VANISH. It was a real event on the calendar
 *    with its own date, RSVPs and FAQs. The container model does not have a
 *    place for "the series' own occurrence", so it becomes what it always
 *    really was — an ordinary event in the series.
 *
 * 4. NOTHING DISAPPEARS FROM A PUBLIC PAGE. An occurrence that inherited its
 *    series' FAQs at display time gets those rows COPIED onto it, because after
 *    this there is no display-time inheritance left to do it.
 *
 * IT DOES NOT RUN ON ITS OWN. There is an admin notice and a screen with a DRY
 * RUN that reports exactly what would happen and writes nothing. That is a
 * deliberate choice for a release that changes the data model against real
 * data: the alternative — migrating silently on upgrade — means the first time
 * anybody sees the report, the writing has already happened.
 *
 * IT IS IDEMPOTENT. Every step is keyed on something it can check first, so
 * running it twice does the work once. The report from a second run reads as
 * zeros, which is also what makes the dry run useful afterwards: it answers
 * "is there anything left to migrate" truthfully at any time.
 *
 * THE OLD META IS LEFT WHERE IT IS. _uc_series_parent, _uc_series_id and the
 * rest are not deleted. Nothing reads them any more — the code that did has
 * been removed rather than left dormant — but keeping the values costs nothing,
 * makes the migration re-runnable against a restored database, and means an
 * inspection can still answer "what was this before". The one exception is
 * _uc_series_cancelled_dates, which is deleted: those occurrences are already
 * absent and nothing will now recreate them, so the list has stopped meaning
 * anything at all.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class SFAF_Migrate {

    /** Set once the migration has been run for real. */
    const DONE_OPTION = 'sfaf_migrated_3_0';

    /** The last report, so the screen can show what happened. */
    const REPORT_OPTION = 'sfaf_migration_3_0_report';

    /** Legacy meta keys, read here and nowhere else in the plugin. */
    const OLD_PARENT    = '_uc_series_parent';
    const OLD_SERIES_ID = '_uc_series_id';
    const OLD_IMAGE_ID  = '_uc_series_image_id';
    const OLD_IMAGE_URL = '_uc_series_image_url';
    const OLD_SERIES_FAQ = '_uc_series_faq';
    const OLD_EVENT_FAQ  = '_uc_event_faq';
    const OLD_OVERRIDE   = '_uc_faq_override';
    const OLD_FAQ_SET    = '_uc_series_default_faq_set';
    const OLD_CANCELLED  = '_uc_series_cancelled_dates';
    const OLD_EDITED     = '_uc_manually_edited';

    public static function register() {
        add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
        add_action( 'admin_init', array( __CLASS__, 'handle_action' ) );
    }

    /** Whether the migration has been run. */
    public static function is_done() {
        return '1' === get_option( self::DONE_OPTION );
    }

    /**
     * Whether there is anything to migrate.
     *
     * Cheap enough to ask on every admin screen: one meta_key EXISTS query,
     * capped at a single row.
     */
    public static function is_needed() {
        if ( self::is_done() ) {
            return false;
        }
        $q = new WP_Query( array(
            'post_type'      => 'uc_event',
            'post_status'    => 'any',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'meta_query'     => array( array( 'key' => self::OLD_PARENT, 'compare' => 'EXISTS' ) ),
        ) );
        return ! empty( $q->posts );
    }

    /* =====================================================================
     * The screen's notice and its two buttons
     * ================================================================== */

    public static function notice() {
        if ( ! current_user_can( 'manage_options' ) || ! self::is_needed() ) {
            return;
        }
        $url = add_query_arg(
            array( 'post_type' => 'uc_event', 'page' => 'uc-migrate' ),
            admin_url( 'edit.php' )
        );
        echo '<div class="notice notice-warning"><p><strong>SFAF Calendar 3.0 has not migrated your series yet.</strong> '
            . 'Series are now a grouping rather than a kind of event, and your existing series need converting once. '
            . 'Nothing has been changed yet.</p>'
            . '<p><a class="button button-primary" href="' . esc_url( $url ) . '">Review and run the migration</a></p></div>';
    }

    /** Run the migration from the screen's button. */
    public static function handle_action() {
        if ( empty( $_POST['sfaf_migrate_run'] ) ) {
            return;
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $nonce = isset( $_POST['sfaf_migrate_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['sfaf_migrate_nonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'sfaf_migrate_3_0' ) ) {
            return;
        }

        $report = self::run( false );
        update_option( self::REPORT_OPTION, $report, false );
        update_option( self::DONE_OPTION, '1' );

        wp_safe_redirect( add_query_arg(
            array( 'post_type' => 'uc_event', 'page' => 'uc-migrate', 'migrated' => '1' ),
            admin_url( 'edit.php' )
        ) );
        exit;
    }

    /* =====================================================================
     * The migration itself
     * ================================================================== */

    /**
     * Convert every old series.
     *
     * @param bool $dry_run True to report without writing a single thing.
     * @return array {
     *     @type bool     dry_run
     *     @type int      series_converted
     *     @type int      parents_demoted   Old parent posts that became ordinary events.
     *     @type int      events_migrated   Events given a series term.
     *     @type int      faqs_moved        Events whose FAQ rows landed in the one key.
     *     @type int      faqs_inherited    Events that had series FAQs COPIED onto them.
     *     @type int      groups_stamped    Events given a recurrence group ID.
     *     @type int      orphans_resolved  Occurrences whose parent had gone.
     *     @type int      cancelled_dropped Cancelled-date lists deleted.
     *     @type string[] notes             Anything a person should read.
     *     @type array[]  series            Per-series detail for the report table.
     * }
     */
    public static function run( $dry_run = true ) {
        $report = array(
            'dry_run'           => (bool) $dry_run,
            'series_converted'  => 0,
            'parents_demoted'   => 0,
            'events_migrated'   => 0,
            'faqs_moved'        => 0,
            'faqs_inherited'    => 0,
            'groups_stamped'    => 0,
            'orphans_resolved'  => 0,
            'cancelled_dropped' => 0,
            'notes'             => array(),
            'series'            => array(),
        );

        $families = self::families();

        foreach ( $families as $parent_id => $family ) {
            $parent_id = (int) $parent_id;
            $parent    = get_post( $parent_id );

            /*
             * AN ORPHANED FAMILY: the parent post is gone, and its occurrences
             * are still pointing at the ID. Under the old model these needed a
             * repair screen and a human decision between "rebuild a series out
             * of them" and "cut them loose". Neither choice exists any more,
             * because neither problem does: they become ordinary events in a
             * series named after themselves, which is what they always were.
             */
            $orphaned = ( ! $parent || 'uc_event' !== $parent->post_type );

            $name = $orphaned
                ? self::orphan_series_name( $family['children'] )
                : get_the_title( $parent_id );

            $term_id = self::find_or_create_series( $parent_id, $name, $parent, $dry_run );
            if ( ! $term_id && ! $dry_run ) {
                $report['notes'][] = sprintf( 'Series "%s" could not be created and was left alone.', $name );
                continue;
            }
            $report['series_converted']++;

            $detail = array(
                'name'      => $name,
                'term_id'   => $term_id,
                'legacy_id' => $parent_id,
                'orphaned'  => $orphaned,
                'events'    => 0,
            );

            // The FAQ rows the whole family inherited at display time.
            $series_faqs = $orphaned
                ? array()
                : sfaf_normalize_faqs( get_post_meta( $parent_id, self::OLD_SERIES_FAQ, true ) );

            // One recurrence group for everything that was in this series, so
            // "edit all upcoming occurrences" keeps working on data that was
            // generated together before 3.0.0 existed.
            $group = self::existing_group( $family['all'] );
            if ( '' === $group ) {
                $group = $dry_run ? 'rg_dry_run' : SFAF_Recurrence::new_group_id();
            }

            foreach ( $family['all'] as $event_id ) {
                $event_id = (int) $event_id;

                $is_parent = ( $event_id === $parent_id );
                if ( $is_parent ) {
                    // The parent's own date becomes an ordinary event. Its ID,
                    // slug, permalink, RSVPs and meta are all untouched — the
                    // only thing that changes is that it now points at a term
                    // instead of at itself.
                    $report['parents_demoted']++;
                }
                if ( $orphaned && ! $is_parent ) {
                    $report['orphans_resolved']++;
                }

                if ( ! $dry_run ) {
                    SFAF_Series::set_for_event( $event_id, $term_id );
                }
                $report['events_migrated']++;
                $detail['events']++;

                // FAQs.
                $moved = self::migrate_faqs( $event_id, $is_parent, $series_faqs, $dry_run );
                if ( $moved['moved'] ) {
                    $report['faqs_moved']++;
                }
                if ( $moved['inherited'] ) {
                    $report['faqs_inherited']++;
                }

                // Recurrence group.
                if ( '' === SFAF_Recurrence::group_of( $event_id ) ) {
                    if ( ! $dry_run ) {
                        update_post_meta( $event_id, SFAF_Recurrence::GROUP_META, $group );
                        $pattern = self::old_pattern( $parent_id );
                        if ( '' !== $pattern ) {
                            update_post_meta( $event_id, SFAF_Recurrence::PATTERN_META, $pattern );
                        }
                    }
                    $report['groups_stamped']++;
                }
            }

            // Cancelled dates stop existing. Those occurrences are already
            // absent and nothing will now recreate them.
            if ( ! $orphaned && '' !== (string) get_post_meta( $parent_id, self::OLD_CANCELLED, true ) ) {
                if ( ! $dry_run ) {
                    delete_post_meta( $parent_id, self::OLD_CANCELLED );
                }
                $report['cancelled_dropped']++;
            }

            $report['series'][] = $detail;
        }

        // Standalone events kept their FAQs in _uc_series_faq too, despite not
        // being a series — see the note above sfaf_faq_meta_key(). They are not
        // in any family, so they are swept separately or their questions would
        // silently stop rendering.
        $report['faqs_moved'] += self::migrate_standalone_faqs( $dry_run );

        if ( empty( $families ) ) {
            $report['notes'][] = 'No old series found. Nothing to convert.';
        }

        return $report;
    }

    /**
     * Every old series family: parent ID => its events.
     *
     * Includes families whose parent post has gone, because those occurrences
     * are exactly the ones that must not be left behind.
     *
     * @return array<int,array{all:int[],children:int[]}>
     */
    private static function families() {
        $q = new WP_Query( array(
            'post_type'              => 'uc_event',
            'post_status'            => 'any',
            'posts_per_page'         => -1,
            'fields'                 => 'ids',
            'no_found_rows'          => true,
            'update_post_term_cache' => false,
            'meta_query'             => array( array( 'key' => self::OLD_PARENT, 'compare' => 'EXISTS' ) ),
        ) );

        $families = array();
        foreach ( $q->posts as $id ) {
            $parent = (int) get_post_meta( $id, self::OLD_PARENT, true );
            if ( ! $parent ) {
                continue;
            }
            if ( ! isset( $families[ $parent ] ) ) {
                $families[ $parent ] = array( 'all' => array(), 'children' => array() );
            }
            $families[ $parent ]['all'][] = (int) $id;
            if ( (int) $id !== $parent ) {
                $families[ $parent ]['children'][] = (int) $id;
            }
        }

        // Earliest date first inside each family, so the recurrence group and
        // the report read in the order the series actually ran.
        foreach ( $families as $parent => $family ) {
            usort( $family['all'], function ( $a, $b ) {
                return strcmp(
                    (string) get_post_meta( $a, '_uc_event_date', true ),
                    (string) get_post_meta( $b, '_uc_event_date', true )
                );
            } );
            $families[ $parent ] = $family;
        }

        return $families;
    }

    /**
     * The series term for an old parent: the one already migrated, or a new one.
     *
     * KEYED ON THE LEGACY ID, which is what makes this idempotent. A second run
     * finds the term it made the first time and changes nothing.
     *
     * @param int          $parent_id
     * @param string       $name
     * @param WP_Post|null $parent
     * @param bool         $dry_run
     * @return int Term ID, or 0 in a dry run / on failure.
     */
    private static function find_or_create_series( $parent_id, $name, $parent, $dry_run ) {
        $existing = SFAF_Series::resolve( $parent_id );
        if ( $existing ) {
            return $existing;
        }
        if ( $dry_run ) {
            return 0;
        }

        $term_id = SFAF_Series::create( $name, array(
            // The parent post's body copy was the series' description in
            // everything but name: the Series Manager edited it under the label
            // "Description". It becomes the term's description.
            'description' => $parent ? $parent->post_content : '',
            'image_id'    => $parent ? (int) get_post_meta( $parent_id, self::OLD_IMAGE_ID, true ) : 0,
            'image_url'   => $parent ? (string) get_post_meta( $parent_id, self::OLD_IMAGE_URL, true ) : '',
            'faq_set'     => $parent ? (string) get_post_meta( $parent_id, self::OLD_FAQ_SET, true ) : '',
            // What keeps every existing embed snippet and shortcode resolving.
            'legacy_id'   => $parent_id,
        ) );

        return is_wp_error( $term_id ) ? 0 : (int) $term_id;
    }

    /**
     * Move one event's FAQ rows into the single key.
     *
     * THE RULE, straight from what a visitor used to see:
     *
     *   - override set   -> the event's own rows won, so they are what it keeps.
     *   - override unset -> the visitor saw the series' rows THEN the event's,
     *                       so both are copied, in that order. Copying is the
     *                       whole point: there is no inheritance left to render
     *                       them, so anything not copied would simply vanish
     *                       off a public page.
     *   - the old parent -> its own rows were the series' shared block, and it
     *                       showed exactly those. It keeps them.
     *
     * Imported rows keep their source_faq_id through sfaf_normalize_faqs(), so
     * the id-matching refresh in SFAF_Sources::sync_faqs() still finds them
     * afterwards — it matches on the ID, never on which key the row sat in.
     *
     * @return array{moved:bool,inherited:bool}
     */
    private static function migrate_faqs( $event_id, $is_parent, $series_faqs, $dry_run ) {
        $out = array( 'moved' => false, 'inherited' => false );
        $key = sfaf_faq_meta_key();

        // Already migrated. Idempotency: never merge a second time.
        if ( ! empty( sfaf_normalize_faqs( get_post_meta( $event_id, $key, true ) ) ) ) {
            return $out;
        }

        if ( $is_parent ) {
            $rows = sfaf_normalize_faqs( get_post_meta( $event_id, self::OLD_SERIES_FAQ, true ) );
        } else {
            $own      = sfaf_normalize_faqs( get_post_meta( $event_id, self::OLD_EVENT_FAQ, true ) );
            $override = ( '1' === get_post_meta( $event_id, self::OLD_OVERRIDE, true ) );
            if ( $override ) {
                $rows = $own;
            } else {
                $rows = array_merge( $series_faqs, $own );
                if ( ! empty( $series_faqs ) ) {
                    $out['inherited'] = true;
                }
            }
        }

        if ( empty( $rows ) ) {
            $out['inherited'] = false;
            return $out;
        }

        if ( ! $dry_run ) {
            update_post_meta( $event_id, $key, $rows );
        }
        $out['moved'] = true;
        return $out;
    }

    /**
     * Standalone events — which every imported event is — kept their questions
     * in _uc_series_faq. Move those too.
     *
     * @param bool $dry_run
     * @return int
     */
    private static function migrate_standalone_faqs( $dry_run ) {
        $q = new WP_Query( array(
            'post_type'              => 'uc_event',
            'post_status'            => 'any',
            'posts_per_page'         => -1,
            'fields'                 => 'ids',
            'no_found_rows'          => true,
            'update_post_term_cache' => false,
            'meta_query'             => array(
                'relation' => 'AND',
                array( 'key' => self::OLD_SERIES_FAQ, 'compare' => 'EXISTS' ),
                array( 'key' => self::OLD_PARENT, 'compare' => 'NOT EXISTS' ),
            ),
        ) );

        $key   = sfaf_faq_meta_key();
        $count = 0;
        foreach ( $q->posts as $id ) {
            if ( ! empty( sfaf_normalize_faqs( get_post_meta( $id, $key, true ) ) ) ) {
                continue; // already migrated
            }
            $rows = sfaf_normalize_faqs( get_post_meta( $id, self::OLD_SERIES_FAQ, true ) );
            if ( empty( $rows ) ) {
                continue;
            }
            if ( ! $dry_run ) {
                update_post_meta( $id, $key, $rows );
            }
            $count++;
        }
        return $count;
    }

    /**
     * A recurrence group already on any member of a family, so a re-run does
     * not hand the same events a second, different group.
     *
     * @param int[] $ids
     * @return string
     */
    private static function existing_group( $ids ) {
        foreach ( (array) $ids as $id ) {
            $group = SFAF_Recurrence::group_of( $id );
            if ( '' !== $group ) {
                return $group;
            }
        }
        return '';
    }

    /**
     * The old cadence, mapped onto a 3.0 pattern where the names still line up.
     *
     * daily / weekly / biweekly / monthly are the same arithmetic and keep
     * their names. There is nothing to map monthly_nth from, because the old
     * engine could not produce it.
     *
     * @param int $parent_id
     * @return string
     */
    private static function old_pattern( $parent_id ) {
        return SFAF_Recurrence::clean_pattern( get_post_meta( (int) $parent_id, '_uc_recurrence', true ) );
    }

    /**
     * A name for a series rebuilt from occurrences whose parent has gone.
     *
     * Their titles were all copied from the parent, so the first one is the
     * parent's title — the name the series had, recovered from the only place
     * it survives.
     *
     * @param int[] $children
     * @return string
     */
    private static function orphan_series_name( $children ) {
        foreach ( (array) $children as $id ) {
            $title = get_the_title( $id );
            if ( '' !== trim( (string) $title ) ) {
                return $title;
            }
        }
        return 'Recovered series';
    }
}
