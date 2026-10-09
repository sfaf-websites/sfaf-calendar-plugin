<?php
/**
 * THE 3.110.3 CHECKS, EACH MADE TO FAIL ON PURPOSE.
 *
 *     php .claude/plant-31103.php [part of a plant name]
 *
 * B a 1201-pixel-wide file accepted; C.2 a second email on a second save;
 * D a submitted picture reaching the picker before approval; E a bulk Set
 * team touching an event the person may not edit; and in the browser, B.JS
 * the editor's own check letting a 1201-wide file through, A the series
 * group no longer following the dropdown.
 *
 * Each replacement is written into the real file, the check is run, and every
 * file is put back from memory, not from git. An anchor that is not there, or
 * is there twice, stops the run. Never run this while run-all.sh is running.
 */

$root = dirname( __DIR__ );
$unit = 'pictures-31103-test.php';
$live = 'release-31103-live.php --run';

$plants = array(
    'B: a 1201-pixel-wide file accepted' => array( $unit, 'PLANT B', array(
        array( 'includes/class-sfaf-uploads.php', '&& self::PICTURE_WIDTH === (int) $w', '&& (int) $w >= self::PICTURE_WIDTH && (int) $w <= self::PICTURE_WIDTH + 1' ),
    ) ),
    'C.2: a second email on a second save' => array( $unit, 'PLANT C.2', array(
        array( 'includes/class-sfaf-media.php', "            update_post_meta( \$att, self::META_UPLOAD_SENT, time() );\n", '' ),
    ) ),
    'D: a submitted picture reaching the picker before approval' => array( $unit, 'PLANT D', array(
        array( 'includes/class-sfaf-media.php', "\$others = self::rows( self::pictures( array( 'place' => 'other', 'per_page' => \$limit ) )['ids'] );", "\$others = self::rows( self::pictures( array( 'place' => 'all', 'per_page' => \$limit ) )['ids'] );" ),
    ) ),
    'E: a bulk Set team touching an event the person may not edit' => array( $unit, 'PLANT E', array(
        array( 'includes/class-sfaf-portal.php', "            if ( ! \$this->may_assign_access( \$user, \$bulk_id ) ) {\n                \$refused++;", "            if ( false ) {\n                \$refused++;" ),
    ) ),
    'F.1: the old slug is not remembered' => array( 'slug-aliases-test.php', 'PLANT F.1', array(
        array( 'includes/class-sfaf-slug-aliases.php', "        \$all[ \$taxonomy ][ \$old ] = (int) \$term->term_id;\n", '' ),
    ) ),
    'F.2: the archive at the old slug is not redirected' => array( 'slug-aliases-test.php', 'PLANT F.2', array(
        array( 'includes/class-sfaf-slug-aliases.php', "        if ( is_404() ) {", "        if ( false ) {" ),
    ) ),
    'F.3: a filter link with the old slug is not redirected' => array( 'slug-aliases-test.php', 'PLANT F.3', array(
        array( 'includes/class-sfaf-slug-aliases.php', "        if ( \$changed ) {\n            \$url", "        if ( false ) {\n            \$url" ),
    ) ),
    'F.4: an embed with the old slug is not resolved' => array( 'slug-aliases-test.php', 'PLANT F.4', array(
        array( 'includes/class-sfaf-embed.php', "            'organizer'    => \$al( 'uc_organizer', 'organizer' ),", "            'organizer'    => (string) \$request->get_param( 'organizer' )," ),
    ) ),
    'B.JS: the editor lets a 1201-wide file through' => array( $live, 'PLANT B.JS', array(
        array( 'public/js/portal.js', 'var ok = (im.naturalWidth === w && im.naturalHeight === h);', 'var ok = (Math.abs(im.naturalWidth - w) <= 1 && im.naturalHeight === h);' ),
    ) ),
    'A: the series group does not follow the dropdown' => array( $live, 'PLANT A', array(
        array( 'public/js/portal.js', "            select.addEventListener('change', function () { apply(true); });", '' ),
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
            echo "ANCHOR NOT UNIQUE for \"$name\" in $file\n";
            exit( 1 );
        }
        file_put_contents( $path, str_replace( $from, $to, $now ) );
    }
    $out = shell_exec( 'php ' . escapeshellarg( __DIR__ . '/' . strtok( $check, ' ' ) ) . ( false !== strpos( $check, '--run' ) ? ' --run' : '' ) . ' 2>&1' );
    foreach ( $saved as $sp => $orig ) { file_put_contents( $sp, $orig ); }
    $caught = ( false !== strpos( (string) $out, $want ) );
    echo ( $caught ? 'caught  ' : 'MISSED  ' ) . $name . "\n";
    if ( ! $caught ) { $missed++; echo '        ' . str_replace( "\n", "\n        ", trim( (string) $out ) ) . "\n"; }
}
echo $missed ? "$missed PLANT(S) MISSED\n" : "every plant caught.\n";
exit( $missed ? 1 : 0 );
