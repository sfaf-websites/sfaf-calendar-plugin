<?php
/**
 * CLEAR THE EVENT PICTURES THAT CAME FROM OUTSIDE THE CALENDAR FOLDER.
 *
 * WHY. The 2026-09-03 import carried each event's featured image across from
 * The Events Calendar. That is what an import normally does, it was not asked
 * for, and it happened without a decision. The result is pictures outside the
 * calendar folder, in the wrong shape for a 16:9 card, untagged, invisible to
 * the picker and unreachable from the Images screen.
 *
 * Mark's decision: a picture that does not sit in the calendar folder is not
 * used. Roxane is producing the event pictures and those are the ones going on.
 *
 * ============================================================================
 * IT DELETES NOTHING. NO FILE, NO ATTACHMENT.
 * ============================================================================
 * Every file stays in the media library exactly where it is. What is removed is
 * the EVENT'S REFERENCE to it: `_thumbnail_id`, and `_uc_image_url` where that
 * is what is carrying the picture. Resources is at 17GB of 20GB and deleting
 * would be tempting; it is not what this does and there is no mode that does.
 *
 * AND IT IS UNDOABLE. Every value it clears is written to a parallel key first,
 * so `--undo` puts all of them back. That costs 73 meta rows and buys a way out
 * of a mistake on 73 events.
 *
 * ============================================================================
 * HOW IT DECIDES WHAT THE IMPORT SET, WHICH IS THE PART THAT MATTERS
 * ============================================================================
 * The question is not "is this picture outside the folder". It is "did anybody
 * CHOOSE this picture", because one Mark chose must survive.
 *
 * THE FOLDER TEST ALONE IS NOT ENOUGH, and saying otherwise is how somebody's
 * work gets thrown away. caladmin's picker only ever offers `uploads/calendar/`,
 * so a picture outside it was not chosen THERE, but two other routes exist:
 * wp-admin's own post editor can set any attachment, and caladmin's image
 * control has a URL field that writes `_uc_image_url` by hand.
 *
 * SO THE IMPORT PLAN IS THE EVIDENCE. `plan.php` is the record of what the
 * import was told to set, image URLs included. A picture whose URL is in that
 * list was set by the import, as a fact rather than an inference. Anything else
 * outside the folder is REPORTED AND LEFT ALONE, because it got there some
 * other way and that way might have been a person.
 *
 * `--include-unmatched` clears those too, and has to be typed. Read the report
 * first: that list is short and every line in it is something to look at.
 *
 * ============================================================================
 * HOW TO RUN IT
 * ============================================================================
 * Put this file and plan.php together in a folder inside the WordPress install,
 * then:
 *
 *     wp eval-file clear-outside-folder.php              report, writes nothing
 *     wp eval-file clear-outside-folder.php apply        clear the matched ones
 *     wp eval-file clear-outside-folder.php apply-all    and the unmatched ones
 *     wp eval-file clear-outside-folder.php undo         put everything back
 *
 * or, logged in as an administrator:
 *
 *     .../clear-outside-folder.php?mode=report
 *     .../clear-outside-folder.php?mode=apply&confirm=CLEAR
 *     .../clear-outside-folder.php?mode=undo&confirm=UNDO
 *
 * REPORT IS THE DEFAULT AND WRITES NOTHING. The modes that write each need
 * their own confirmation word, so a shared URL cannot run one by being opened.
 *
 * ============================================================================
 * WHAT HAPPENS TO THOSE EVENTS AFTERWARDS
 * ============================================================================
 * `sfaf_event_image_url()` falls through to the next rung: the series' picture,
 * which from 3.81.0 resolves to a picture TAGGED to that series when the series
 * has none set. Where there is neither, the category placeholder. So the
 * calendar looks emptier until the new pictures are in, and every event picks up
 * its series' picture the moment one is tagged, with nothing further to run.
 */

if ( ! defined( 'ABSPATH' ) ) {
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

$cli = ( defined( 'WP_CLI' ) && WP_CLI ) || ( PHP_SAPI === 'cli' );

if ( ! $cli && ! current_user_can( 'manage_options' ) ) {
    wp_die( 'Administrators only.' );
}

if ( ! class_exists( 'SFAF_Media_Folder' ) ) {
    exit( "The SFAF Calendar plugin is not active, so the calendar folder is unknown.\n" );
}

/* ---------------------------------------------------------------------------
 * Mode, and the confirmation each writing mode needs.
 * ------------------------------------------------------------------------ */
$mode = 'report';
if ( $cli ) {
    foreach ( array_slice( (array) $argv, 1 ) as $a ) {
        if ( in_array( $a, array( 'report', 'apply', 'apply-all', 'undo' ), true ) ) {
            $mode = $a;
        }
    }
} else {
    $asked   = isset( $_GET['mode'] ) ? sanitize_key( $_GET['mode'] ) : 'report';
    $confirm = isset( $_GET['confirm'] ) ? sanitize_text_field( wp_unslash( $_GET['confirm'] ) ) : '';
    if ( in_array( $asked, array( 'apply', 'apply-all' ), true ) && 'CLEAR' === $confirm ) {
        $mode = $asked;
    } elseif ( 'undo' === $asked && 'UNDO' === $confirm ) {
        $mode = 'undo';
    }
    header( 'Content-Type: text/plain; charset=utf-8' );
}

/* Where a cleared value is kept, so undo is a real offer and not a hope. */
const SFAF_CLEARED_THUMB = '_uc_cleared_thumbnail_id';
const SFAF_CLEARED_URL   = '_uc_cleared_image_url';

$prefix = SFAF_Media_Folder::prefix();

/* ---------------------------------------------------------------------------
 * The import's own record of what it set.
 *
 * THIS IS WHAT MAKES "THE IMPORT DID IT" A FACT. Without plan.php the only
 * available test is the folder, which cannot tell an import from an
 * administrator using wp-admin.
 * ------------------------------------------------------------------------ */
$plan_urls = array();
$plan_file = __DIR__ . '/plan.php';
if ( file_exists( $plan_file ) ) {
    $plan = include $plan_file;
    if ( is_array( $plan ) ) {
        $walk = function ( $rows ) use ( &$walk, &$plan_urls ) {
            foreach ( (array) $rows as $row ) {
                if ( is_array( $row ) ) {
                    if ( isset( $row['image'] ) && '' !== $row['image'] ) {
                        $plan_urls[ sfaf_cof_norm( $row['image'] ) ] = true;
                    }
                    $walk( $row );
                }
            }
        };
        $walk( $plan );
    }
}

/**
 * One comparable form of a URL: no scheme, no host, no size suffix.
 *
 * THE SIZE SUFFIX IS WHY THIS EXISTS. The plan carries `Damn-Daddy.jpg` and the
 * page renders `Damn-Daddy-768x512.jpg`, because WordPress serves a generated
 * size. Comparing the two as strings would match nothing and the report would
 * say the import set none of them.
 */
function sfaf_cof_norm( $url ) {
    $path = (string) wp_parse_url( (string) $url, PHP_URL_PATH );
    $path = preg_replace( '#^.*/wp-content/uploads/#', '', $path );
    $path = preg_replace( '#-\d+x\d+(\.[A-Za-z0-9]+)$#', '$1', $path );
    return strtolower( ltrim( $path, '/' ) );
}

/* ---------------------------------------------------------------------------
 * Look at every event, and classify its rung-one picture.
 *
 * EVERY STATUS, because a draft is exactly what most of these are: all 287
 * imported events are drafts and the report would find almost nothing without
 * this.
 * ------------------------------------------------------------------------ */
$events = get_posts( array(
    'post_type'      => 'uc_event',
    'post_status'    => 'any',
    'posts_per_page' => -1,
    'fields'         => 'ids',
    'no_found_rows'  => true,
) );

$inside     = array();   // picture is in the calendar folder: never touched
$matched    = array();   // outside, and its URL is in the import plan
$unmatched  = array();   // outside, and it is not: reported, not cleared
$none       = 0;
$restorable = array();

foreach ( $events as $id ) {
    $thumb = (int) get_post_meta( $id, '_thumbnail_id', true );
    $url   = (string) get_post_meta( $id, '_uc_image_url', true );

    if ( get_post_meta( $id, SFAF_CLEARED_THUMB, true ) !== ''
        || get_post_meta( $id, SFAF_CLEARED_URL, true ) !== '' ) {
        $restorable[] = $id;
    }

    if ( ! $thumb && '' === $url ) {
        $none++;
        continue;
    }

    /* The attachment half. In the folder is the whole test here: an attachment
     * inside it is one the picker offers, whoever set it. */
    if ( $thumb ) {
        if ( SFAF_Media_Folder::holds( $thumb ) ) {
            $inside[] = $id;
            continue;
        }
        $file = (string) get_post_meta( $thumb, '_wp_attached_file', true );
        $row  = array(
            'id'    => $id,
            'title' => get_the_title( $id ) ?: '(untitled)',
            'kind'  => 'featured image',
            'what'  => $file,
        );
        if ( isset( $plan_urls[ sfaf_cof_norm( $file ) ] ) ) {
            $matched[] = $row;
        } else {
            $unmatched[] = $row;
        }
        continue;
    }

    /* The URL half. A URL is never "in the folder" as an attachment, so the
     * path is what decides, and an address on another site is outside by
     * definition. */
    $row = array(
        'id'    => $id,
        'title' => get_the_title( $id ) ?: '(untitled)',
        'kind'  => 'image URL',
        'what'  => $url,
    );
    if ( false !== strpos( $url, '/wp-content/uploads/' . $prefix ) ) {
        $inside[] = $id;
        continue;
    }
    if ( isset( $plan_urls[ sfaf_cof_norm( $url ) ] ) ) {
        $matched[] = $row;
    } else {
        $unmatched[] = $row;
    }
}

/* ---------------------------------------------------------------------------
 * Report. Always, before anything is written.
 * ------------------------------------------------------------------------ */
echo "Event pictures from outside the calendar folder\n";
echo str_repeat( '=', 62 ) . "\n";
echo 'calendar folder:  uploads/' . $prefix . "\n";
echo 'import plan:      ' . ( $plan_urls ? count( $plan_urls ) . ' picture(s) recorded' : 'NOT FOUND, so nothing can be matched to the import' ) . "\n";
echo 'events examined:  ' . count( $events ) . "\n\n";

printf( "  %-4d have no picture of their own\n", $none );
printf( "  %-4d have one INSIDE the calendar folder, which is never touched\n", count( $inside ) );
printf( "  %-4d have one outside it that the import set\n", count( $matched ) );
printf( "  %-4d have one outside it that the import did NOT set\n", count( $unmatched ) );
echo "\n";

if ( $matched ) {
    echo "FROM THE IMPORT, matched against plan.php. These are what `apply` clears:\n";
    foreach ( $matched as $r ) {
        printf( "  %-6d %-46s %s: %s\n", $r['id'], mb_substr( $r['title'], 0, 46 ), $r['kind'], $r['what'] );
    }
    echo "\n";
}

if ( $unmatched ) {
    echo "NOT FROM THE IMPORT. READ THIS LIST BEFORE CLEARING ANYTHING.\n";
    echo "Each of these is outside the folder and is not in the import plan, so it\n";
    echo "was set some other way, and one of those ways is a person choosing it.\n";
    echo "`apply` leaves them alone. `apply-all` clears them too.\n";
    foreach ( $unmatched as $r ) {
        printf( "  %-6d %-46s %s: %s\n", $r['id'], mb_substr( $r['title'], 0, 46 ), $r['kind'], $r['what'] );
    }
    echo "\n";
}

if ( $restorable ) {
    printf( "%d event(s) carry a cleared value that `undo` would put back.\n\n", count( $restorable ) );
}

if ( 'report' === $mode ) {
    echo "Nothing was written. Run `apply` once the lists above look right.\n";
    if ( ! $cli ) {
        echo "\nOr visit this file with ?mode=apply&confirm=CLEAR\n";
    }
    return;
}

/* ---------------------------------------------------------------------------
 * Undo.
 * ------------------------------------------------------------------------ */
if ( 'undo' === $mode ) {
    $back = 0;
    foreach ( $restorable as $id ) {
        $t = get_post_meta( $id, SFAF_CLEARED_THUMB, true );
        $u = get_post_meta( $id, SFAF_CLEARED_URL, true );
        if ( '' !== $t ) {
            update_post_meta( $id, '_thumbnail_id', (int) $t );
            delete_post_meta( $id, SFAF_CLEARED_THUMB );
            $back++;
        }
        if ( '' !== $u ) {
            update_post_meta( $id, '_uc_image_url', $u );
            delete_post_meta( $id, SFAF_CLEARED_URL );
            $back++;
        }
    }
    echo "Put back: $back reference(s) on " . count( $restorable ) . " event(s).\n";
    return;
}

/* ---------------------------------------------------------------------------
 * Clear. The value is recorded first, then removed, in that order, so a failure
 * between the two leaves something to restore rather than nothing.
 * ------------------------------------------------------------------------ */
$targets = $matched;
if ( 'apply-all' === $mode ) {
    $targets = array_merge( $matched, $unmatched );
}

$cleared = 0;
foreach ( $targets as $r ) {
    $id = (int) $r['id'];

    $thumb = (int) get_post_meta( $id, '_thumbnail_id', true );
    if ( $thumb ) {
        update_post_meta( $id, SFAF_CLEARED_THUMB, $thumb );
        delete_post_meta( $id, '_thumbnail_id' );
        $cleared++;
    }

    $url = (string) get_post_meta( $id, '_uc_image_url', true );
    if ( '' !== $url ) {
        update_post_meta( $id, SFAF_CLEARED_URL, $url );
        delete_post_meta( $id, '_uc_image_url' );
        $cleared++;
    }
}

/* ---------------------------------------------------------------------------
 * Count again, from the database, rather than subtracting.
 *
 * A COUNT DERIVED FROM THE ONE BEFORE IT ONLY SAYS WHAT THIS SCRIPT BELIEVES.
 * Asking again says what is there.
 * ------------------------------------------------------------------------ */
$still = 0;
foreach ( $events as $id ) {
    $thumb = (int) get_post_meta( $id, '_thumbnail_id', true );
    $url   = (string) get_post_meta( $id, '_uc_image_url', true );
    if ( $thumb && ! SFAF_Media_Folder::holds( $thumb ) ) {
        $still++;
        continue;
    }
    if ( '' !== $url && false === strpos( $url, '/wp-content/uploads/' . $prefix ) ) {
        $still++;
    }
}

echo str_repeat( '-', 62 ) . "\n";
printf( "before:   %d event(s) with a picture outside the calendar folder\n", count( $matched ) + count( $unmatched ) );
printf( "cleared:  %d reference(s) on %d event(s)\n", $cleared, count( $targets ) );
printf( "after:    %d event(s) with a picture outside the calendar folder\n", $still );
echo "\nNo file and no attachment was deleted. Every cleared value is recorded and\n";
echo "`undo` puts it back.\n";

if ( $still && 'apply' === $mode ) {
    echo "\nThe " . $still . " remaining are the ones the import did not set. They were\n";
    echo "left deliberately. Read the list above, then `apply-all` if they should go.\n";
}
