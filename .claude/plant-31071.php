<?php
/**
 * THE 3.107.1 CHECKS, EACH MADE TO FAIL ON PURPOSE.
 *
 *     php .claude/plant-31071.php [part of a plant name]
 *
 * The series card is the first card of the main column on Add and Edit, as wide
 * as that column, 16px above Title, and its thumbnail is 56x32. Each plant moves
 * it somewhere else or changes one of those, and the check must say so.
 *
 * A plant is one or more replacements, each written into the real file; the
 * check is run and every file is put back from memory, not from git, so an
 * uncommitted change cannot be lost. A replacement whose anchor is not there,
 * or is there twice, stops the run: a plant that did not apply proves nothing.
 * Never run this while run-all.sh is running.
 */

$root = dirname( __DIR__ );
$live = 'release-3107-live.php --run';
$php  = 'includes/class-sfaf-portal.php';
$css  = 'public/css/portal.css';

$in_main = "                <?php // ---- Series and language: first, above Title (3.107.1). ?>\n"
    . "                <?php \$this->render_series_prefill( \$all_series, \$cur_series, (int) \$event_id ); ?>\n";
$call    = "<?php \$this->render_series_prefill( \$all_series, \$cur_series, (int) \$event_id ); ?>\n";

$plants = array(
    'A.4: back at the top of the side column' => array( $live, 'PLANT A.4', array(
        array( $php, $in_main, '' ),
        array( $php, "            ob_start();\n            ?>\n                <?php\n                // ---- Links", "            ob_start();\n            ?>\n                " . $call . "                <?php\n                // ---- Links" ),
    ) ),
    'A.4: back in the side column, the same, read by the render test' => array( 'series-control-test.php', 'first card of the main column', array(
        array( $php, $in_main, '' ),
        array( $php, "            ob_start();\n            ?>\n                <?php\n                // ---- Links", "            ob_start();\n            ?>\n                " . $call . "                <?php\n                // ---- Links" ),
    ) ),
    'A.4: under Title' => array( $live, 'PLANT A.4', array(
        array( $php, $in_main, '' ),
        array( $php, "                <?php // ---- Schedule: when, and where its other dates live. - ?>", "                " . $call . "                <?php // ---- Schedule: when, and where its other dates live. - ?>" ),
    ) ),
    'A.4: last in the main column' => array( $live, 'PLANT A.4', array(
        array( $php, $in_main, '' ),
        array( $php, "            <div class=\"uc-bento-side\"><?php echo \$side_html; ?></div>", "            " . $call . "            <div class=\"uc-bento-side\"><?php echo \$side_html; ?></div>" ),
    ) ),
    'A.4: gone from the editor' => array( $live, 'PLANT A.4', array(
        array( $php, $in_main, '' ),
    ) ),
    'A.4: narrower than the column' => array( $live, 'PLANT A.4', array(
        array( $css, "/* No margin of its own (3.107.1)", ".uc-series-first { max-width: 60%; }\n/* No margin of its own (3.107.1)" ),
    ) ),
    'A.4: the 18px margin is back' => array( $live, 'PLANT A.4', array(
        array( $css, "/* No margin of its own (3.107.1)", ".uc-series-first { margin-bottom: 18px; }\n/* No margin of its own (3.107.1)" ),
    ) ),
    'A.4: the thumbnail never loads' => array( $live, 'PLANT A.4', array(
        array( 'public/js/portal.js', "                    img.className = 'uc-prefill-thumb';", "                    img.className = 'uc-prefill-thumb'; src = 'broken:' + src;" ),
    ) ),
    'A.4: the thumbnail shrinks' => array( $live, 'PLANT A.4', array(
        array( $css, ".uc-prefill-thumb { display: block; width: 56px; height: 32px;", ".uc-prefill-thumb { display: block; width: 40px; height: 24px;" ),
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
