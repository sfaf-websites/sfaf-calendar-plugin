<?php
/**
 * A SOURCE FILE THAT LOST MORE THAN HALF ITS LINES STOPS THE BUILD (3.105.0).
 *
 *     php .claude/shrink-check.php <repo root> [--allow-shrink=<path> ...]
 *
 * Exits 0 when nothing shipped has shrunk past half, 1 naming each file that
 * has. build-zip.sh runs it before anything is staged.
 *
 * WHY THIS EXISTS. The linter proves a file parses, and an EMPTY file parses.
 * The callable audit proves that what a file calls exists, and an empty file
 * calls nothing. So a class emptied by a bad write, a failed merge or an
 * editor crash passed both gates and would have shipped as a plugin missing a
 * class, which fails at the first request that asks for it, not at activation.
 *
 * TWO COMPARISONS, BECAUSE THIS REPOSITORY COMMITS BEFORE IT BUILDS.
 *
 *   the working tree against HEAD     a file emptied and not yet committed
 *   HEAD against the commit before it a file emptied and then committed
 *
 * Only the first would have been "since the last commit" read literally, and
 * it would pass every build made the way builds are made here: commit, then
 * build. The second closes that without reaching further back than one commit.
 *
 * THE OVERRIDE NAMES THE FILE. A deliberate cut is real (a class split in two,
 * a dead block removed), so it can be let through, but only by writing the
 * path on the command line: --allow-shrink=includes/class-sfaf-foo.php. There
 * is no switch that waives the check for everything, because that switch is
 * the one that gets left in a script.
 *
 * WHAT IS CHECKED: tracked text files that ship (the paths build-zip.sh copies)
 * with at least MIN_LINES lines in the older version. A deleted file counts as
 * zero lines, so deleting one needs the override too.
 */

const SFAF_SHRINK_MIN_LINES = 8;

/** The paths build-zip.sh copies into the zip. */
function sfaf_shrink_ships( $path ) {
    if ( ! preg_match( '/\.(php|js|css|txt)$/', $path ) ) {
        return false;
    }
    return in_array( $path, array( 'sfaf-calendar.php', 'readme.txt' ), true )
        || (bool) preg_match( '#^(admin|includes|public|templates)/#', $path );
}

function sfaf_shrink_git( $root, $args ) {
    $cmd = 'git -C ' . escapeshellarg( $root ) . ' ' . $args . ' 2>&1';
    exec( $cmd, $out, $rc );
    return array( $rc, $out );
}

/** Lines in a blob at a revision, or null when the path is not there. */
function sfaf_shrink_lines_at( $root, $rev, $path ) {
    list( $rc, $out ) = sfaf_shrink_git( $root, 'show ' . escapeshellarg( $rev . ':' . $path ) );
    return 0 === $rc ? count( $out ) : null;
}

function sfaf_shrink_lines_now( $root, $path ) {
    $file = $root . '/' . $path;
    if ( ! is_file( $file ) ) {
        return 0;
    }
    $src = (string) file_get_contents( $file );
    return '' === $src ? 0 : count( preg_split( "/\r\n|\n|\r/", rtrim( $src, "\r\n" ) ) );
}

/**
 * Every shipped file that shrank past half, in either comparison.
 *
 * @return array<string,string> path => "was N, now M (against X)"
 */
function sfaf_shrink_findings( $root, $allowed = array() ) {
    $found = array();

    list( $rc, $tracked ) = sfaf_shrink_git( $root, 'ls-tree -r --name-only HEAD' );
    if ( 0 !== $rc ) {
        return array( '(git)' => 'could not list HEAD: ' . implode( ' ', $tracked ) );
    }

    // HEAD against the working tree.
    foreach ( $tracked as $path ) {
        if ( ! sfaf_shrink_ships( $path ) || in_array( $path, $allowed, true ) ) {
            continue;
        }
        $was = sfaf_shrink_lines_at( $root, 'HEAD', $path );
        if ( null === $was || $was < SFAF_SHRINK_MIN_LINES ) {
            continue;
        }
        $now = sfaf_shrink_lines_now( $root, $path );
        if ( $now * 2 < $was ) {
            $found[ $path ] = "was $was lines at HEAD, is $now in the working tree";
        }
    }

    // The commit before HEAD against HEAD: a shrink that was committed.
    list( $rc ) = sfaf_shrink_git( $root, 'rev-parse --verify --quiet HEAD~1' );
    if ( 0 === $rc ) {
        list( , $before ) = sfaf_shrink_git( $root, 'ls-tree -r --name-only HEAD~1' );
        foreach ( $before as $path ) {
            if ( isset( $found[ $path ] ) || ! sfaf_shrink_ships( $path ) || in_array( $path, $allowed, true ) ) {
                continue;
            }
            $was = sfaf_shrink_lines_at( $root, 'HEAD~1', $path );
            if ( null === $was || $was < SFAF_SHRINK_MIN_LINES ) {
                continue;
            }
            $now = sfaf_shrink_lines_at( $root, 'HEAD', $path );
            $now = null === $now ? 0 : $now;
            if ( $now * 2 < $was ) {
                $found[ $path ] = "was $was lines before the last commit, is $now in it";
            }
        }
    }

    return $found;
}

if ( isset( $argv ) && realpath( $argv[0] ) === realpath( __FILE__ ) ) {
    $root    = isset( $argv[1] ) ? rtrim( $argv[1], '/\\' ) : '';
    $allowed = array();
    foreach ( array_slice( $argv, 2 ) as $arg ) {
        if ( 0 === strpos( $arg, '--allow-shrink=' ) ) {
            $allowed[] = str_replace( '\\', '/', substr( $arg, strlen( '--allow-shrink=' ) ) );
        } else {
            fwrite( STDERR, "unknown argument: $arg\n" );
            exit( 2 );
        }
    }
    if ( '' === $root || ! is_dir( $root ) ) {
        fwrite( STDERR, "usage: php shrink-check.php <repo root> [--allow-shrink=<path> ...]\n" );
        exit( 2 );
    }

    $found = sfaf_shrink_findings( $root, $allowed );
    if ( $found ) {
        echo "FAIL: a shipped file lost more than half its lines. Nothing was built.\n";
        foreach ( $found as $path => $why ) {
            echo "  $path: $why\n";
        }
        echo "If the cut is deliberate, name the file: --allow-shrink=<path>\n";
        exit( 1 );
    }
    echo 'shrink check: no shipped file lost more than half its lines'
        . ( $allowed ? ' (allowed: ' . implode( ', ', $allowed ) . ')' : '' ) . "\n";
    exit( 0 );
}
