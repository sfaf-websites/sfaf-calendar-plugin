<?php
/**
 * ONE-TIME IMPORT OF THE OLD EVENTS CALENDAR STRUCTURE INTO THE SFAF CALENDAR.
 *
 * This is not part of the plugin and is not in the zip. It is run once, by
 * hand, against resources.sfaf.org, and then deleted.
 *
 * WHAT IT DOES, in this order, and nothing else:
 *
 *   1. Resolves each organizer, venue and category on Mark's list against what
 *      caladmin already has, by name and by the other names the same thing has
 *      been called. It creates the three organizers and any venue that is
 *      genuinely absent. It NEVER creates a category and never renames or
 *      re-addresses anything it finds.
 *   2. Matches each series by name into the existing series, or creates it.
 *      An existing series keeps its description, its image and its FAQ set.
 *   3. Creates each event on the list as a DRAFT, with the description, times,
 *      venue, organizer and featured image the export carried, and generates
 *      its occurrences where there is a live schedule.
 *
 * WHAT IT WILL NOT DO. It writes no roles, touches no teams, no FAQ sets and
 * no settings, and it does not publish anything. Clearing the existing events
 * is a separate mode that must be asked for by name.
 *
 * HOW TO RUN IT. Put this file, plan.php and schedule.php together in one
 * folder inside the WordPress install (the plugin folder will do), then either:
 *
 *     wp eval-file sfaf-tec-import.php report
 *     wp eval-file sfaf-tec-import.php clear
 *     wp eval-file sfaf-tec-import.php import
 *
 * or, logged in as an administrator, visit
 *
 *     .../sfaf-tec-import.php?mode=report
 *     .../sfaf-tec-import.php?mode=clear&confirm=CLEAR
 *     .../sfaf-tec-import.php?mode=import&confirm=IMPORT
 *
 * REPORT IS THE DEFAULT AND WRITES NOTHING. The two modes that write each
 * require their own confirmation word, so a bookmarked or shared URL cannot
 * run one by being opened.
 *
 * DELETE THIS FILE AFTERWARDS.
 */

/* ---------------------------------------------------------------------------
 * WordPress.
 * ------------------------------------------------------------------------ */
if ( ! defined( 'ABSPATH' ) ) {
    // Walk up looking for wp-load.php, so the file works from the plugin
    // folder, from mu-plugins, or from the install root.
    $dir  = __DIR__;
    $load = '';
    for ( $i = 0; $i < 8; $i++ ) {
        if ( file_exists( $dir . '/wp-load.php' ) ) {
            $load = $dir . '/wp-load.php';
            break;
        }
        $up = dirname( $dir );
        if ( $up === $dir ) {
            break;
        }
        $dir = $up;
    }
    if ( '' === $load ) {
        exit( "Could not find wp-load.php from " . __DIR__ . "\n" );
    }
    require $load;
}

$sfaf_import_cli = ( defined( 'WP_CLI' ) && WP_CLI ) || ( PHP_SAPI === 'cli' );

if ( ! $sfaf_import_cli && ! current_user_can( 'manage_options' ) ) {
    status_header( 403 );
    exit( 'This runs as an administrator.' );
}
if ( ! class_exists( 'SFAF_Series' ) || ! class_exists( 'SFAF_Recurrence' ) ) {
    exit( "The SFAF Calendar plugin is not active. Nothing was done.\n" );
}

require __DIR__ . '/schedule.php';
$sfaf_plan = require __DIR__ . '/plan.php';

/* ---------------------------------------------------------------------------
 * Mode.
 * ------------------------------------------------------------------------ */
$sfaf_mode    = 'report';
$sfaf_confirm = '';
if ( $sfaf_import_cli ) {
    foreach ( array_slice( isset( $argv ) ? $argv : array(), 1 ) as $a ) {
        if ( in_array( $a, array( 'report', 'clear', 'import' ), true ) ) {
            $sfaf_mode = $a;
        }
    }
    // On the command line there is no shared URL to worry about, so naming the
    // mode is the confirmation.
    $sfaf_confirm = strtoupper( $sfaf_mode );
} else {
    if ( isset( $_GET['mode'] ) ) {
        $m = sanitize_key( wp_unslash( $_GET['mode'] ) );
        if ( in_array( $m, array( 'report', 'clear', 'import' ), true ) ) {
            $sfaf_mode = $m;
        }
    }
    $sfaf_confirm = isset( $_GET['confirm'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_GET['confirm'] ) ) ) : '';
    header( 'Content-Type: text/plain; charset=utf-8' );
}

if ( 'report' !== $sfaf_mode && $sfaf_confirm !== strtoupper( $sfaf_mode ) ) {
    exit( "Add &confirm=" . strtoupper( $sfaf_mode ) . " to run that. Nothing was done.\n" );
}

$WRITING = ( 'report' !== $sfaf_mode );

function say( $line = '' ) {
    echo $line . "\n";
    if ( function_exists( 'ob_get_level' ) && ob_get_level() ) {
        @ob_flush();
    }
    flush();
}
function rule( $ch = '=' ) {
    say( str_repeat( $ch, 78 ) );
}

$today   = current_time( 'Y-m-d' );
$horizon = $sfaf_plan['horizon'];

say( 'SFAF CALENDAR: ONE-TIME IMPORT FROM THE EVENTS CALENDAR' );
rule();
say( 'mode:    ' . $sfaf_mode . ( $WRITING ? '  (WRITING)' : '  (reads only, writes nothing)' ) );
say( 'today:   ' . $today );
say( 'horizon: ' . $horizon );
say( 'plan:    ' . $sfaf_plan['generated'] );
say();

/* ===========================================================================
 * 1. The existing events.
 * ======================================================================== */

/** Every uc_event, counted by status. */
function sfaf_import_event_counts() {
    $counts = array();
    foreach ( array( 'publish', 'pending', 'draft', 'future', 'private', 'trash' ) as $status ) {
        $ids = get_posts( array(
            'post_type'      => 'uc_event',
            'post_status'    => $status,
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ) );
        $counts[ $status ] = count( $ids );
    }
    return $counts;
}

$before = sfaf_import_event_counts();
say( 'EVENTS ON THE SITE NOW' );
rule( '-' );
$total_live = 0;
foreach ( $before as $status => $n ) {
    say( sprintf( '  %-10s %d', $status, $n ) );
    if ( 'trash' !== $status ) {
        $total_live += $n;
    }
}
say( sprintf( '  %-10s %d', 'to clear', $total_live ) );
say();

if ( 'clear' === $sfaf_mode ) {
    /*
     * TRASHED, NOT DELETED, AND THAT IS THE WHOLE DIFFERENCE.
     *
     * wp_trash_post() is what the plugin's own remove actions use, so a
     * cleared event is in the state the software already understands, and a
     * wrong call here is one click to undo rather than a restore from backup.
     * Emptying the trash afterwards is a separate decision and a separate
     * screen.
     */
    $cleared = 0;
    foreach ( array( 'publish', 'pending', 'draft', 'future', 'private' ) as $status ) {
        $ids = get_posts( array(
            'post_type'      => 'uc_event',
            'post_status'    => $status,
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ) );
        foreach ( $ids as $id ) {
            if ( wp_trash_post( $id ) ) {
                $cleared++;
            }
        }
    }
    $after = sfaf_import_event_counts();
    say( 'CLEARED' );
    rule( '-' );
    say( '  moved to trash: ' . $cleared );
    foreach ( $after as $status => $n ) {
        say( sprintf( '  %-10s %d', $status, $n ) );
    }
    say();
    say( 'Nothing was deleted. Each one reinstates from the trash.' );
    exit;
}

/* ===========================================================================
 * 2. Organizers, venues and categories.
 * ======================================================================== */

/** A term by name in one taxonomy, or 0. Case-insensitive. */
function sfaf_import_term_by_name( $name, $taxonomy ) {
    $name = trim( (string) $name );
    if ( '' === $name ) {
        return 0;
    }
    $term = get_term_by( 'name', $name, $taxonomy );
    if ( $term && ! is_wp_error( $term ) ) {
        return (int) $term->term_id;
    }
    // get_term_by( 'name' ) is already case-insensitive on a normal collation,
    // but the fold is the database's, not PHP's. This is the belt to that
    // brace, and it also catches the doubled spaces the old data has.
    $needle = strtolower( preg_replace( '/\s+/u', ' ', $name ) );
    $all    = get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false ) );
    if ( is_wp_error( $all ) ) {
        return 0;
    }
    foreach ( $all as $t ) {
        if ( strtolower( preg_replace( '/\s+/u', ' ', $t->name ) ) === $needle ) {
            return (int) $t->term_id;
        }
    }
    return 0;
}

say( 'ORGANIZERS' );
rule( '-' );
$org_ids = array();
foreach ( $sfaf_plan['organizers'] as $name => $aliases ) {
    $id  = 0;
    $how = '';
    foreach ( array_merge( array( $name ), $aliases ) as $candidate ) {
        $id = sfaf_import_term_by_name( $candidate, 'uc_organizer' );
        if ( $id ) {
            $how = ( $candidate === $name ) ? 'found' : 'found as "' . $candidate . '"';
            break;
        }
    }
    if ( ! $id ) {
        if ( $WRITING ) {
            $res = SFAF_Organizers::save( 0, $name );
            if ( is_wp_error( $res ) ) {
                say( sprintf( '  %-36s FAILED: %s', $name, $res->get_error_message() ) );
                continue;
            }
            $id  = (int) $res;
            $how = 'created';
        } else {
            $how = 'would create';
        }
    }
    $org_ids[ $name ] = $id;
    say( sprintf( '  %-36s %s', $name, $how ) );
}
say();

say( 'VENUES' );
rule( '-' );
$venue_ids = array();
foreach ( $sfaf_plan['venues'] as $name => $parts ) {
    $id  = sfaf_import_term_by_name( $name, 'uc_venue' );
    $how = $id ? 'found, address left alone' : '';
    if ( ! $id ) {
        // The old filing prefix, in case caladmin carries it too.
        $id = sfaf_import_term_by_name( 'Calendar Events: ' . $name, 'uc_venue' );
        if ( $id ) {
            $how = 'found as "Calendar Events: ' . $name . '", left alone';
        }
    }
    if ( ! $id ) {
        if ( $WRITING ) {
            $res = SFAF_Venues::save( 0, $name, $parts );
            if ( is_wp_error( $res ) ) {
                say( sprintf( '  %-36s FAILED: %s', $name, $res->get_error_message() ) );
                continue;
            }
            $id  = (int) $res;
            $how = 'created with ' . trim( implode( ', ', array_filter( $parts ) ) );
        } else {
            $how = 'would create with ' . trim( implode( ', ', array_filter( $parts ) ) );
        }
    }
    $venue_ids[ $name ] = $id;
    say( sprintf( '  %-36s %s', $name, $how ) );
}
say();

/*
 * CATEGORIES ARE RESOLVED, NEVER CREATED.
 *
 * Every category in caladmin carries a brand color and an icon that somebody
 * chose, and a term created here would carry the defaults and look like a
 * mistake on the calendar. So each name on the plan is a preference order, the
 * first one that exists wins, and an event whose category is absent is created
 * without one and named in the report.
 */
say( 'CATEGORIES' );
rule( '-' );
$cat_ids  = array();
$cat_miss = array();
foreach ( $sfaf_plan['series'] as $s ) {
    foreach ( $s['events'] as $e ) {
        foreach ( $e['cats'] as $choices ) {
            $key = $choices[0];
            if ( isset( $cat_ids[ $key ] ) || isset( $cat_miss[ $key ] ) ) {
                continue;
            }
            foreach ( $choices as $c ) {
                $id = sfaf_import_term_by_name( $c, 'uc_event_category' );
                if ( $id ) {
                    $cat_ids[ $key ] = $id;
                    say( sprintf( '  %-36s found as "%s"', $key, $c ) );
                    break;
                }
            }
            if ( ! isset( $cat_ids[ $key ] ) ) {
                $cat_miss[ $key ] = $choices;
                say( sprintf( '  %-36s NOT FOUND. Tried: %s', $key, implode( '; ', $choices ) ) );
            }
        }
    }
}
if ( $cat_miss ) {
    say();
    say( '  Events wanting a category that is not there are created without one.' );
    say( '  Add the category in caladmin and set it on the drafts, or say which' );
    say( '  existing category each should use.' );
}
say();

/* ===========================================================================
 * 3. Series and events.
 * ======================================================================== */

say( 'SERIES AND EVENTS' );
rule( '-' );

$made_series   = 0;
$found_series  = 0;
$made_events   = 0;
$made_posts    = 0;
$failures      = array();

foreach ( $sfaf_plan['series'] as $s ) {
    $term_id = sfaf_import_term_by_name( $s['name'], SFAF_Series::TAXONOMY );
    if ( $term_id ) {
        $found_series++;
        $note = 'existing series, left as it is';
    } else {
        if ( $WRITING ) {
            $res = SFAF_Series::create( $s['name'] );
            if ( is_wp_error( $res ) ) {
                $failures[] = 'series "' . $s['name'] . '": ' . $res->get_error_message();
                say( sprintf( '  %-46s FAILED', $s['name'] ) );
                continue;
            }
            $term_id = (int) $res;
            $note    = 'created';
        } else {
            $note = 'would create';
        }
        $made_series++;
    }
    say( sprintf( '  %-46s %s', $s['name'], $note ) );

    foreach ( $s['events'] as $e ) {
        $d     = sfaf_import_plan_dates( $e, $today, $horizon );
        $count = ( '' === $d['seed'] ) ? 0 : 1 + count( $d['dates'] );
        $made_events++;
        $made_posts += max( 1, $count );

        say( sprintf( '      %-42s %s, %s',
            $e['title'],
            ( '' !== $e['source'] ? 'from "' . $e['source'] . '"' : 'nothing matched' ),
            ( $count ? $count . ' dates from ' . $d['seed'] : 'no date' )
        ) );

        if ( ! $WRITING ) {
            continue;
        }

        /*
         * THE SEED IS AN ORDINARY EVENT FROM THE MOMENT IT EXISTS, which is why
         * every write below is the one the plugin already makes: the same meta
         * keys the editor saves, SFAF_Venues::set_for_event() for the place,
         * SFAF_Online::set() for an online one, and SFAF_Recurrence::generate()
         * for the dates. Nothing here writes a post row the plugin would not
         * have written itself.
         */
        $post_id = wp_insert_post( array(
            'post_type'    => 'uc_event',
            'post_status'  => 'draft',
            'post_title'   => $e['title'],
            'post_content' => $e['content'],
        ), true );
        if ( is_wp_error( $post_id ) || ! $post_id ) {
            $failures[] = 'event "' . $e['title'] . '": ' . ( is_wp_error( $post_id ) ? $post_id->get_error_message() : 'insert returned nothing' );
            continue;
        }
        $post_id = (int) $post_id;

        wp_set_object_terms( $post_id, array( (int) $term_id ), SFAF_Series::TAXONOMY );

        if ( ! empty( $org_ids[ $s['organizer'] ] ) ) {
            wp_set_object_terms( $post_id, array( (int) $org_ids[ $s['organizer'] ] ), 'uc_organizer' );
        }

        $cats = array();
        foreach ( $e['cats'] as $choices ) {
            if ( ! empty( $cat_ids[ $choices[0] ] ) ) {
                $cats[] = (int) $cat_ids[ $choices[0] ];
            }
        }
        if ( $cats ) {
            wp_set_object_terms( $post_id, $cats, 'uc_event_category' );
        }

        /*
         * THE DATE IS WRITTEN EVEN WHEN IT IS EMPTY.
         *
         * The caladmin event list orders by the _uc_event_date meta key, and a
         * WP_Query that orders by a meta key drops every post that has no row
         * for it. An event with no schedule yet is exactly what this import
         * hands Mark to fill in, so it has to be in the list to be filled in.
         * An empty row sorts; an absent row disappears.
         */
        update_post_meta( $post_id, '_uc_event_date', $d['seed'] );
        update_post_meta( $post_id, '_uc_start_time', $e['start'] );
        update_post_meta( $post_id, '_uc_end_time', $e['end'] );

        if ( $e['online'] ) {
            // No meeting link: the export carries none, and a link is the one
            // thing this import must not invent.
            SFAF_Online::set( $post_id, true, '', array() );
        } elseif ( '' !== $e['venue'] && ! empty( $venue_ids[ $e['venue'] ] ) ) {
            SFAF_Venues::set_for_event( $post_id, (int) $venue_ids[ $e['venue'] ] );
            // The venue carries the address now, so the composed line goes.
            delete_post_meta( $post_id, '_uc_location' );
        }

        if ( '' !== $e['image'] ) {
            $attach = attachment_url_to_postid( $e['image'] );
            if ( $attach ) {
                set_post_thumbnail( $post_id, (int) $attach );
            } else {
                // The file is on this site already, so a miss means the URL in
                // the export no longer resolves to a media row. Recorded as the
                // fallback the plugin already reads rather than left empty.
                update_post_meta( $post_id, '_uc_image_url', esc_url_raw( $e['image'] ) );
            }
        }

        if ( $count > 1 ) {
            $gen = SFAF_Recurrence::generate( $post_id, $d['gen_pattern'], $horizon, 0, $d['extra'] );
            if ( count( $gen['created'] ) !== count( $d['dates'] ) ) {
                $failures[] = sprintf(
                    'event "%s": planned %d further dates, created %d',
                    $e['title'], count( $d['dates'] ), count( $gen['created'] )
                );
            }
        }
    }
}

say();
rule();
say( sprintf( 'series:  %d existing, %d %s', $found_series, $made_series, $WRITING ? 'created' : 'to create' ) );
say( sprintf( 'events:  %d named on the list', $made_events ) );
say( sprintf( 'posts:   %d %s, all drafts', $made_posts, $WRITING ? 'created' : 'to create' ) );

if ( $WRITING ) {
    $after = sfaf_import_event_counts();
    say();
    say( 'EVENTS ON THE SITE NOW' );
    rule( '-' );
    foreach ( $after as $status => $n ) {
        say( sprintf( '  %-10s %d  (was %d)', $status, $n, $before[ $status ] ) );
    }
}

if ( $failures ) {
    say();
    say( 'FAILURES' );
    rule( '-' );
    foreach ( $failures as $f ) {
        say( '  ' . $f );
    }
} else {
    say();
    say( 'no failures.' );
}

if ( ! $WRITING ) {
    say();
    say( 'Nothing was written. Run clear first, then import.' );
}
