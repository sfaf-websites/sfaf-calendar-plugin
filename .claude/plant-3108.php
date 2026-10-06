<?php
/**
 * THE 3.108.0 CHECKS, EACH MADE TO FAIL ON PURPOSE.
 *
 *     php .claude/plant-3108.php [part of a plant name]
 *
 * A: Add event opens on "A venue", Edit event on what it has, and the series
 * prefill still switches the location. B: the tour's skip rule, its focus
 * trap and the rest of what release-3108-live.php measures.
 *
 * A plant is one or more replacements, each written into the real file; the
 * check is run and every file is put back from memory, not from git, so an
 * uncommitted change cannot be lost. A replacement whose anchor is not there,
 * or is there twice, stops the run: a plant that did not apply proves nothing.
 * Never run this while run-all.sh is running.
 */

$root = dirname( __DIR__ );
$live = 'release-3108-live.php --run';
$php  = 'includes/class-sfaf-portal.php';
$js   = 'public/js/portal.js';

$plants = array(
    /* A */
    'A: Add event opens on A different location' => array( $live, 'PLANT A:', array(
        array( $php, "        } elseif ( ! \$event_id && ! empty( \$venues ) ) {", "        } elseif ( false ) {" ),
    ) ),
    'A: Edit event opens on A venue too' => array( $live, 'PLANT A:', array(
        array( $php, "        } elseif ( ! \$event_id && ! empty( \$venues ) ) {", "        } elseif ( ! empty( \$venues ) ) {" ),
    ) ),
    'A: the series prefill no longer switches the location' => array( $live, 'PLANT A PREFILL', array(
        array( $js, "                    if (mode) { mode.checked = true; mode.dispatchEvent(new Event('change', { bubbles: true })); }\n                    if (d.location_mode === 'venue') {",
            "                    if (d.location_mode === 'venue') {" ),
    ) ),
    /* B.3 */
    'B.3: a card that is not on the page is shown' => array( $live, 'PLANT B.3', array(
        array( $js, "                return !!card && card.getClientRects().length > 0;\n            });\n        }\n\n        function el(", "                return true;\n            });\n        }\n\n        function el(" ),
    ) ),
    /* B.2 */
    'B.2: Tab is not trapped' => array( $live, 'PLANT B.2', array(
        array( $js, "            if (e.key === 'Tab') {\n                var list = focusables()", "            if (false) {\n                var list = focusables()" ),
    ) ),
    'B.2: the page behind is not inert' => array( $live, 'PLANT B.2', array(
        array( $js, "                child.setAttribute('inert', '');\n", "" ),
    ) ),
    'B.2: Esc does nothing' => array( $live, 'PLANT B.2', array(
        array( $js, "            if (e.key === 'Escape' || e.key === 'Esc') { e.preventDefault(); close(); return; }\n", "" ),
    ) ),
    'B.2: focus is not handed back' => array( $live, 'PLANT B.2', array(
        array( $js, "            document.body.classList.remove('uc-tour-open');\n            link.focus();\n", "            document.body.classList.remove('uc-tour-open');\n" ),
    ) ),
    'B.2: not announced as a dialog' => array( $live, 'PLANT B.2', array(
        array( $js, "            panel.setAttribute('role', 'dialog');\n", "" ),
    ) ),
    /* B.1 and B.5 */
    'B.1: the tour starts on its own' => array( $live, 'PLANT B.1', array(
        array( $js, "        link.addEventListener('click', open);\n", "        link.addEventListener('click', open);\n        open();\n" ),
    ) ),
    'B.5: the ring is not on the card' => array( $live, 'PLANT B.5', array(
        array( $js, "var r = card.getBoundingClientRect(), pad = 6, gap = 12, m = 12;", "var r = card.getBoundingClientRect(), pad = 0, gap = 12, m = 12;" ),
    ) ),
    'B.5: the panel is not held inside the window' => array( $live, 'PLANT B.5', array(
        array( $js, "            top = Math.max(m, Math.min(top, vh - ph - m));\n            left = Math.max(m, Math.min(left, vw - pw - m));\n", "            if (wide) { left = vw - pw / 2; }\n" ),
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
