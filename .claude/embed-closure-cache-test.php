<?php
/**
 * SAVING A CLOSURE RETIRES THE EMBED'S CACHED PAGES (3.106.0).
 *
 *     php .claude/embed-closure-cache-test.php
 *
 * The fault Mark saw: a still-open row saved on a closure showed on the
 * shortcode calendar at once and on the sfaf.org embed only after its
 * ten-minute cache ran out, because a closure is an option and every flush
 * hook on the embed cache was for posts, terms or settings.
 *
 * Asserted through wp-kit.php with the real SFAF_Embed: the closure option's
 * add, update and delete hooks are registered to the flush, and running each
 * one as WordPress would moves the cache generation, which is what makes the
 * next request miss the stale page.
 */

require __DIR__ . '/wp-kit.php';

$fails = array();
function ec( $label, $got, $want ) {
    global $fails;
    if ( $got !== $want ) { $fails[] = $label . ': got ' . var_export( $got, true ) . ', wanted ' . var_export( $want, true ); }
}

$embed = new SFAF_Embed( new SFAF_Shortcodes() );
$GLOBALS['kit_hooks'] = array();
$embed->register();

$version = function () { return (int) get_option( SFAF_Embed::CACHE_VERSION_OPTION, 1 ); };

foreach ( array( 'add_option_', 'update_option_', 'delete_option_' ) as $prefix ) {
    $hook  = $prefix . SFAF_Closures::OPTION;
    $found = null;
    foreach ( isset( $GLOBALS['kit_hooks'][ $hook ] ) ? $GLOBALS['kit_hooks'][ $hook ] : array() as $h ) {
        if ( is_array( $h['cb'] ) && $h['cb'][0] === $embed && 'flush_cache' === $h['cb'][1] ) { $found = $h; }
    }
    ec( "PLANT: $hook flushes the embed cache", null !== $found, true );
    if ( $found ) {
        $before = $version();
        // WordPress passes the option name and values; the flush takes and ignores them.
        call_user_func( $found['cb'], SFAF_Closures::OPTION, array() );
        ec( "running $hook moves the cache generation", $version(), $before + 1 );
    }
}

if ( $fails ) {
    echo 'EMBED CLOSURE CACHE: ' . count( $fails ) . " FAILURE(S)\n  - " . implode( "\n  - ", $fails ) . "\n";
    exit( 1 );
}
echo "embed closure cache: adding, changing or removing a closure retires every cached embed page.\n";
