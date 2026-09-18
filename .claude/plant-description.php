<?php
/**
 * PLANT A FAULT IN THE DESCRIPTION RESOLVER, RUN THE CHECK, PUT IT BACK.
 *
 *     php .claude/plant-description.php
 *
 * THE TWO DIRECTIONS ARE BOTH FAULTS, and they are opposite:
 *
 *   THE SERIES' TEXT REACHING AN EVENT THAT HAS ITS OWN. The words somebody
 *   typed are replaced by the programme's, which is data loss on screen.
 *
 *   THE SEED'S EXCERPT REACHING ANY SURFACE. The hidden field no screen offers
 *   and nothing in the editor writes, which is where every date in a generated
 *   series got the same text however often one was edited.
 *
 * Restores every file and compares byte for byte before exiting.
 */

$root = dirname( __DIR__ );

$plants = array(

    /* ---- THE RUNGS, AND THEIR ORDER. ------------------------------------ */
    'THE SERIES DESCRIPTION REACHES AN EVENT THAT HAS ITS OWN' => array(
        'file' => 'includes/sfaf-template-functions.php',
        'from' => "    \$own     = \$post ? trim( (string) \$post->post_content ) : '';\n    if ( '' !== \$own ) {\n        return \$own;\n    }",
        'to'   => "    \$own     = \$post ? trim( (string) \$post->post_content ) : '';",
    ),

    'the series rung is gone, so an empty event shows nothing again' => array(
        'file' => 'includes/sfaf-template-functions.php',
        'from' => "    \$term = SFAF_Series::for_event( \$post_id );",
        'to'   => "    \$term = null;",
    ),

    'whitespace counts as a description, so the series never shows' => array(
        'file' => 'includes/sfaf-template-functions.php',
        'from' => "    \$own     = \$post ? trim( (string) \$post->post_content ) : '';",
        'to'   => "    \$own     = \$post ? (string) \$post->post_content : '';",
    ),

    /* THE EDITOR'S NOTE ASKS WHETHER THERE IS A SERIES DESCRIPTION TO SHOW, not
     * whether there is a series. A programme whose description nobody has
     * written yet would otherwise be promised to the manager and then not
     * appear, which is the worst of the three states to be in. */
    'the editor asks whether there is a series, not whether it has a description' => array(
        'file' => 'includes/sfaf-template-functions.php',
        'from' => "    return ( '' !== sfaf_event_description_html( \$post_id ) );",
        'to'   => "    return ( null !== SFAF_Series::for_event( \$post_id ) );",
    ),

    /* ---- THE EDITOR'S PROMISE. ------------------------------------------ */
    'the editor promises the series description to an event with its own' => array(
        'file' => 'includes/sfaf-template-functions.php',
        'from' => "    if ( \$post && '' !== trim( (string) \$post->post_content ) ) {\n        return false;\n    }",
        'to'   => "    if ( false ) {\n        return false;\n    }",
    ),

    /* ---- THE SEED'S EXCERPT, ON EACH SURFACE SEPARATELY. ---------------- */
    /* FOUR PLANTS, NOT ONE. Each surface is a call site somebody can change on
     * its own, and three of the four were wrong in exactly this way before. */
    'A SEED SUMMARY REACHES A CARD' => array(
        'file' => 'includes/class-sfaf-shortcodes.php',
        'from' => "\$summary = wp_trim_words( sfaf_event_description_text( \$post_id ), 25 );",
        'to'   => "\$summary = wp_trim_words( sfaf_flatten_html( get_the_excerpt( \$post_id ) ?: get_the_content( null, false, \$post_id ) ), 25 );",
    ),

    'the search engine summary goes back to the excerpt' => array(
        'file' => 'includes/class-sfaf-seo.php',
        'from' => "        return wp_trim_words( sfaf_event_description_text( \$id ), 40 );",
        'to'   => "        \$excerpt = get_the_excerpt( \$id );\n        return wp_trim_words( sfaf_flatten_html( \$excerpt ? \$excerpt : get_post_field( 'post_content', \$id ) ), 40 );",
    ),

    'the calendar file goes back to the excerpt' => array(
        'file' => 'sfaf-calendar.php',
        'from' => "    \$description = sfaf_event_description_text( \$post_id );",
        'to'   => "    \$description = sfaf_flatten_html( get_the_excerpt( \$post_id ) );",
    ),

    'the event page stops falling back at all' => array(
        'file' => 'templates/single-uc_event.php',
        'from' => "                            echo apply_filters( 'the_content', sfaf_event_description_html( \$post_id ) );",
        'to'   => "                            echo '';",
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
    exec( 'php ' . escapeshellarg( $root . '/.claude/description-source-test.php' ) . ' 2>&1', $out, $code );

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

echo "\n";
if ( $missed ) {
    echo 'PLANTS NOT CAUGHT: ' . count( $missed ) . ' of ' . count( $plants ) . "\n";
    foreach ( $missed as $m ) { echo '  . ' . $m . "\n"; }
    exit( 1 );
}
echo 'caught ' . $caught . ' of ' . count( $plants ) . " planted faults, and every file is restored.\n";
exit( 0 );
