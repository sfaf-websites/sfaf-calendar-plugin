<?php
/**
 * PLANT A FAULT IN THE VIDEO BOX AND ITS PREVIEW, RUN THE CHECK, PUT IT BACK.
 *
 *     php .claude/plant-video-preview.php
 *
 * THE CHECK IS A BROWSER, which is why this planter runs a page rather than a
 * source reader: what is being asserted is the state of a preview after a
 * sequence of changes, and every one of these faults leaves the source looking
 * entirely reasonable.
 *
 * Restores every file and compares byte for byte before exiting.
 */

$root = dirname( __DIR__ );

$plants = array(

    /* ---- THE TICK, OFFERED WHERE IT DOES NOTHING. ----------------------- */
    /* THE FAULT THE BRIEF NAMES. The box was drawn on every event, including
     * ones whose series has no video, where it turned off something that was
     * never going to play. */
    'THE VIDEO BOX IS DRAWN ON AN EVENT WITH NO SERIES VIDEO' => array(
        'file' => 'public/js/portal.js',
        'from' => "                noneRow.hidden = !offer;",
        'to'   => "                noneRow.hidden = false;",
    ),

    'the server draws the tick on every event again' => array(
        'file' => 'includes/class-sfaf-portal.php',
        'from' => "<label class=\"uc-check\" data-uc-video-none-row<?php echo \$series_has ? '' : ' hidden'; ?>>",
        'to'   => "<label class=\"uc-check\" data-uc-video-none-row>",
    ),

    /* AND A HIDDEN TICK THAT IS STILL ON. Moving to a series with no video must
     * clear it, or it goes on suppressing a video with no control saying so. */
    'a hidden no-video tick stays ticked' => array(
        'file' => 'public/js/portal.js',
        'from' => "                if (!offer && none && none.checked) { none.checked = false; }",
        'to'   => "",
    ),

    /* ---- THE PREVIEW'S THREE INPUTS, AND THEIR ORDER. ------------------- */
    'the series video is never previewed' => array(
        'file' => 'public/js/portal.js',
        'from' => "            } else if (fromSeries) {\n                embed = fromSeries;\n                isSeries = true;\n            }",
        'to'   => "            }",
    ),

    "the event's own link stops winning over the series" => array(
        'file' => 'public/js/portal.js',
        'from' => "            if (own) {\n                embed = own;\n            } else if (none && none.checked) {",
        'to'   => "            if (false) {\n                embed = own;\n            } else if (none && none.checked) {",
    ),

    'the tick stops clearing the preview' => array(
        'file' => 'public/js/portal.js',
        'from' => "            } else if (none && none.checked) {\n                embed = '';",
        'to'   => "            } else if (false) {\n                embed = '';",
    ),

    /* THE LINE THAT SAYS WHOSE VIDEO IT IS. Without it a series video reads as
     * one the manager attached themselves. */
    'the preview stops saying the video is the series one' => array(
        'file' => 'public/js/portal.js',
        'from' => "            if (note) { note.hidden = !isSeries; }",
        'to'   => "            if (note) { note.hidden = true; }",
    ),

    'the note stays on screen over the event\'s own video' => array(
        'file' => 'public/js/portal.js',
        'from' => "                isSeries = true;\n            }\n\n            if (note) { note.hidden = !isSeries; }",
        'to'   => "                isSeries = true;\n            }\n\n            if (note) { note.hidden = false; }",
    ),

    /* ---- THE MAP, AND WHERE IT COMES FROM. ------------------------------ */
    'the series video map is never emitted' => array(
        'file' => 'includes/class-sfaf-portal.php',
        'from' => "        <script type=\"application/json\" data-uc-series-videos><?php",
        'to'   => "        <script type=\"application/json\" data-uc-series-videos-off><?php",
    ),

    /* AND THE PREVIEW REACTING TO THE SELECT AT ALL, which is what makes it
     * arrive before a save rather than after one. */
    'the preview stops watching the series select' => array(
        'file' => 'public/js/portal.js',
        'from' => "        if (select) { select.addEventListener('change', apply); }",
        'to'   => "",
    ),

    /* ---- RUBBISH IN THE FIELD. ----------------------------------------- */
    /* An address that is not a video must show NOTHING rather than a frame
     * that loads nothing and looks broken. */
    'anything typed is treated as a video' => array(
        'file' => 'public/js/portal.js',
        'from' => "            var own   = ucVideoEmbed(field.value);",
        'to'   => "            var own   = field.value ? field.value : '';",
    ),
);

$caught = 0;
$missed = array();
$good   = array();

foreach ( $plants as $p ) {
    if ( ! isset( $good[ $p['file'] ] ) ) {
        $good[ $p['file'] ] = file_get_contents( $root . '/' . $p['file'] );
    }
}

foreach ( $plants as $name => $p ) {
    $path = $root . '/' . $p['file'];
    $src  = $good[ $p['file'] ];

    if ( false === strpos( $src, $p['from'] ) ) {
        $missed[] = $name . '  [the plant no longer matches the source, so it proved nothing]';
        echo '  MISSED: ' . $name . "\n";
        continue;
    }
    $at     = strpos( $src, $p['from'] );
    $broken = substr( $src, 0, $at ) . $p['to'] . substr( $src, $at + strlen( $p['from'] ) );
    if ( $broken === $src ) {
        $missed[] = $name . '  [the swap changed nothing]';
        echo '  MISSED: ' . $name . "\n";
        continue;
    }
    file_put_contents( $path, $broken );

    $out  = array();
    $code = 0;
    exec( 'php ' . escapeshellarg( $root . '/.claude/video-preview-live.php' ) . ' --run 2>&1', $out, $code );

    file_put_contents( $path, $src );

    if ( 0 !== $code ) {
        $caught++;
        echo '  caught: ' . $name . "\n";
    } else {
        $missed[] = $name;
        echo '  MISSED: ' . $name . "\n";
    }
}

foreach ( $good as $rel => $src ) {
    file_put_contents( $root . '/' . $rel, $src );
    if ( file_get_contents( $root . '/' . $rel ) !== $src ) {
        echo 'RESTORE FAILED for ' . $rel . ". Do not build.\n";
        exit( 1 );
    }
}

/* The page on disk was rewritten by every run above, so put it back to one
 * built from the restored sources. */
exec( 'php ' . escapeshellarg( $root . '/.claude/video-preview-live.php' ) . ' 2>&1' );

echo "\n";
if ( $missed ) {
    echo 'PLANTS NOT CAUGHT: ' . count( $missed ) . ' of ' . count( $plants ) . "\n";
    foreach ( $missed as $m ) { echo '  . ' . $m . "\n"; }
    exit( 1 );
}
echo 'caught ' . $caught . ' of ' . count( $plants ) . " planted faults, and every file is restored.\n";
exit( 0 );
