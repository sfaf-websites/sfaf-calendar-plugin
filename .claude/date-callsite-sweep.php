<?php
/**
 * WHERE IS A DATE STILL BEING FORMATTED AT THE CALL SITE?
 *
 * DESIGN.md §3: one formatter, and never format a date at the call site. The
 * rule was set in 3.17.0 and two more call sites were found AFTER it, so it is
 * checked here rather than asserted. This walks every PHP file with the
 * tokenizer (grep gets the argument wrong the moment a format string contains a
 * brace or an escaped character) and reports every date_i18n(), date(),
 * gmdate(), ->format() and DateTime::format() with a literal format string.
 *
 * IT SPLITS THEM INTO TWO LISTS, AND ONLY ONE OF THEM IS A FAULT.
 *
 *   HUMAN   the format puts a month name, a weekday name or an ordinal in front
 *           of somebody. These must go through sfaf_ap_date() and friends.
 *   MACHINE Y-m-d keys, Y-m slugs, H:i meta, w weekday numbers, the ICS stamp.
 *           These are storage and protocol, they are compared and sorted, and
 *           putting them through a localised formatter would break them. They
 *           stay exactly as they are.
 *
 * The classifier is deliberately blunt: any format character that names a month
 * or a day in words, or an ordinal suffix, makes it human. That way a new call
 * site is a fault until somebody looks at it.
 *
 *     php .claude/date-callsite-sweep.php            # the repo
 *     php .claude/date-callsite-sweep.php --self-test # prove the checker works
 */

$root = dirname( __DIR__ );

/* The formatter itself, and this checker. A format string inside sfaf_ap_date()
   is the definition, not a call site. */
$allow_files = array(
    'includes/sfaf-template-functions.php' => array( 'sfaf_ap_date', 'sfaf_ap_datetime', 'sfaf_ap_time', 'sfaf_ap_time_range', 'sfaf_ap_date_range' ),
);

/* Format characters that put words or ordinals in front of a person: the two
   weekday NAMES, the two month NAMES, and the ordinal suffix. The numeric
   weekday characters are not here on purpose - 'N' and 'w' are 1-7 and 0-6, and
   the recurrence engine compares them. */
const HUMAN_CHARS = array( 'D', 'l', 'S', 'F', 'M' );

function is_human_format( $fmt ) {
    $escaped = false;
    $len     = strlen( $fmt );
    for ( $i = 0; $i < $len; $i++ ) {
        $c = $fmt[ $i ];
        if ( $escaped ) { $escaped = false; continue; }
        if ( '\\' === $c ) { $escaped = true; continue; }
        if ( in_array( $c, HUMAN_CHARS, true ) ) {
            return true;
        }
    }
    return false;
}

/**
 * Every date-formatting call in one file, with the literal format if there is
 * one. Tokenised, because "{$a}" and function &name() both defeat a regex.
 */
function scan_file( $path ) {
    $src    = file_get_contents( $path );
    $tokens = token_get_all( $src );
    $out    = array();

    $count = count( $tokens );
    for ( $i = 0; $i < $count; $i++ ) {
        $t = $tokens[ $i ];

        $name = '';
        if ( is_array( $t ) && T_STRING === $t[0] ) {
            $name = $t[1];
        }
        if ( '' === $name ) {
            continue;
        }
        $lname = strtolower( $name );
        /* get_the_date/get_the_time/mysql2date all take the format first as
           well, and get_the_date is how one of these hid from an earlier
           sweep that only looked for date_i18n. */
        if ( ! in_array( $lname, array( 'date_i18n', 'date', 'gmdate', 'format', 'wp_date',
                                        'get_the_date', 'get_the_time', 'mysql2date' ), true ) ) {
            continue;
        }

        /* The next non-whitespace token must be the opening bracket, or this is
           a mention rather than a call. */
        $j = $i + 1;
        while ( $j < $count && is_array( $tokens[ $j ] ) && T_WHITESPACE === $tokens[ $j ][0] ) { $j++; }
        if ( $j >= $count || '(' !== $tokens[ $j ] ) {
            continue;
        }

        /* `format` only counts as a date call when it is a method: ->format(
           or ::format(. A function called format() would be something else. */
        if ( 'format' === $lname ) {
            $k = $i - 1;
            while ( $k >= 0 && is_array( $tokens[ $k ] ) && T_WHITESPACE === $tokens[ $k ][0] ) { $k--; }
            $prev = $k >= 0 ? $tokens[ $k ] : null;
            $is_method = is_array( $prev ) && in_array( $prev[0], array( T_OBJECT_OPERATOR, T_DOUBLE_COLON ), true );
            if ( defined( 'T_NULLSAFE_OBJECT_OPERATOR' ) && is_array( $prev ) && T_NULLSAFE_OBJECT_OPERATOR === $prev[0] ) {
                $is_method = true;
            }
            if ( ! $is_method ) {
                continue;
            }
        }

        /* The first argument, if it is a plain string literal. */
        $k = $j + 1;
        while ( $k < $count && is_array( $tokens[ $k ] ) && T_WHITESPACE === $tokens[ $k ][0] ) { $k++; }
        $fmt = null;
        if ( $k < $count && is_array( $tokens[ $k ] ) && T_CONSTANT_ENCAPSED_STRING === $tokens[ $k ][0] ) {
            $raw = $tokens[ $k ][1];
            $q   = $raw[0];
            $fmt = substr( $raw, 1, -1 );
            if ( "'" === $q ) {
                $fmt = str_replace( array( "\\'", '\\\\' ), array( "'", '\\' ), $fmt );
            }
        }

        $line = is_array( $t ) ? $t[2] : 0;
        $out[] = array( 'fn' => $name, 'line' => $line, 'fmt' => $fmt );
    }

    return $out;
}

/** The enclosing function name for a line, so the formatter can exempt itself. */
function function_at_line( $path, $line ) {
    $tokens = token_get_all( file_get_contents( $path ) );
    $best   = '';
    foreach ( $tokens as $idx => $t ) {
        if ( ! is_array( $t ) || T_FUNCTION !== $t[0] || $t[2] > $line ) {
            continue;
        }
        /* Skip whitespace and the by-reference ampersand, which the tokenizer
           hands back as a bare string. */
        $j = $idx + 1;
        $n = count( $tokens );
        while ( $j < $n && ( ( is_array( $tokens[ $j ] ) && T_WHITESPACE === $tokens[ $j ][0] ) || '&' === $tokens[ $j ] ) ) { $j++; }
        if ( $j < $n && is_array( $tokens[ $j ] ) && T_STRING === $tokens[ $j ][0] ) {
            $best = $tokens[ $j ][1];
        }
    }
    return $best;
}

if ( in_array( '--self-test', $argv, true ) ) {
    /* MAKE IT FAIL ON PURPOSE FIRST. A checker that has never rejected anything
       has not been shown to check. */
    $cases = array(
        array( "<?php echo date_i18n( 'M j, Y', \$t );",       true,  'month name' ),
        array( "<?php echo date_i18n( 'jS', \$t );",            true,  'ordinal suffix' ),
        array( "<?php echo \$d->format( 'F Y' );",              true,  'method call, month name' ),
        array( "<?php echo date( 'Y-m-d', \$t );",              false, 'machine key' ),
        array( "<?php echo \$d->format( 'Y-m' );",              false, 'machine slug' ),
        array( "<?php echo date_i18n( 'w', \$t );",             false, 'weekday number' ),
        array( "<?php echo date_i18n( '\\\\M\\\\a\\\\y', \$t );", false, 'escaped letters are literal text' ),
        array( "<?php \$x = format( 'F j' );",                  false, 'not a method call' ),
        array( "<?php echo \"{\$a}\" . date_i18n( 'D', \$t );", true,  'curly interpolation before the call' ),
    );
    $fails = 0;
    foreach ( $cases as $n => $case ) {
        $tmp = tempnam( sys_get_temp_dir(), 'sweep' ) . '.php';
        file_put_contents( $tmp, $case[0] );
        $hits  = scan_file( $tmp );
        $human = false;
        foreach ( $hits as $h ) {
            if ( null !== $h['fmt'] && is_human_format( $h['fmt'] ) ) { $human = true; }
        }
        unlink( $tmp );
        $ok = ( $human === $case[1] );
        if ( ! $ok ) { $fails++; }
        printf( "%-6s case %d (%s): expected %s, got %s\n", $ok ? 'ok' : 'FAIL', $n + 1, $case[2],
            $case[1] ? 'human' : 'machine', $human ? 'human' : 'machine' );
    }
    echo $fails ? "SELF-TEST FAILED\n" : "self-test passes: the checker rejects what it should and passes what it should\n";
    exit( $fails ? 1 : 0 );
}

$files = array();
$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
foreach ( $it as $f ) {
    $p = str_replace( '\\', '/', $f->getPathname() );
    if ( substr( $p, -4 ) !== '.php' ) { continue; }
    if ( false !== strpos( $p, '/Old Calendar Files/' ) ) { continue; }
    /* .build-stage is a byte-identical COPY of the plugin that build-zip.sh
     * leaves behind. Scanning it counts every call site twice, and the second
     * copy sits at a path none of the exemptions above name, so the formatter
     * gets reported for being the formatter. Not source; skipped like the
     * archive above it. */
    if ( false !== strpos( $p, '/.build-stage/' ) ) { continue; }
    $files[] = $p;
}
sort( $files );

$human = array();
$machine = array();
$dynamic = array();

foreach ( $files as $path ) {
    $rel = ltrim( str_replace( str_replace( '\\', '/', $root ), '', $path ), '/' );
    foreach ( scan_file( $path ) as $hit ) {
        if ( null === $hit['fmt'] ) {
            $dynamic[] = sprintf( '%s:%d  %s( <not a literal> )', $rel, $hit['line'], $hit['fn'] );
            continue;
        }
        $row = sprintf( '%s:%d  %s( %s )', $rel, $hit['line'], $hit['fn'], var_export( $hit['fmt'], true ) );
        if ( ! is_human_format( $hit['fmt'] ) ) {
            $machine[] = $row;
            continue;
        }
        if ( isset( $allow_files[ $rel ] ) ) {
            $fn = function_at_line( $path, $hit['line'] );
            if ( in_array( $fn, $allow_files[ $rel ], true ) ) {
                continue; // this IS the formatter
            }
        }
        $human[] = $row;
    }
}

echo "HUMAN-FACING DATE CALL SITES OUTSIDE THE FORMATTER: " . count( $human ) . "\n";
foreach ( $human as $row ) { echo '  ' . $row . "\n"; }
echo "\nDynamic format arguments, read them by hand: " . count( $dynamic ) . "\n";
foreach ( $dynamic as $row ) { echo '  ' . $row . "\n"; }
echo "\nMachine formats, left alone on purpose: " . count( $machine ) . "\n";
foreach ( $machine as $row ) { echo '  ' . $row . "\n"; }

exit( count( $human ) ? 1 : 0 );
