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
/*
 * EVERY CLASS THIS FILE READS, NOT JUST THE TWO IT WRITES THROUGH. The clear
 * decides what to spare by asking SFAF_Sources and SFAF_Submissions, so a
 * partial load would make every queue row look like a hand-made test event and
 * trash it. Refusing is the only safe answer to that.
 */
foreach ( array( 'SFAF_Series', 'SFAF_Recurrence', 'SFAF_Sources', 'SFAF_Submissions', 'SFAF_Reminders', 'SFAF_Venues', 'SFAF_Organizers', 'SFAF_Online' ) as $needed ) {
    if ( ! class_exists( $needed ) ) {
        exit( "The SFAF Calendar plugin is not fully loaded ({$needed} is missing). Nothing was done.\n" );
    }
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

/* ---------------------------------------------------------------------------
 * THE RUN LOG, AND WHY THERE IS ONE.
 *
 * The three modes were run out of order once, IMPORT then REPORT then CLEAR,
 * and the clear trashed everything the import had just made. Nothing was lost,
 * because clear trashes rather than deletes, but it cost an afternoon. The
 * import also ran twice, which nothing stopped and nothing reported.
 *
 * So each run records itself, every mode prints what has run before, and import
 * refuses to add a second copy on top of a first without being told twice.
 * ------------------------------------------------------------------------ */
define( 'SFAF_TEC_LOG_OPTION', 'sfaf_tec_import_log' );

function sfaf_import_log_read() {
    $log = get_option( SFAF_TEC_LOG_OPTION, array() );
    return is_array( $log ) ? $log : array();
}

function sfaf_import_log_add( $mode, $note ) {
    $log   = sfaf_import_log_read();
    $log[] = array(
        'mode' => (string) $mode,
        'when' => current_time( 'Y-m-d H:i:s' ),
        'who'  => (string) wp_get_current_user()->user_login,
        'note' => (string) $note,
    );
    // Bounded, so a runaway loop cannot grow an option without limit.
    if ( count( $log ) > 50 ) {
        $log = array_slice( $log, -50 );
    }
    update_option( SFAF_TEC_LOG_OPTION, $log, false );
}

/**
 * Live events already sitting in the series this plan would fill.
 *
 * THE PRECONDITION FOR IMPORT IS NOT "CLEAR HAS RUN", IT IS "THESE EVENTS ARE
 * NOT ALREADY HERE". Those are different, and only the second is what actually
 * goes wrong: a clear that ran a week ago is no protection, and a site that was
 * never dirty needs no clear at all. So this asks the question that matters.
 *
 * Trashed rows do not count. An import whose output is in the trash is not a
 * duplicate waiting to happen; it is an import that was undone.
 *
 * @return array<string,int> series name => live events in it.
 */
function sfaf_import_existing_in_plan( $plan ) {
    $out      = array();
    $statuses = array_values( array_diff( SFAF_Sources::all_statuses(), array( 'trash' ) ) );

    foreach ( $plan['series'] as $s ) {
        $term = sfaf_import_term_by_name( $s['name'], SFAF_Series::TAXONOMY );
        if ( ! $term ) {
            continue;
        }
        $ids = get_posts( array(
            'post_type'      => 'uc_event',
            'post_status'    => $statuses,
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'tax_query'      => array( array(
                'taxonomy' => SFAF_Series::TAXONOMY,
                'field'    => 'term_id',
                'terms'    => (int) $term,
            ) ),
        ) );
        if ( $ids ) {
            $out[ $s['name'] ] = count( $ids );
        }
    }
    return $out;
}

$today   = current_time( 'Y-m-d' );
$horizon = $sfaf_plan['horizon'];

say( 'SFAF CALENDAR: ONE-TIME IMPORT FROM THE EVENTS CALENDAR' );
rule();
say( 'mode:    ' . $sfaf_mode . ( $WRITING ? '  (WRITING)' : '  (reads only, writes nothing)' ) );
say( 'today:   ' . $today );
say( 'horizon: ' . $horizon );
say( 'plan:    ' . $sfaf_plan['generated'] );

/*
 * SAID OUT LOUD, IN EVERY MODE, because the address that would otherwise be
 * added is the one nobody typed. See sfaf_import_silence().
 */
$sfaf_author = wp_get_current_user();
say( 'author:  ' . ( ( $sfaf_author && $sfaf_author->ID )
    ? $sfaf_author->display_name . ' <' . $sfaf_author->user_email . '>'
    : 'nobody (no logged-in user)' ) );
say( '         Every post is created with that author AND with the author taken' );
say( '         off its notification list, so no mail can reach anybody. Nothing' );
say( '         else writes an address. Addresses inside descriptions stay.' );
say();

/* ---------------------------------------------------------------------------
 * What has already been run. Printed in EVERY mode, first, before anything
 * else, because the fault this prevents is not knowing.
 * ------------------------------------------------------------------------ */
$sfaf_log = sfaf_import_log_read();
say( 'WHAT HAS ALREADY BEEN RUN' );
rule( '-' );
if ( ! $sfaf_log ) {
    say( '  nothing recorded. Either this is the first run, or every run so far' );
    say( '  predates the log, which was added after the modes were run out of order.' );
} else {
    foreach ( $sfaf_log as $entry ) {
        say( sprintf( '  %-7s %s  by %-16s %s',
            $entry['mode'], $entry['when'], $entry['who'], $entry['note'] ) );
    }
}
say();

/* ===========================================================================
 * 1. The existing events.
 * ======================================================================== */

/**
 * Every uc_event, counted by status.
 *
 * THE STATUS LIST COMES FROM THE PLUGIN, NOT FROM HERE. The first version of
 * this named six statuses by hand and so could not see the two that matter
 * most: `uc_imported` and `uc_dismissed` are custom statuses, and a hand-typed
 * list silently reported the Pending and Dismissed queues as empty when they
 * were not. SFAF_Sources::all_statuses() is the same list find_existing() uses.
 */
function sfaf_import_event_counts() {
    $counts = array();
    foreach ( SFAF_Sources::all_statuses() as $status ) {
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

/**
 * Why one event must survive the clear, or '' when it is Mark's to remove.
 *
 * THE CLEAR IS FOR TEST EVENTS SOMEBODY MADE BY HAND AND NOTHING ELSE. A row in
 * a queue is a decision waiting to be made, or one already made, and neither is
 * a test event.
 *
 * WHY TRASHING A QUEUE ROW WOULD BE DATA LOSS RATHER THAN AN INCONVENIENCE.
 * SFAF_Sources::all_statuses() includes 'trash' and find_existing() searches
 * with it, so the next fetch MATCHES a trashed row. It then finds that 'trash'
 * is not in updatable_statuses(), counts the row as untouched and moves on.
 * **It does not create a new one.** So a trashed import does not come back on a
 * re-fetch; it comes back only if somebody restores it from the trash by hand,
 * and if WordPress empties the trash first the row and every decision recorded
 * on it are gone. The plugin says this itself in SFAF_Sources, in the paragraph
 * explaining why an expired pending row is dismissed rather than trashed.
 *
 * FOUR THINGS ARE KEPT, and status alone is not enough to find them all. An
 * import that vanished at its source is parked as an ordinary `draft`, and a
 * submission awaiting review is an ordinary `pending`, so both look exactly
 * like a hand-made test event until the provenance meta is read.
 *
 * @param int $post_id
 * @return string A reason, or '' to clear.
 */
function sfaf_import_keep_reason( $post_id ) {
    $post_id = (int) $post_id;
    $status  = (string) get_post_status( $post_id );

    if ( SFAF_Sources::STATUS_PENDING === $status ) {
        return 'in the Pending queue';
    }
    if ( SFAF_Sources::STATUS_DISMISSED === $status ) {
        return 'in the Dismissed queue';
    }
    if ( '' !== (string) get_post_meta( $post_id, SFAF_Submissions::META_KIND, true ) ) {
        return 'a submission awaiting review';
    }
    if ( '' !== (string) get_post_meta( $post_id, SFAF_Sources::META_EXTERNAL_ID, true )
        || '' !== (string) get_post_meta( $post_id, SFAF_Sources::META_SOURCE, true ) ) {
        return 'imported from a source';
    }
    if ( '' !== (string) get_post_meta( $post_id, SFAF_Sources::META_REMOVED_AT, true ) ) {
        return 'an import that vanished at the source';
    }
    return '';
}

/**
 * Split every event into what the clear would remove and what it would keep.
 *
 * Already-trashed rows are not candidates: they are out of the way already and
 * trashing them again would do nothing.
 *
 * @return array{clear:int[],keep:array<int,string>}
 */
function sfaf_import_clear_plan() {
    $out      = array( 'clear' => array(), 'keep' => array() );
    $statuses = array_values( array_diff( SFAF_Sources::all_statuses(), array( 'trash' ) ) );

    $ids = get_posts( array(
        'post_type'      => 'uc_event',
        'post_status'    => $statuses,
        'posts_per_page' => -1,
        'fields'         => 'ids',
        'no_found_rows'  => true,
    ) );
    foreach ( $ids as $id ) {
        $reason = sfaf_import_keep_reason( $id );
        if ( '' === $reason ) {
            $out['clear'][] = (int) $id;
        } else {
            $out['keep'][ (int) $id ] = $reason;
        }
    }
    return $out;
}

$before = sfaf_import_event_counts();
say( 'EVENTS ON THE SITE NOW' );
rule( '-' );
foreach ( $before as $status => $n ) {
    say( sprintf( '  %-14s %d', $status, $n ) );
}
say();

$plan_clear = sfaf_import_clear_plan();
$keep_by    = array();
foreach ( $plan_clear['keep'] as $id => $reason ) {
    $keep_by[ $reason ] = ( $keep_by[ $reason ] ?? 0 ) + 1;
}
$clear_by = array();
foreach ( $plan_clear['clear'] as $id ) {
    $s              = (string) get_post_status( $id );
    $clear_by[ $s ] = ( $clear_by[ $s ] ?? 0 ) + 1;
}

say( 'WHAT THE CLEAR WOULD REMOVE' );
rule( '-' );
if ( ! $clear_by ) {
    say( '  nothing' );
}
foreach ( $clear_by as $s => $n ) {
    say( sprintf( '  %-14s %d', $s, $n ) );
}
say( sprintf( '  %-14s %d', 'TOTAL', count( $plan_clear['clear'] ) ) );
say();

say( 'WHAT IT LEAVES ALONE' );
rule( '-' );
if ( ! $keep_by ) {
    say( '  nothing: no queue rows, submissions or imported events exist' );
}
foreach ( $keep_by as $reason => $n ) {
    say( sprintf( '  %-38s %d', $reason, $n ) );
}
if ( $plan_clear['keep'] ) {
    say();
    say( '  Named, because these are the rows a wrong clear would cost:' );
    foreach ( $plan_clear['keep'] as $id => $reason ) {
        say( sprintf( '    #%-6d %-38s %s', $id, $reason, get_the_title( $id ) ) );
    }
    say();
    say( '  A TRASHED IMPORT DOES NOT COME BACK ON A RE-FETCH. all_statuses()' );
    say( '  includes trash and find_existing() searches with it, so the next' );
    say( '  fetch matches the trashed row, finds trash is not updatable, and' );
    say( '  counts it untouched rather than creating a new one.' );
}
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
    /*
     * IT CLEARS THE LIST IT JUST PRINTED, and nothing else. The ids come from
     * sfaf_import_clear_plan(), which is the same call that produced the
     * breakdown above, so what was named is what goes and there is no second
     * definition of "clearable" to drift from the first.
     *
     * The keep reason is asked again per row, because between printing the
     * list and acting on it a fetch could have run.
     */
    $cleared = 0;
    $spared  = 0;
    foreach ( $plan_clear['clear'] as $id ) {
        $reason = sfaf_import_keep_reason( $id );
        if ( '' !== $reason ) {
            $spared++;
            say( sprintf( '  spared #%d, now %s', $id, $reason ) );
            continue;
        }
        if ( wp_trash_post( $id ) ) {
            $cleared++;
        }
    }
    $after = sfaf_import_event_counts();
    say( 'CLEARED' );
    rule( '-' );
    say( '  moved to trash:  ' . $cleared );
    say( '  left alone:      ' . count( $plan_clear['keep'] ) );
    if ( $spared ) {
        say( '  spared late:     ' . $spared . ' (changed between the listing and the clear)' );
    }
    say();
    foreach ( $after as $status => $n ) {
        say( sprintf( '  %-14s %d  (was %d)', $status, $n, $before[ $status ] ) );
    }
    say();
    say( 'Nothing was deleted. Each one reinstates from the trash.' );
    say( 'No queue row, submission or imported event was touched.' );
    sfaf_import_log_add( 'clear', sprintf(
        'trashed %d, left alone %d', $cleared, count( $plan_clear['keep'] ) ) );
    exit;
}

/* ===========================================================================
 * 2. Organizers, venues and categories.
 * ======================================================================== */

/**
 * NOTHING THIS IMPORT CREATES MAY CAUSE MAIL TO ANYBODY.
 *
 * The calendar has not rolled out. An address on an event's notification list
 * means a real person starts receiving registration alerts and pre-event
 * summaries the moment that event is published, and 287 drafts are about to be
 * created for somebody to publish in bulk.
 *
 * THE ADDRESS THIS IMPORT WOULD OTHERWISE ADD IS THE AUTHOR'S, AND NOBODY
 * TYPED IT. SFAF_Reminders::notify_entries() reads four sources: post_author,
 * _uc_notify_users, _uc_notify_emails, and the event's teams. This import
 * writes none of the last three. But wp_insert_post() defaults post_author to
 * whoever is logged in, and the creator is on the list unless the event says
 * otherwise, so running this from a browser would put the administrator who
 * ran it on all 287 lists without a single address being typed anywhere.
 *
 * AND THE OPT-OUT DOES NOT TRAVEL TO AN OCCURRENCE. SFAF_Recurrence copies
 * post_author onto every generated occurrence and its $copied_meta carries none
 * of the three notification keys, which is correct for a manager creating a
 * group by hand and wrong here. So this runs on the seed AND on every date
 * generated from it, one at a time.
 *
 * THE CHECK IS THE PLUGIN'S OWN RESOLVER, NOT AN ASSERTION ABOUT IT.
 * notify_list() is what the mail actually asks, so asking it back is the only
 * thing that proves the list is empty. A future default that put somebody on a
 * list by another route would be caught by this and not by any reasoning about
 * which keys are written.
 *
 * Addresses inside descriptions are text on a page, no send path reads them,
 * and they stay exactly as written.
 *
 * @param int $post_id
 * @return string '' when nobody is on the list, or the addresses that are.
 */
function sfaf_import_silence( $post_id ) {
    $post_id = (int) $post_id;
    if ( ! $post_id ) {
        return 'no post id';
    }

    update_post_meta( $post_id, SFAF_Reminders::NOTIFY_AUTHOR_OPTOUT_META, '1' );
    delete_post_meta( $post_id, SFAF_Reminders::NOTIFY_USERS_META );
    delete_post_meta( $post_id, SFAF_Reminders::NOTIFY_EMAILS_META );

    $list = SFAF_Reminders::notify_list( $post_id );
    return empty( $list ) ? '' : implode( ', ', array_keys( $list ) );
}

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

/* ---------------------------------------------------------------------------
 * THE PREFLIGHT. Import will not quietly add a second copy on top of a first.
 *
 * Reported in every mode so report mode answers "is it safe to import" without
 * anybody having to run import to find out. Enforced only in import mode, and
 * overridable, because a deliberate top-up is a real thing to want; what is not
 * a real thing to want is doing it by accident.
 * ------------------------------------------------------------------------ */
$sfaf_existing = sfaf_import_existing_in_plan( $sfaf_plan );
$sfaf_existing_total = array_sum( $sfaf_existing );

say( 'WHAT IS ALREADY IN THESE SERIES' );
rule( '-' );
if ( ! $sfaf_existing ) {
    say( '  nothing. Importing adds to empty series.' );
} else {
    foreach ( $sfaf_existing as $name => $n ) {
        say( sprintf( '  %-46s %d live event(s)', $name, $n ) );
    }
    say( sprintf( '  %-46s %d', 'TOTAL', $sfaf_existing_total ) );
    say();
    say( '  Trashed events are not counted: an import sitting in the trash has' );
    say( '  been undone and is not a duplicate waiting to happen.' );
}
say();

if ( 'import' === $sfaf_mode && $sfaf_existing_total > 0 ) {
    $anyway = $sfaf_import_cli
        ? in_array( 'anyway', array_slice( isset( $argv ) ? $argv : array(), 1 ), true )
        : ( isset( $_GET['anyway'] ) && 'YES' === strtoupper( sanitize_text_field( wp_unslash( $_GET['anyway'] ) ) ) );

    if ( ! $anyway ) {
        say( 'REFUSED' );
        rule( '-' );
        say( sprintf( '  %d event(s) already live in the series this would fill.', $sfaf_existing_total ) );
        say( '  Importing now would add a SECOND copy of every event listed above,' );
        say( '  not replace them. Nothing has been written.' );
        say();
        say( '  Run clear first, or add &anyway=YES if a second copy is genuinely' );
        say( '  what you want.' );
        sfaf_import_log_add( 'import', sprintf( 'refused: %d live events already in the plan\'s series', $sfaf_existing_total ) );
        exit;
    }
    say( 'PROCEEDING ON TOP OF ' . $sfaf_existing_total . ' EXISTING EVENT(S), because anyway=YES was given.' );
    say();
}

say( 'SERIES AND EVENTS' );
rule( '-' );

$made_series   = 0;
$found_series  = 0;
$made_events   = 0;
$made_posts    = 0;
$silenced      = 0;
$failures      = array();
$skipped_images = array();

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

        /*
         * THE PICTURE IS ONLY CARRIED ACROSS IF IT IS IN THE CALENDAR FOLDER
         * (3.82.0).
         *
         * WHAT THIS USED TO DO, AND WHAT IT COST. It set whatever picture the
         * export named, which is what an import normally does and was nobody's
         * decision here. The 2026-09-03 run put 73 events' pictures outside the
         * calendar folder: the wrong shape for a 16:9 card, untagged, invisible
         * to the picker and unreachable from the Images screen. Clearing them
         * was a separate job, and a one-time clear that the next import undoes
         * is not a fix.
         *
         * THE RULE IS MARK'S: a picture that does not sit in the calendar
         * folder is not used. This is that rule at the WRITE, which is the only
         * place it can be enforced without the editor showing one picture and
         * the calendar another.
         *
         * A SKIPPED PICTURE IS REPORTED, NOT SWALLOWED. The event still gets
         * its series' picture through the normal chain, and the run says how
         * many it passed over so nobody has to notice the absence later.
         */
        if ( '' !== $e['image'] ) {
            $attach = attachment_url_to_postid( $e['image'] );
            if ( $attach && class_exists( 'SFAF_Media_Folder' ) && ! SFAF_Media_Folder::holds( (int) $attach ) ) {
                $skipped_images[] = $e['title'] . '  ' . $e['image'];
            } elseif ( $attach ) {
                set_post_thumbnail( $post_id, (int) $attach );
            } elseif ( class_exists( 'SFAF_Media_Folder' )
                && false === strpos( $e['image'], '/wp-content/uploads/' . SFAF_Media_Folder::prefix() ) ) {
                /* A URL that did not resolve to an attachment AND is not in the
                 * folder. There is nothing here worth recording: it would be a
                 * picture the calendar has undertaken not to use. */
                $skipped_images[] = $e['title'] . '  ' . $e['image'];
            } else {
                // In the folder but no media row: the URL in the export no
                // longer resolves. Recorded as the fallback the plugin already
                // reads rather than left empty.
                update_post_meta( $post_id, '_uc_image_url', esc_url_raw( $e['image'] ) );
            }
        }

        /*
         * SILENCED BEFORE THE OCCURRENCES EXIST AND AGAIN AFTER, because the
         * opt-out is not among the meta an occurrence inherits. Every post this
         * import creates goes through this, and every one of them is checked.
         */
        $left = sfaf_import_silence( $post_id );
        if ( '' !== $left ) {
            $failures[] = 'event "' . $e['title'] . '" still has a notification list: ' . $left;
        }
        $silenced++;

        if ( $count > 1 ) {
            $gen = SFAF_Recurrence::generate( $post_id, $d['gen_pattern'], $horizon, 0, $d['extra'] );
            if ( count( $gen['created'] ) !== count( $d['dates'] ) ) {
                $failures[] = sprintf(
                    'event "%s": planned %d further dates, created %d',
                    $e['title'], count( $d['dates'] ), count( $gen['created'] )
                );
            }
            foreach ( $gen['created'] as $occ_id ) {
                $left = sfaf_import_silence( $occ_id );
                if ( '' !== $left ) {
                    $failures[] = 'occurrence ' . (int) $occ_id . ' of "' . $e['title'] . '" still has a notification list: ' . $left;
                }
                $silenced++;
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
    say( sprintf( 'mail:    %d of %d posts checked, every notification list empty', $silenced, $made_posts ) );
} else {
    say( 'mail:    every post created will have an empty notification list, checked one at a time' );
}

if ( $WRITING ) {
    $after = sfaf_import_event_counts();
    say();
    say( 'EVENTS ON THE SITE NOW' );
    rule( '-' );
    foreach ( $after as $status => $n ) {
        say( sprintf( '  %-10s %d  (was %d)', $status, $n, $before[ $status ] ) );
    }
}

/*
 * THE PICTURES THIS RUN PASSED OVER, SAID OUT LOUD (3.82.0).
 *
 * Not a failure, which is why it is its own block: the rule is that a picture
 * outside the calendar folder is not used, and skipping one is that rule
 * working. It is reported because the alternative is nobody noticing until an
 * event looks empty, which is how the 73 got there in the first place.
 */
if ( $skipped_images ) {
    say();
    say( 'PICTURES NOT CARRIED ACROSS, because they are outside the calendar folder' );
    rule( '-' );
    foreach ( $skipped_images as $s ) {
        say( '  ' . $s );
    }
    say();
    say( '  ' . count( $skipped_images ) . ' skipped. Each event takes its series picture instead,' );
    say( '  and picks up a new one the moment a picture is tagged to that series.' );
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

if ( 'import' === $sfaf_mode ) {
    sfaf_import_log_add( 'import', sprintf(
        'created %d post(s) across %d series, %d failure(s)',
        $made_posts, count( $sfaf_plan['series'] ), count( $failures ) ) );
}

if ( ! $WRITING ) {
    say();
    if ( $sfaf_existing_total > 0 ) {
        say( 'Nothing was written. Import would REFUSE right now: ' . $sfaf_existing_total
            . ' event(s) are already live in these series. Run clear first.' );
    } else {
        say( 'Nothing was written. These series are empty, so import is safe to run.' );
    }
}
