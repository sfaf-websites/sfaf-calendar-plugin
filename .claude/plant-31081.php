<?php
/**
 * THE 3.108.1 CHECKS, EACH MADE TO FAIL ON PURPOSE.
 *
 *     php .claude/plant-31081.php [part of a plant name]
 *
 * A the series panel's picture size, B.1 an image URL box back on the editor,
 * B.2 the pill wrong for the state and Remove not falling back, C two FAQs
 * open at once, D a notice off the component, E the emails row in its old
 * style, F a caption drifting from Mark's words.
 *
 * A plant is one or more replacements, each written into the real file; the
 * check is run and every file is put back from memory, not from git, so an
 * uncommitted change cannot be lost. A replacement whose anchor is not there,
 * or is there twice, stops the run: a plant that did not apply proves nothing.
 * Never run this while run-all.sh is running.
 */

$root = dirname( __DIR__ );
$live = 'release-31081-live.php --run';
$php  = 'includes/class-sfaf-portal.php';
$js   = 'public/js/portal.js';
$css  = 'public/css/portal.css';

$plants = array(
    'A: the series picture shrinks' => array( $live, 'PLANT A', array(
        array( $css, ".uc-prefill-thumb { display: block; width: 160px; height: 90px;", ".uc-prefill-thumb { display: block; width: 120px; height: 68px;" ),
    ) ),
    'B.1: the image URL box is back on the editor' => array( $live, 'PLANT B.1', array(
        array( $php, "                        'show_url'    => ! \$on_editor,", "                        'show_url'    => true," ),
    ) ),
    'B.2: an inherited picture reads Event-specific' => array( $live, 'PLANT B.2', array(
        array( $js, "state === 'event' ? 'data-uc-img-source-own' : (state === 'series' ? 'data-uc-img-source-series' : 'data-uc-img-source-none')",
            "state === 'event' ? 'data-uc-img-source-own' : (state === 'series' ? 'data-uc-img-source-own' : 'data-uc-img-source-none')" ),
    ) ),
    'B.2: Remove does not clear the event\'s own picture' => array( $live, 'PLANT B.2', array(
        array( $js, "                reset.disabled = (t.value !== '0');", "                reset.disabled = true;" ),
    ) ),
    'C: opening one FAQ leaves the others open' => array( $live, 'PLANT C', array(
        array( $js, "                Array.prototype.forEach.call(rows.querySelectorAll('[data-uc-faq-fold]'), function (other) {\n                    if (other !== row) { shut(other); }\n                });\n",
            "" ),
    ) ),
    'D: the prefill notice is the old flash again' => array( $live, 'PLANT D', array(
        array( $js, "            said.className = 'uc-notice' + (bad ? ' uc-notice-error' : '');", "            said.className = 'uc-flash uc-prefill-said' + (bad ? ' uc-flash-error' : '');" ),
    ) ),
    'E: the emails row in its old style' => array( $live, 'PLANT E', array(
        array( $php, '<summary class="uc-picker-toggle" aria-expanded="<?php echo $off_count', '<summary class="uc-team-add-toggle" aria-expanded="<?php echo $off_count' ),
    ) ),
    'F: a caption drifts' => array( 'release-3108-live.php --run', 'PLANT B.3', array(
        array( $php, "'Classification', 'Add at least one category and one organizer. A category is the kind of event", "'Classification', 'Add at least one category and an organizer. A category is the kind of event" ),
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
