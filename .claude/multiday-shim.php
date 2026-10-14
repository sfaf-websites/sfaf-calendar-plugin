<?php
/**
 * THE MULTI-DAY HELPERS FOR A HARNESS THAT STUBS THE DATE FORMATTER (3.112.0).
 *
 *     require __DIR__ . '/multiday-shim.php';   // after the harness's sfaf_ap_date()
 *
 * sfaf_event_last_date(), sfaf_event_days(), sfaf_ap_range() and
 * sfaf_ap_event_date() are lifted out of the shipped template file and run as
 * they are, over whatever sfaf_ap_date() the harness defines, so a harness
 * that builds an email or a card reads the date the way the plugin does.
 * A function the harness already has is left alone.
 */

$md_src = file_get_contents( dirname( __DIR__ ) . '/includes/sfaf-template-functions.php' );
foreach ( array( 'sfaf_event_last_date', 'sfaf_event_days', 'sfaf_ap_range', 'sfaf_ap_event_date' ) as $md_fn ) {
    if ( function_exists( $md_fn ) ) {
        continue;
    }
    $md_at = strpos( $md_src, 'function ' . $md_fn . '(' );
    if ( false === $md_at ) {
        echo "FAIL: $md_fn() is not in the template file.\n";
        exit( 1 );
    }
    $md_depth = 0;
    for ( $md_i = strpos( $md_src, '{', $md_at ); $md_i < strlen( $md_src ); $md_i++ ) {
        if ( '{' === $md_src[ $md_i ] ) {
            $md_depth++;
        } elseif ( '}' === $md_src[ $md_i ] ) {
            $md_depth--;
            if ( 0 === $md_depth ) {
                eval( substr( $md_src, $md_at, $md_i - $md_at + 1 ) );
                break;
            }
        }
    }
}
unset( $md_src, $md_fn, $md_at, $md_depth, $md_i );
