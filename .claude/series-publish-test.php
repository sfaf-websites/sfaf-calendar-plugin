<?php
/**
 * WHAT THE SERIES BULK PUBLISH WILL AND WILL NOT TOUCH.
 *
 * This is the only action on the schedule screen that reaches the public
 * calendar, and it acts on a whole series at once. The import creates 287
 * drafts across 32 series, so the set it acts on is large, and the rows it must
 * not touch are sitting in the same series: an event that vanished at its
 * source and was parked as a draft, a submission awaiting review, an event
 * somebody already published, and every date that has already been.
 *
 * SFAF_Series::publish_skip_reason() is the whole of that decision, which is
 * why it is a separate method: publishable() runs a WP_Query, and a rule that
 * needs a database to exercise is a rule nobody exercises.
 *
 * A SELF-TEST FIRST, because a checker that cannot fail is not evidence.
 * --self-test plants five holes in the rule and requires every one caught.
 *
 *     php .claude/series-publish-test.php --self-test
 *     php .claude/series-publish-test.php
 */

$self = in_array( '--self-test', array_slice( $argv, 1 ), true );
$root = dirname( __DIR__ );

define( 'ABSPATH', __DIR__ );

/* ---------------------------------------------------------------------------
 * WordPress and two classes, reduced to what the rule actually calls.
 * ------------------------------------------------------------------------ */
$GLOBALS['posts'] = array();

function get_post_status( $id ) {
    return isset( $GLOBALS['posts'][ $id ]['status'] ) ? $GLOBALS['posts'][ $id ]['status'] : false;
}
function get_post_meta( $id, $key, $single = false ) {
    return isset( $GLOBALS['posts'][ $id ]['meta'][ $key ] ) ? $GLOBALS['posts'][ $id ]['meta'][ $key ] : '';
}
function current_time( $type, $gmt = 0 ) {
    return '2026-09-10';
}
function wp_parse_args( $args, $defaults = array() ) {
    return array_merge( $defaults, (array) $args );
}
function sanitize_text_field( $s ) { return is_string( $s ) ? trim( $s ) : ''; }
function wp_strip_all_tags( $s, $b = false ) { return strip_tags( (string) $s ); }

class SFAF_Sources {
    const META_REMOVED_AT = '_uc_source_removed_at';
    public static function provenance( $post_id ) {
        return array(
            'source'      => (string) get_post_meta( $post_id, '_uc_external_source', true ),
            'external_id' => (string) get_post_meta( $post_id, '_uc_external_id', true ),
        );
    }
}
class SFAF_Submissions {
    const META_KIND = '_uc_submission_kind';
}

/* The rule under test, sliced out of the shipping class so this exercises the
   real source rather than a copy of it. Loading the whole file would drag in
   every WordPress function its other 900 lines call. */
function rule_source( $root ) {
    $src = file_get_contents( $root . '/includes/class-sfaf-series.php' );
    if ( false === $src ) { return ''; }
    if ( ! preg_match( '/public static function publish_skip_reason\(.*?\n    \}/s', $src, $m ) ) {
        return '';
    }
    return $m[0];
}

$rule = rule_source( $root );
if ( '' === $rule ) {
    echo "could not find publish_skip_reason() in class-sfaf-series.php\n";
    exit( 1 );
}

/** Build a throwaway class around whichever version of the rule is being run. */
function load_rule( $rule, $suffix ) {
    $cls = 'Ser' . $suffix;
    eval( 'class ' . $cls . ' { ' . $rule . ' }' );
    return $cls;
}

/* ---------------------------------------------------------------------------
 * The cases. Today is 2026-09-10 throughout.
 * ------------------------------------------------------------------------ */
function cases() {
    return array(
        // id => array( status, meta, expected reason ('' = publish it) )
        1  => array( 'draft',   array( '_uc_event_date' => '2026-09-11' ), '' ),
        2  => array( 'draft',   array( '_uc_event_date' => '2026-09-10' ), '' ),
        3  => array( 'draft',   array( '_uc_event_date' => '2026-09-09' ), 'already happened' ),
        4  => array( 'draft',   array( '_uc_event_date' => '' ),           'no date yet' ),
        5  => array( 'publish', array( '_uc_event_date' => '2026-09-11' ), 'not a draft' ),
        6  => array( 'pending', array( '_uc_event_date' => '2026-09-11' ), 'not a draft' ),
        7  => array( 'private', array( '_uc_event_date' => '2026-09-11' ), 'not a draft' ),
        8  => array( 'future',  array( '_uc_event_date' => '2026-09-11' ), 'not a draft' ),
        9  => array( 'draft',   array( '_uc_event_date' => '2026-09-11', '_uc_external_source' => 'gfmp' ), 'imported from a source' ),
        10 => array( 'draft',   array( '_uc_event_date' => '2026-09-11', '_uc_external_id' => 'abc123' ), 'imported from a source' ),
        11 => array( 'draft',   array( '_uc_event_date' => '2026-09-11', '_uc_source_removed_at' => '1788000000' ), 'gone at its source' ),
        12 => array( 'draft',   array( '_uc_event_date' => '2026-09-11', '_uc_submission_kind' => 'community' ), 'a submission' ),
        13 => array( 'draft',   array( '_uc_event_date' => '2026-09-11', '_uc_submission_kind' => 'staff' ), 'a submission' ),
        // What the import actually creates: a plain draft, dated, in a series,
        // carrying the notification opt-out the importer stamps. Eligible.
        14 => array( 'draft',   array( '_uc_event_date' => '2026-12-16', '_uc_notify_author_optout' => '1' ), '' ),
        // And one of its generated occurrences, which also carries a group.
        15 => array( 'draft',   array( '_uc_event_date' => '2026-11-18', '_uc_notify_author_optout' => '1', '_uc_recurrence_group' => 'rg_abc' ), '' ),
    );
}

function run_cases( $cls, $verbose ) {
    $fails = 0;
    foreach ( cases() as $id => $c ) {
        list( $status, $meta, $want ) = $c;
        $GLOBALS['posts'] = array( $id => array( 'status' => $status, 'meta' => $meta ) );
        $got = $cls::publish_skip_reason( $id, '2026-09-10' );
        $ok  = ( $got === $want );
        if ( $verbose ) {
            printf( "  %-8s %-28s %s\n", $status,
                ( '' === $want ) ? 'PUBLISH' : $want,
                $ok ? 'ok' : "FAIL got=" . var_export( $got, true ) );
        }
        if ( ! $ok ) { $fails++; }
    }
    return $fails;
}

/* ---------------------------------------------------------------------------
 * Self-test: hole the rule, require the cases to notice.
 * ------------------------------------------------------------------------ */
if ( $self ) {
    $plants = array(
        'the draft check dropped, so it publishes anything' =>
            array( "if ( 'draft' !== get_post_status( \$id ) ) {", "if ( false ) {" ),
        'the past check dropped, so it publishes old dates' =>
            array( "if ( \$date < (string) \$today ) {", "if ( false ) {" ),
        'the empty-date check dropped' =>
            array( "if ( '' === \$date ) {", "if ( false ) {" ),
        'the provenance check dropped, so it publishes imports' =>
            array( "if ( '' !== \$prov['source'] || '' !== \$prov['external_id'] ) {", "if ( false ) {" ),
        'the submission check dropped' =>
            array( "if ( '' !== (string) get_post_meta( \$id, SFAF_Submissions::META_KIND, true ) ) {", "if ( false ) {" ),
    );

    $missed = 0;
    $n = 0;
    foreach ( $plants as $label => $pair ) {
        $broken = str_replace( $pair[0], $pair[1], $rule );
        if ( $broken === $rule ) {
            printf( "%-54s PLANT DID NOT APPLY\n", $label );
            $missed++;
            continue;
        }
        $cls = load_rule( $broken, 'B' . ( ++$n ) );
        $f   = run_cases( $cls, false );
        printf( "%-54s %s\n", $label, $f ? 'caught' : 'MISSED' );
        if ( ! $f ) { $missed++; }
    }

    $clean = load_rule( $rule, 'Clean' );
    $f     = run_cases( $clean, false );
    printf( "%-54s %s\n", 'the real rule, unmodified', $f ? 'FALSE POSITIVE' : 'passes' );
    if ( $f ) { $missed++; }

    echo "\n" . ( $missed ? "FAILED: {$missed}\n" : "self-test passed: every planted hole caught, no false positive.\n" );
    exit( $missed ? 1 : 0 );
}

echo "Series bulk publish: what it will and will not touch\n";
echo str_repeat( '-', 62 ) . "\n";
$cls   = load_rule( $rule, 'Real' );
$fails = run_cases( $cls, true );
echo "\n" . ( $fails ? "{$fails} failure(s).\n" : "every case decided correctly.\n" );
exit( $fails ? 1 : 0 );
