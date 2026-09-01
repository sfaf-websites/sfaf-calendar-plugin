<?php
/**
 * Control inventory for caladmin.
 *
 * Reads the portal renderer and lists every interactive element it emits,
 * grouped by the class it carries. The point is to find the same KIND of
 * control wearing two different classes, which is the divergence the control
 * standard is meant to close.
 *
 * Usage: php .claude/control-inventory.php [file ...]
 */

$files = array_slice( $argv, 1 );
if ( ! $files ) {
    $files = array( __DIR__ . '/../includes/class-sfaf-portal.php' );
}

$found = array();   // tag => class-signature => count
$where = array();   // tag|class => list of line numbers

foreach ( $files as $file ) {
    $src   = file_get_contents( $file );
    $lines = explode( "\n", $src );

    foreach ( $lines as $n => $line ) {
        if ( ! preg_match_all( '/<(button|input|select|textarea|a|label|fieldset|details|summary)\b([^>]*)/i', $line, $m, PREG_SET_ORDER ) ) {
            continue;
        }
        foreach ( $m as $hit ) {
            $tag  = strtolower( $hit[1] );
            $attr = $hit[2];

            $type = '';
            if ( preg_match( '/type=[\'"]?([a-z\-]+)/i', $attr, $t ) ) {
                $type = strtolower( $t[1] );
            }

            $class = '';
            if ( preg_match( '/class=[\'"]([^\'"]*)/i', $attr, $c ) ) {
                $class = trim( $c[1] );
            }

            // Drop PHP interpolation so classes group.
            $class = preg_replace( '/\$\{?[A-Za-z_\[\]\'\>\-\(\)0-9]+\}?/', '{php}', $class );
            $class = preg_replace( '/\s+/', ' ', $class );

            $key = $tag . ( $type ? "[$type]" : '' );
            $sig = $class === '' ? '(no class)' : $class;

            if ( ! isset( $found[ $key ][ $sig ] ) ) {
                $found[ $key ][ $sig ] = 0;
                $where[ $key ][ $sig ] = array();
            }
            $found[ $key ][ $sig ]++;
            if ( count( $where[ $key ][ $sig ] ) < 6 ) {
                $where[ $key ][ $sig ][] = basename( $file ) . ':' . ( $n + 1 );
            }
        }
    }
}

ksort( $found );
foreach ( $found as $key => $sigs ) {
    arsort( $sigs );
    $total = array_sum( $sigs );
    echo "\n=== $key  ($total) ===\n";
    foreach ( $sigs as $sig => $count ) {
        printf( "  %4d  %s\n", $count, $sig );
        echo "        " . implode( ', ', $where[ $key ][ $sig ] ) . "\n";
    }
}
