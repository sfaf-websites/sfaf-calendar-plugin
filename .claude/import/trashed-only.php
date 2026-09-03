<?php
/**
 * WHICH NAMES ON MARK'S LIST MATCH ONLY ROWS THAT ARE IN THE TRASH.
 *
 * The 3.69.0 report said five. That was wrong, and this is the check that
 * settles it rather than another eyeball over the same list.
 */
require __DIR__ . '/lib.php';
chdir( dirname( dirname( __DIR__ ) ) );

$items = wxr_items( 'SFAF Calendar Export.xml' );
$pub = array(); $trash = array(); $draft = array();
foreach ( $items as $it ) {
    if ( 'tribe_events' !== $it['type'] ) continue;
    $t = $it['title'];
    if ( 'publish' === $it['status'] ) { $pub[ $t ] = ( $pub[ $t ] ?? 0 ) + 1; }
    elseif ( 'trash' === $it['status'] ) { $trash[ $t ] = ( $trash[ $t ] ?? 0 ) + 1; }
    elseif ( 'draft' === $it['status'] ) { $draft[ $t ] = ( $draft[ $t ] ?? 0 ) + 1; }
}
echo "titles: published " . count( $pub ) . ", trashed " . count( $trash ) . ", draft " . count( $draft ) . "\n\n";
echo "TRASHED TITLES WITH NO PUBLISHED ROW OF THE SAME NAME\n";
foreach ( $trash as $t => $n ) {
    if ( isset( $pub[ $t ] ) ) continue;
    printf( "  x%-2d %s\n", $n, $t );
}
echo "\nDRAFT TITLES WITH NO PUBLISHED ROW OF THE SAME NAME\n";
foreach ( $draft as $t => $n ) {
    if ( isset( $pub[ $t ] ) ) continue;
    printf( "  x%-2d %s\n", $n, $t );
}
