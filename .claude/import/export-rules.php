<?php
require __DIR__ . '/lib.php';
$want = array(
'Community Coffee Social (Elizabeth Taylor 50-Plus Network)','Dinner with Friends',
'Damn, Daddy! - Support Group (Online)',
'50-Plus Wednesday Night Virtual Check-in (Members Only) - Elizabeth Taylor 50-Plus Network',
'Transformaciones','Beyond the Binary','El Grupo de Apoyo Latino',
'Capacitación sobre cómo revertir una sobredosis y cómo responder ante ella',
'Grupos de Educación sobre Salud del Programa Latino','Grupo de Asistencia Legal del Programa Latino',
'TransLife Galaxy Mental Health Series','TransLife Drop-In','Soul Sessions',
'Express Yourself - Support Group','Off Mute - Support Group','Abstinence Support Group',
'El Salón - Grupo de apoyo','PROP Contingency Management','PROP for All - Contingency Management',
'PROP Empowerment Program',
);
$items = wxr_items( 'SFAF Calendar Export.xml' );
$by = array();
foreach ( $items as $it ) {
    if ( 'tribe_events' !== $it['type'] || 'publish' !== $it['status'] ) continue;
    if ( ! in_array( $it['title'], $want, true ) ) continue;
    $by[ $it['title'] ][] = $it;
}
foreach ( $want as $t ) {
    if ( ! isset( $by[$t] ) ) continue;
    $seen = array();
    foreach ( $by[$t] as $r ) {
        $b = m1( $r, '_EventRecurrence' );
        if ( '' === $b ) continue;
        $s = rec_summary( $b );
        $seen[ $s ][] = substr( m1($r,'_EventStartDate'), 0, 10 ) . ' ' . substr(m1($r,'_EventStartDate'),11,5) . '-' . substr(m1($r,'_EventEndDate'),11,5);
    }
    echo "### $t\n";
    if ( ! $seen ) { echo "    (no recurrence)\n\n"; continue; }
    foreach ( $seen as $s => $rows ) printf( "    %-95s  x%d  first=%s last=%s\n", $s, count($rows), $rows[0], $rows[count($rows)-1] );
    echo "\n";
}
