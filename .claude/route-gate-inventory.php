<?php
/**
 * EVERY ROUTE THAT TOUCHES AN EVENT OR ITS RSVPS, AND WHAT GATES IT.
 *
 * A reporting tool, not a pass/fail test. `.claude/route-gate-whitelist.php` is
 * the one that fails. This exists to produce the inventory a person reads when
 * deciding whether a gate is the RIGHT one, which no script can answer.
 *
 *     php .claude/route-gate-inventory.php            # the table
 *     php .claude/route-gate-inventory.php --json     # machine readable
 *
 * WHY IT IS SEPARATE FROM THE TEST. The whitelist test answers "does every
 * route go through the single gate", which is mechanical. This answers "what
 * does each route require", which is the question the five permission defects
 * on this project were all failures of. Four of the five were routes that HAD a
 * gate; it was the wrong one. A checker cannot see that, and a person reading a
 * table can.
 */

$root = dirname( __DIR__ );
$json = in_array( '--json', $argv, true );

$portal = file_get_contents( $root . '/includes/class-sfaf-portal.php' );
$rest   = '';
foreach ( glob( $root . '/includes/*.php' ) as $f ) {
    $rest .= file_get_contents( $f );
}

/* ---------------------------------------------------------------------------
 * The gate vocabulary.
 *
 * Ordered most permissive last, so a route carrying several is described by the
 * strongest thing it actually enforces.
 * ------------------------------------------------------------------------ */
$GATES = array(
    'is_admin_role'        => 'calendar admin',
    'can_view_all'         => 'admin or editor',
    'user_can_view_all'    => 'admin or editor',
    'can_edit_event'       => 'THE EVENT GATE',
    'user_can_edit_event'  => 'THE EVENT GATE',
    'can_create'           => 'any calendar user',
    'manage_options'       => 'WP administrator',
);

/**
 * Pull the body of one `case 'x':` arm out of the POST dispatcher.
 */
function case_body( $src, $action ) {
    $needle = "case '" . $action . "':";
    $at     = strpos( $src, $needle );
    if ( false === $at ) {
        return '';
    }
    // To the next `case '` at the same level, or the end of the switch.
    $next = strpos( $src, "\n            case '", $at + strlen( $needle ) );
    return substr( $src, $at, ( false === $next ? 4000 : $next - $at ) );
}

/** Which gates appear in a slice of code. */
function gates_in( $code, $GATES ) {
    $found = array();
    foreach ( array_keys( $GATES ) as $g ) {
        if ( preg_match( '#\b' . preg_quote( $g, '#' ) . '\s*\(#', $code ) ) {
            $found[] = $g;
        }
    }
    return $found;
}

/* ---------------------------------------------------------------------------
 * THE TWO DISPATCHERS ARE SLICED OUT FIRST, AND THAT IS THE WHOLE CORRECTNESS
 * ARGUMENT OF THIS FILE.
 *
 * The first cut matched every `case 'x':` in a 10,000 line file and reported 53
 * POST actions where there are 34. The extra 19 were arms of other switches:
 * the field renderers (image, description, organizer), the recurrence patterns
 * (daily, weekly, monthly) and the screen slugs, all of which look identical to
 * a regex and none of which is a route. An inventory that over-reports is not
 * the safe direction: it buries the real rows.
 *
 * So each dispatcher is cut to its own line range by finding the switch and
 * counting braces to its close, and only that slice is searched.
 * ------------------------------------------------------------------------ */

/**
 * The body of the switch statement starting at $from, brace-counted.
 *
 * Brace counting rather than a regex because these arms contain nested braces,
 * strings holding braces, and heredocs.
 */
function switch_body( $src, $from ) {
    $open = strpos( $src, '{', $from );
    if ( false === $open ) {
        return '';
    }
    $depth = 0;
    $len   = strlen( $src );
    for ( $i = $open; $i < $len; $i++ ) {
        if ( '{' === $src[ $i ] ) { $depth++; }
        if ( '}' === $src[ $i ] ) {
            $depth--;
            if ( 0 === $depth ) {
                return substr( $src, $open, $i - $open + 1 );
            }
        }
    }
    return '';
}

$post_at = strpos( $portal, 'private function dispatch_post(' );
$post_sw = strpos( $portal, 'switch ( $action ) {', $post_at );
$POST_SRC = switch_body( $portal, $post_sw );

$page_sw  = strpos( $portal, 'switch ( $page ) {' );
$PAGE_SRC = switch_body( $portal, $page_sw );

if ( '' === $POST_SRC || '' === $PAGE_SRC ) {
    fwrite( STDERR, "FAIL: a dispatcher could not be sliced. The routing moved and this inventory is blind.\n" );
    exit( 2 );
}

/* ---------------------------------------------------------------------------
 * 1. POST actions.
 * ------------------------------------------------------------------------ */
preg_match_all( "#\n            case '([a-z_]+)':#", $POST_SRC, $m );
$post_actions = array_values( array_unique( $m[1] ) );

if ( count( $post_actions ) < 20 ) {
    fwrite( STDERR, "FAIL: only " . count( $post_actions ) . " POST actions found. The dispatcher moved.\n" );
    exit( 2 );
}

$rows = array();

foreach ( $post_actions as $action ) {
    $body  = case_body( $POST_SRC, $action );
    $found = gates_in( $body, $GATES );

    // A case that delegates to a save_*_from_post() helper carries its gate
    // there. Follow one level, which is as deep as this codebase goes.
    if ( ! $found && preg_match( '#\$this->([a-z_]+)\s*\(#', $body, $call ) ) {
        if ( preg_match( '#function ' . $call[1] . '\s*\([^)]*\)\s*\{#', $portal, $fm, PREG_OFFSET_CAPTURE ) ) {
            $found = gates_in( substr( $portal, $fm[0][1], 3000 ), $GATES );
        }
    }

    $rows[] = array(
        'kind'   => 'POST',
        'route'  => 'uc_action=' . $action,
        'gates'  => $found,
        'nonce'  => true, // dispatch_post() verifies before the switch, always
    );
}

/* ---------------------------------------------------------------------------
 * 2. GET screens.
 * ------------------------------------------------------------------------ */
preg_match_all( "#case '([a-z-]+)':#", $PAGE_SRC, $sm );
$slugs = array_values( array_unique( $sm[1] ) );

foreach ( $slugs as $slug ) {
    $arm = case_body( $PAGE_SRC, $slug );
    // Which renderer this arm reaches. An arm may reach several (events/new,
    // events/edit); every one of them is reported.
    preg_match_all( '#\$this->(render_[a-z_]+)\s*\(#', $arm, $rm2 );
    $methods = array_values( array_unique( $rm2[1] ) );
    if ( ! $methods ) {
        $methods = array( '(no renderer)' );
    }

    foreach ( $methods as $method ) {
        $found = array();
        if ( '(no renderer)' !== $method
            && preg_match( '#function ' . $method . '\s*\([^)]*\)\s*\{#', $portal, $fm, PREG_OFFSET_CAPTURE ) ) {
            $found = gates_in( substr( $portal, $fm[0][1], 2500 ), $GATES );
        }
        $rows[] = array(
            'kind'  => 'GET',
            'route' => '/caladmin/' . $slug . '  ' . $method . '()',
            'gates' => $found,
            'nonce' => false,
        );
    }
}
/* ---------------------------------------------------------------------------
 * 3. REST routes, anywhere in the plugin.
 * ------------------------------------------------------------------------ */
preg_match_all(
    "#register_rest_route\(\s*([^,]+),\s*'([^']+)'.*?permission_callback'\s*=>\s*([^,\)]+)#s",
    $rest,
    $rm,
    PREG_SET_ORDER
);
foreach ( $rm as $r ) {
    $cb = trim( $r[3] );
    $rows[] = array(
        'kind'  => 'REST',
        'route' => trim( $r[2] ),
        'gates' => ( false !== strpos( $cb, '__return_true' ) ) ? array( 'PUBLIC' ) : array( $cb ),
        'nonce' => false,
    );
}

/* ---------------------------------------------------------------------------
 * 4. admin-ajax actions.
 * ------------------------------------------------------------------------ */
preg_match_all( "#add_action\(\s*'wp_ajax_(nopriv_)?([a-z_0-9]+)'#", $rest, $am, PREG_SET_ORDER );
$ajax = array();
foreach ( $am as $a ) {
    $ajax[ $a[2] ] = isset( $ajax[ $a[2] ] ) ? $ajax[ $a[2] ] : array();
    $ajax[ $a[2] ][] = $a[1] ? 'logged out too' : 'logged in';
}
foreach ( $ajax as $action => $who ) {
    $rows[] = array(
        'kind'  => 'AJAX',
        'route' => $action,
        'gates' => array( implode( ' + ', array_unique( $who ) ) ),
        'nonce' => false,
    );
}

/* ---------------------------------------------------------------------------
 * Report.
 * ------------------------------------------------------------------------ */
if ( $json ) {
    echo json_encode( $rows, JSON_PRETTY_PRINT ) . "\n";
    exit( 0 );
}

echo "ROUTE GATE INVENTORY\n";
echo str_repeat( '=', 78 ) . "\n\n";

$by_kind = array();
foreach ( $rows as $r ) {
    $by_kind[ $r['kind'] ][] = $r;
}

foreach ( $by_kind as $kind => $list ) {
    echo "$kind (" . count( $list ) . ")\n";
    echo str_repeat( '-', 78 ) . "\n";
    usort( $list, function ( $a, $b ) { return strcmp( $a['route'], $b['route'] ); } );
    foreach ( $list as $r ) {
        $gates = $r['gates'] ? implode( ', ', $r['gates'] ) : '(none found)';
        printf( "  %-34s %s\n", $r['route'], $gates );
    }
    echo "\n";
}

$ungated = array_filter( $rows, function ( $r ) {
    return ! $r['gates'] || in_array( 'PUBLIC', $r['gates'], true );
} );

echo "routes with no gate this tool could find: " . count( $ungated ) . "\n";
foreach ( $ungated as $r ) {
    echo "  {$r['kind']}  {$r['route']}\n";
}
echo "\nRead every one of those by hand. Some are correct: a screen that shows only\n";
echo "what the viewer may act on needs no gate of its own, and a POST that edits\n";
echo "nothing needs no event gate. The tool cannot tell those from a hole.\n";
