<?php
/**
 * 3.128.0: THE EVENTS SCREEN'S DATE RANGE IS ONE QUERY BY KEY, AND THE EVENT
 * PAGE'S "UPCOMING IN THIS SERIES" CARRIES THE DAY OF THE WEEK.
 *
 *     php .claude/release-31280-test.php
 *
 * A. SFAF_Portal::range_event_ids() runs on a postmeta modelled in SQLite,
 *    with WordPress's two keys per event and the awkward rows: no end date,
 *    an empty start, an end before the start, a multi-day event, another
 *    post type's meta. For 400 random ranges, each with no tab, Upcoming and
 *    Archived, its answer is the 3.125.0 rule worked out here in PHP the way
 *    MySQL's CAST(... AS DATE) reads the values:
 *      first day >= from, or last day >= from; first day <= to;
 *      Upcoming: first day >= today; Archived: first day < today.
 * B. query_events() with a range sends no date clause in meta_query and
 *    hands WP_Query those ids, crossed with a person's own; none is
 *    array( 0 ). Without a range the tabs' clauses are as before.
 * C. Its SQL reads postmeta by key only: no join on post_id, no CAST.
 * D. The event page's list, through wp-kit: "Wed, Oct 28", a Spanish
 *    event's "mié., 28 oct.".
 *
 * The before and after times are measured on MySQL 8.4 and reported in the
 * changelog; a timing does not belong in a check that runs on any machine.
 *
 * PLANTs in plant-31280.php.
 */

// SQLite ships with PHP here as an extension that is not loaded by default.
if ( ! class_exists( 'PDO' ) || ! in_array( 'sqlite', PDO::getAvailableDrivers(), true ) ) {
    if ( getenv( 'RT31280_CHILD' ) ) { echo "release-31280: no SQLite driver\n"; exit( 1 ); }
    putenv( 'RT31280_CHILD=1' );
    $cmd = escapeshellarg( PHP_BINARY ) . ' -d extension_dir=' . escapeshellarg( dirname( PHP_BINARY ) . DIRECTORY_SEPARATOR . 'ext' )
        . ' -d extension=pdo_sqlite ' . escapeshellarg( __FILE__ );
    passthru( $cmd, $code );
    exit( $code );
}

$GLOBALS['loop_done'] = false;
require __DIR__ . '/wp-kit.php';
foreach ( array( '_n' => 'return $a[2]==1?$a[0]:$a[1];' ) as $f => $b ) { if ( ! function_exists( $f ) ) { eval( "function $f( ...\$a ) { $b }" ); } }

$fails = array();
$ran   = 0;
function rt( $label, $ok ) {
    global $fails, $ran;
    $ran++;
    if ( ! $ok ) { $fails[] = $label; }
}

/* ---- the modelled postmeta ------------------------------------------- */
$pdo = new PDO( 'sqlite::memory:' );
$pdo->exec( 'CREATE TABLE wp_postmeta (meta_id INTEGER PRIMARY KEY, post_id INT, meta_key TEXT, meta_value TEXT)' );
$ins  = $pdo->prepare( 'INSERT INTO wp_postmeta (post_id, meta_key, meta_value) VALUES (?,?,?)' );
$rows = array(); // post_id => array( start|null, end|null ), null = no row
mt_srand( 31280 );
$day = function ( $lo = 0, $hi = 3285 ) { return date( 'Y-m-d', mktime( 12, 0, 0, 1, 1, 2019 ) + mt_rand( $lo, $hi ) * 86400 ); };
for ( $id = 1; $id <= 600; $id++ ) {
    $start = $day();
    $end   = null;
    $kind  = $id % 12;
    if ( 0 === $kind ) { $start = ''; }                                          // an empty first day
    if ( 1 === $kind || 2 === $kind ) { $end = date( 'Y-m-d', strtotime( $start . ' +' . mt_rand( 1, 9 ) . ' days' ) ); } // multi-day
    if ( 3 === $kind ) { $end = ''; }                                            // an empty end row
    if ( 4 === $kind ) { $end = date( 'Y-m-d', strtotime( $start . ' -3 days' ) ); } // end before start
    if ( 5 === $kind ) { $start = null; $end = $day(); }                         // no first-day row at all
    if ( null !== $start ) { $ins->execute( array( $id, '_uc_event_date', $start ) ); }
    if ( null !== $end ) { $ins->execute( array( $id, '_uc_end_date', $end ) ); }
    $ins->execute( array( $id, '_uc_start_time', '18:00' ) );
    $rows[ $id ] = array( $start, $end );
}
// Another post type's dates under other keys, which must never be read.
for ( $id = 601; $id <= 700; $id++ ) { $ins->execute( array( $id, '_event_date', $day() ) ); $ins->execute( array( $id, 'end_date', $day() ) ); }
// Around today, so Upcoming and Archived both have a boundary to get wrong.
$today = current_time( 'Y-m-d' );
foreach ( array( 701 => '-1 day', 702 => '+0 days', 703 => '+1 day' ) as $id => $rel ) {
    $d = date( 'Y-m-d', strtotime( $today . ' ' . $rel ) );
    $ins->execute( array( $id, '_uc_event_date', $d ) );
    $rows[ $id ] = array( $d, null );
}
$ins->execute( array( 704, '_uc_event_date', date( 'Y-m-d', strtotime( $today . ' -2 days' ) ) ) );
$ins->execute( array( 704, '_uc_end_date', date( 'Y-m-d', strtotime( $today . ' +2 days' ) ) ) );
$rows[704] = array( date( 'Y-m-d', strtotime( $today . ' -2 days' ) ), date( 'Y-m-d', strtotime( $today . ' +2 days' ) ) );

$GLOBALS['rt_sql'] = array();
$GLOBALS['rt_pdo'] = $pdo;
$GLOBALS['wpdb'] = new class extends wpdb {
    function get_col( $q ) {
        $GLOBALS['rt_sql'][] = (string) $q;
        $st = $GLOBALS['rt_pdo']->query( (string) $q );
        if ( ! $st ) { $GLOBALS['rt_sql_error'] = implode( ' ', $GLOBALS['rt_pdo']->errorInfo() ); return array(); }
        return $st->fetchAll( PDO::FETCH_COLUMN );
    }
};

/* The 3.125.0 rule, the way MySQL reads it: a value that is not a date is NULL. */
function rt_cast( $v ) { return ( null !== $v && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ) ) ? $v : null; }
function rt_want( $rows, $from, $to, $view, $today ) {
    $out = array();
    foreach ( $rows as $id => $r ) {
        list( $s, $e ) = $r;
        if ( null === $s ) { continue; }                 // the orderby join needs the row
        $cs = rt_cast( $s ); $ce = rt_cast( $e );
        if ( '' !== $from && ! ( ( null !== $cs && $cs >= $from ) || ( null !== $ce && $ce >= $from ) ) ) { continue; }
        if ( '' !== $to && ! ( null !== $cs && $cs <= $to ) ) { continue; }
        if ( 'upcoming' === $view && ! ( null !== $cs && $cs >= $today ) ) { continue; }
        if ( 'archived' === $view && ! ( null !== $cs && $cs < $today ) ) { continue; }
        $out[] = (int) $id;
    }
    sort( $out );
    return $out;
}

/* ---- A: the rule, 400 ranges by three tabs --------------------------- */
$bad = array(); $checked = 0; $multi_seen = false;
for ( $i = 0; $i < 400; $i++ ) {
    $from = mt_rand( 0, 4 ) ? $day( -30, 3300 ) : '';
    $to   = mt_rand( 0, 4 ) ? $day( -30, 3300 ) : '';
    if ( 0 === $i ) { $from = date( 'Y-m-d', strtotime( $today . ' -1 day' ) ); $to = date( 'Y-m-d', strtotime( $today . ' +1 day' ) ); }
    if ( '' === $from && '' === $to ) { $from = $day(); }
    foreach ( array( '', 'upcoming', 'archived' ) as $view ) {
        $got = SFAF_Portal::range_event_ids( $from, $to, $view );
        sort( $got );
        $want = rt_want( $rows, $from, $to, $view, $today );
        $checked++;
        if ( $got !== $want ) {
            $bad[] = "$view [$from, $to]: " . count( $got ) . ' found, the rule says ' . count( $want )
                . ' (extra ' . json_encode( array_slice( array_values( array_diff( $got, $want ) ), 0, 5 ) )
                . ', missing ' . json_encode( array_slice( array_values( array_diff( $want, $got ) ), 0, 5 ) ) . ')';
        }
        foreach ( $want as $id ) { if ( $rows[ $id ][1] && rt_cast( $rows[ $id ][0] ) < $from ) { $multi_seen = true; } }
    }
}
rt( 'PLANT A: the range does not follow the rule: ' . implode( '; ', array_slice( $bad, 0, 3 ) ) . ( isset( $GLOBALS['rt_sql_error'] ) ? ' SQL error: ' . $GLOBALS['rt_sql_error'] : '' ), ! $bad );
rt( "A: only $checked ranges were compared", 1200 === $checked );
rt( 'A: no range found a multi-day event by its later days, so the end date went untested', $multi_seen );
rt( 'A: a malformed edge is not ignored', SFAF_Portal::range_event_ids( "2026-01-01' OR 1=1 --", '', '' ) === SFAF_Portal::range_event_ids( '', '', '' ) );

/* ---- C: by key, never by event --------------------------------------- */
$GLOBALS['rt_sql'] = array();
SFAF_Portal::range_event_ids( '2026-10-01', '2026-10-31', 'upcoming' );
$sql = (string) end( $GLOBALS['rt_sql'] );
rt( 'PLANT C: the range joins postmeta to itself by event: ' . $sql, ! preg_match( '/\bJOIN\b/i', $sql ) && false === strpos( $sql, 'post_id = ' ) );
rt( 'C: the range compares through CAST: ' . $sql, false === stripos( $sql, 'CAST(' ) );
rt( 'C: the range reads more than one query', 1 === count( $GLOBALS['rt_sql'] ) );

/* ---- B: query_events() ----------------------------------------------- */
$GLOBALS['rt_q'] = array();
$GLOBALS['kit_query'] = function ( $a ) { $GLOBALS['rt_q'][] = $a; return array(); };
$portal = ( new ReflectionClass( 'SFAF_Portal' ) )->newInstanceWithoutConstructor();
$admin  = new WP_User();
$q_for  = function ( $args ) use ( $portal, $admin ) {
    $GLOBALS['rt_q'] = array();
    kit_call( 'SFAF_Portal', 'query_events', $portal, array( $admin, $args ) );
    return (array) end( $GLOBALS['rt_q'] );
};
$date_clauses = function ( $mq ) {
    $n = 0;
    array_walk_recursive( $mq, function ( $v, $k ) use ( &$n ) { if ( 'key' === $k && in_array( $v, array( '_uc_event_date', '_uc_end_date' ), true ) ) { $n++; } } );
    return $n;
};
$range = array( 'from' => '2026-10-01', 'to' => '2026-10-31' );
foreach ( array( 'All' => array(), 'Upcoming' => array( 'upcoming' => true ), 'Archived' => array( 'archived' => true ) ) as $tab => $extra ) {
    $q = $q_for( array_merge( $range, $extra, array( 'scope' => 'all', 'paged' => 1, 'per_page' => 25 ) ) );
    $want = SFAF_Portal::range_event_ids( '2026-10-01', '2026-10-31', isset( $extra['upcoming'] ) ? 'upcoming' : ( isset( $extra['archived'] ) ? 'archived' : '' ) );
    rt( "PLANT B: $tab with a range still puts " . $date_clauses( $q['meta_query'] ) . ' date clauses in meta_query', 0 === $date_clauses( $q['meta_query'] ) );
    $in = isset( $q['post__in'] ) ? array_map( 'intval', $q['post__in'] ) : null;
    sort( $want );
    if ( is_array( $in ) ) { sort( $in ); }
    rt( "PLANT B: $tab with a range hands WP_Query " . json_encode( null === $in ? null : count( $in ) ) . ' ids, not the ' . count( $want ) . ' in range', ( $want ? $want : array( 0 ) ) === $in );
}
rt( 'B: the date still orders the list', '_uc_event_date' === ( $q['meta_key'] ?? '' ) );
$q = $q_for( array( 'from' => '1999-01-01', 'to' => '1999-01-02', 'scope' => 'all' ) );
rt( 'B: a range with nothing in it is not array( 0 ): ' . json_encode( $q['post__in'] ?? null ), array( 0 ) === ( $q['post__in'] ?? null ) );
$q = $q_for( array( 'upcoming' => true, 'scope' => 'all' ) );
rt( 'B: Upcoming without a range lost its clause', 1 === $date_clauses( $q['meta_query'] ) && ! isset( $q['post__in'] ) );
$q = $q_for( array( 'archived' => true, 'scope' => 'all' ) );
rt( 'B: Archived without a range lost its clause', 1 === $date_clauses( $q['meta_query'] ) );

// A person's own events are crossed with the range, read from the source.
$src    = file_get_contents( $GLOBALS['kit_root'] . '/includes/class-sfaf-portal.php' );
$body   = substr( $src, strpos( $src, '    private function query_events(' ) );
$body   = substr( $body, 0, strpos( $body, "\n    }\n" ) );
rt( 'PLANT B: a person\'s own events are not crossed with the range', false !== strpos( $body, "array_intersect( \$in_range, array_map( 'intval', \$q['post__in'] ) )" )
    && strpos( $body, "\$q['post__in']   = \$mine_ids" ) < strpos( $body, 'array_intersect( $in_range' ) );

/* ---- D: the event page's list ---------------------------------------- */
$ev = array();
foreach ( array( 'en' => '+3 days', 'es' => '+4 days', 'me' => '+5 days' ) as $lang => $rel ) {
    $d  = date( 'Y-m-d', strtotime( $today . ' ' . $rel ) );
    $id = kit_write_post( array( 'post_type' => 'uc_event', 'post_status' => 'publish', 'post_title' => 'Date ' . $d, 'post_name' => 'd' . $lang ) );
    update_post_meta( $id, '_uc_event_date', $d );
    update_post_meta( $id, '_uc_language', 'es' === $lang ? 'es' : 'en' );
    $GLOBALS['kit_terms'][ $id ]['uc_series'] = array( 11 );
    $ev[ $lang ] = array( $id, $d );
}
$GLOBALS['kit_query'] = function ( $a ) use ( $ev ) { $o = array(); foreach ( $ev as $e ) { $o[] = $e[0]; } return $o; };
$GLOBALS['kit_get_posts'] = $GLOBALS['kit_query'];
$html = sfaf_series_list_html( $ev['me'][0] );
function rt_want_date( $d, $lang ) {
    $t = strtotime( $d . ' 12:00:00' );
    if ( 'es' !== $lang ) { return date( 'D, M j', $t ); }
    $dias = array( 'dom.', 'lun.', 'mar.', 'mié.', 'jue.', 'vie.', 'sáb.' );
    $mes  = array( 1 => 'ene.', 'feb.', 'mar.', 'abr.', 'may.', 'jun.', 'jul.', 'ago.', 'sep.', 'oct.', 'nov.', 'dic.' );
    return $dias[ (int) date( 'w', $t ) ] . ', ' . date( 'j', $t ) . ' ' . $mes[ (int) date( 'n', $t ) ];
}
preg_match_all( '#<span class="uc-series-date">([^<]*)</span>\s*<span class="uc-series-title">Date (\d{4}-\d{2}-\d{2})</span>#', $html, $m, PREG_SET_ORDER );
rt( 'D: the list shows ' . count( $m ) . ' dates, not the other 2', 2 === count( $m ) );
foreach ( $m as $row ) {
    $lang = ( $row[2] === $ev['es'][1] ) ? 'es' : 'en';
    $got  = html_entity_decode( $row[1], ENT_QUOTES, 'UTF-8' );
    rt( "PLANT D: the $lang event on {$row[2]} reads " . json_encode( $got ) . ', not ' . json_encode( rt_want_date( $row[2], $lang ) ), rt_want_date( $row[2], $lang ) === $got );
}
rt( 'D: the heading changed', false !== strpos( $html, '>Upcoming in this series<' ) );

echo "\n3.128.0: the Events date range by key, and the event page's series weekdays\n" . str_repeat( '=', 72 ) . "\n";
if ( $fails ) { echo count( $fails ) . " of $ran failed:\n  - " . implode( "\n  - ", $fails ) . "\n"; exit( 1 ); }
echo "$ran assertions passed. $checked ranges match the 3.125.0 rule on a modelled postmeta, read by key in one query; query_events() hands\nWP_Query the ids and no date clause; the event page's series list reads \"Wed, Oct 28\", a Spanish event's in Spanish.\n";
exit( 0 );
