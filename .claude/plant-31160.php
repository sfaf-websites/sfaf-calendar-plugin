<?php
/**
 * THE 3.116.0 CHECKS, EACH MADE TO FAIL ON PURPOSE.
 *
 *     php .claude/plant-31160.php [part of a plant name]
 *
 * One fault per plant, written into the real file; the check is run, and every
 * file is put back from memory, not from git. An anchor that is not there, or
 * is there twice, stops the run. Commit first, and never run this while
 * run-all.sh is running.
 */

$root = dirname( __DIR__ );

$plants = array(
    'A: Language back in the main column' => array( 'release-31160-test.php', "A: the side column's cards", array(
        array( 'includes/class-sfaf-portal.php', '                <section class="uc-bento-card" data-uc-card="language">', '            </div><div class="uc-bento-main-x">' . "\n" . '                <section class="uc-bento-card" data-uc-card="language">' ),
    ) ),
    'B: the save turns yellow' => array( 'release-31160-test.php', 'B: Save changes is green', array(
        array( 'includes/class-sfaf-portal.php', '<button type="submit" form="uc-series-form" class="uc-btn uc-btn-go uc-editor-save-main uc-editor-save">', '<button type="submit" form="uc-series-form" class="uc-btn uc-btn-primary uc-editor-save">' ),
    ) ),
    'B: Remove series a solid button again' => array( 'release-31160-test.php', 'B: Remove series is a red link in the bar', array(
        array( 'includes/class-sfaf-portal.php', '<a class="uc-link-danger uc-editor-delete" href="<?php echo esc_url( $this->url( \'series/remove/\' . (int) $term_id ) ); ?>">Remove series</a>', '<a class="uc-btn uc-btn-danger" href="<?php echo esc_url( $this->url( \'series/remove/\' . (int) $term_id ) ); ?>">Remove series</a>' ),
    ) ),
    'C: no leave warning' => array( 'release-31160-test.php', 'C: the leave warning is on the form', array(
        array( 'includes/class-sfaf-portal.php', 'class="uc-form" enctype="multipart/form-data" data-uc-leave-warn>' . "\n" . '            <input type="hidden" name="uc_action" value="save_series" />', 'class="uc-form" enctype="multipart/form-data">' . "\n" . '            <input type="hidden" name="uc_action" value="save_series" />' ),
    ) ),
    'D: "Default" is back' => array( 'release-31160-test.php', 'D: not "Default categories"', array(
        array( 'includes/class-sfaf-portal.php', 'id="uc-series-default-category">Categories</span>', 'id="uc-series-default-category">Default categories</span>' ),
    ) ),
    'L: Remove series beside Save' => array( 'release-31160-live.php', 'Remove series is not at the bar', array(
        array( 'public/css/portal.css', '.uc-editor-actions[data-uc-series-bar] .uc-editor-delete { margin-right: auto; }', '.uc-editor-actions[data-uc-series-bar] .uc-editor-delete { }' ),
    ) ),
    'L: the bar is not sticky' => array( 'release-31160-live.php', 'the bar is not sticky', array(
        array( 'includes/class-sfaf-portal.php', '        <div class="uc-form-actions uc-form-actions-primary uc-editor-actions uc-editor-bar" data-uc-card="actions" data-uc-editor-bar data-uc-series-bar>' . "\n" . '            <?php if ( $term ) : ?>', '        <div class="uc-form-actions uc-form-actions-primary uc-editor-actions" data-uc-card="actions" data-uc-editor-bar data-uc-series-bar>' . "\n" . '            <?php if ( $term ) : ?>' ),
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
    $args = ( false !== strpos( $check, '-live' ) ) ? ' --run' : ' --verbose';
    $out = shell_exec( 'cd ' . escapeshellarg( $root ) . ' && php ' . escapeshellarg( __DIR__ . '/' . $check ) . $args . ' 2>&1' );
    foreach ( $saved as $sp => $orig ) { file_put_contents( $sp, $orig ); }
    $caught = ( false !== strpos( (string) $out, $want ) );
    echo ( $caught ? 'caught  ' : 'MISSED  ' ) . $name . "\n";
    if ( ! $caught ) { $missed++; echo '        ' . str_replace( "\n", "\n        ", trim( substr( (string) $out, -1500 ) ) ) . "\n"; }
}
echo $missed ? "$missed PLANT(S) MISSED\n" : "every plant caught.\n";
exit( $missed ? 1 : 0 );
