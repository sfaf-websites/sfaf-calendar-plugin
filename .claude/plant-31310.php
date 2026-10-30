<?php
/**
 * THE 3.131.0 CHECKS, MADE TO FAIL ON PURPOSE.
 *
 *     php .claude/plant-31310.php [part of a plant's name]
 *
 * Plants, one at a time, a fault in the reminder's cancel link, and runs the
 * check that must catch it. Every file is put back from memory. Commit first,
 * and never run this while run-all.sh is running.
 */

$root = dirname( __DIR__ );
$X    = 'includes/class-sfaf-texts.php';
$T    = 'release-31310-test.php';

$plants = array(
    '{{5}} is the event page, not the cancel link' => array( $T, "FAIL  A: {{5}} is Beto's own cancel link", array(
        array( $X, "'5' => ! empty( \$v['cancel'] ) ? \$v['cancel'] : \$v['link'] )", "'5' => \$v['link'] )" ),
    ) ),
    'a registration with no token sends an empty {{5}}' => array( $T, 'FAIL  A: without a token, {{5}} is never empty', array(
        array( $X, "'5' => ! empty( \$v['cancel'] ) ? \$v['cancel'] : \$v['link'] )", "'5' => \$v['cancel'] )" ),
    ) ),
    'everyone gets the same link' => array( $T, "FAIL  A: {{5}} is Cruz's own, not Beto's", array(
        array( $X, "        \$token = isset( \$row->token ) ? trim( (string) \$row->token ) : '';", "        \$token = str_repeat( 'b', 32 );" ),
    ) ),
    'the text has no cancel line' => array( $T, 'FAIL  B: the English reminder ends with the cancel line', array(
        array( $X, "            if ( ! empty( \$v['cancel'] ) ) {\n                \$s .= \$es", "            if ( false ) {\n                \$s .= \$es" ),
    ) ),
    "the English line is not Mark's" => array( $T, 'FAIL  B: the English reminder ends with the cancel line', array(
        array( $X, "\" Can't make it? Cancel here: %s\"", "\" To cancel: %s\"" ),
    ) ),
    "the Spanish line is not Mark's" => array( $T, "FAIL  B: the Spanish reminder in Mark's words", array(
        array( $X, "' ¿No puedes venir? Cancela aquí: %s'", "' ¿No puede venir? Cancele aquí: %s'" ),
    ) ),
    'the update gets a fourth variable' => array( $T, 'FAIL  C: the update template keeps three variables', array(
        array( $X, ": array( '1' => \$v['name'], '2' => self::ended( \$v['message'] ), '3' => \$v['reply'] );", ": array( '1' => \$v['name'], '2' => self::ended( \$v['message'] ), '3' => \$v['reply'], '4' => \$v['cancel'] );" ),
    ) ),
);
$missed = 0;
$only = isset( $argv[1] ) ? $argv[1] : '';
foreach ( $plants as $name => $p ) {
    if ( '' !== $only && false === strpos( $name, $only ) ) { continue; }
    list( $check, $want, $edits ) = $p;
    $saved = array();
    foreach ( $edits as $e ) {
        list( $file, $from, $to ) = $e;
        $path = $root . '/' . $file;
        if ( ! isset( $saved[ $path ] ) ) { $saved[ $path ] = file_get_contents( $path ); }
        $now = file_get_contents( $path );
        if ( 1 !== substr_count( $now, $from ) ) {
            foreach ( $saved as $sp => $orig ) { file_put_contents( $sp, $orig ); }
            echo "ANCHOR NOT UNIQUE for \"$name\" in $file (" . substr_count( $now, $from ) . ")\n";
            exit( 1 );
        }
        file_put_contents( $path, str_replace( $from, $to, $now ) );
    }
    $out = shell_exec( 'cd ' . escapeshellarg( $root ) . ' && php ' . escapeshellarg( __DIR__ . '/' . $check ) . ' 2>&1' );
    foreach ( $saved as $sp => $orig ) { file_put_contents( $sp, $orig ); }
    $caught = ( false !== strpos( (string) $out, $want ) );
    echo ( $caught ? 'caught  ' : 'MISSED  ' ) . $name . "\n";
    if ( ! $caught ) { $missed++; echo '        ' . str_replace( "\n", "\n        ", trim( substr( (string) $out, -1500 ) ) ) . "\n"; }
}
echo $missed ? "$missed PLANT(S) MISSED\n" : "every plant caught.\n";
exit( $missed ? 1 : 0 );
