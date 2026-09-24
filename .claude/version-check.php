<?php
/**
 * THE FOUR PLACES THE VERSION LIVES, AND WHETHER THEY AGREE (3.101.0).
 *
 *     php .claude/version-check.php <plugin root> <X.Y.Z>
 *
 * Exits 0 when every place says X.Y.Z, 1 naming each one that does not.
 * build-zip.sh runs it before anything is staged, so a disagreeing release is
 * never built.
 *
 * THE FOURTH PLACE IS embed.js. EMBED_JS_VERSION is how a stale cached script
 * says so (PROJECT.md 1), and it was bumped by hand with nothing checking it
 * until the embed filter test caught 3.100.2 without it. The build gate checked
 * three places and the release steps named three, so the fourth was found by a
 * test that happened to look, which is not a gate.
 */

/**
 * Every place, with what it says. '' when the pattern is not found, which is
 * a disagreement too: a place that cannot be read cannot be said to agree.
 *
 * @param string $root
 * @return array<string,string>
 */
function sfaf_version_places( $root ) {
    $read = function ( $file, $pattern ) use ( $root ) {
        $src = @file_get_contents( $root . '/' . $file );
        return ( false !== $src && preg_match( $pattern, $src, $m ) ) ? $m[1] : '';
    };
    return array(
        'plugin header (sfaf-calendar.php)'          => $read( 'sfaf-calendar.php', '/^ \* Version: (\S+)$/m' ),
        'SFAF_VERSION (sfaf-calendar.php)'           => $read( 'sfaf-calendar.php', "/^define\\( 'SFAF_VERSION', '([^']+)' \\);$/m" ),
        'readme Stable tag (readme.txt)'             => $read( 'readme.txt', '/^Stable tag: (\S+)$/m' ),
        'EMBED_JS_VERSION (public/js/embed.js)'      => $read( 'public/js/embed.js', "/EMBED_JS_VERSION = '([^']+)';/" ),
    );
}

if ( isset( $argv ) && realpath( $argv[0] ) === realpath( __FILE__ ) ) {
    $root    = isset( $argv[1] ) ? rtrim( $argv[1], '/\\' ) : '';
    $version = isset( $argv[2] ) ? $argv[2] : '';
    if ( '' === $root || '' === $version ) {
        fwrite( STDERR, "usage: php version-check.php <plugin root> <X.Y.Z>\n" );
        exit( 2 );
    }
    $bad = array();
    foreach ( sfaf_version_places( $root ) as $where => $says ) {
        if ( $says !== $version ) {
            $bad[] = sprintf( "FAIL: %s says '%s', you asked for '%s'", $where, $says, $version );
        }
    }
    if ( $bad ) {
        echo implode( "\n", $bad ) . "\n";
        echo "Nothing was built. Bump every place, or build the version they agree on.\n";
        exit( 1 );
    }
    echo "version $version agrees in all four places.\n";
    exit( 0 );
}
