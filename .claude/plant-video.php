<?php
/**
 * PLANT A FAULT IN SFAF_Video, RUN THE TEST, PUT IT BACK.
 *
 *     php .claude/plant-video.php
 *
 * WHY THIS IS A FILE. A perl or sed one-liner carrying `$url` loses it to the
 * shell, which is the trap CLAUDE.md 5 records and which silently produced a
 * "passing" plant here: the substitution never matched, the file was untouched,
 * and the test passed for the one reason that proves nothing.
 *
 * So each plant is an exact string swap checked for having actually happened,
 * and a plant that does not change the file is itself a failure.
 */

$root = dirname( __DIR__ );
$file = $root . '/includes/class-sfaf-video.php';
$good = file_get_contents( $file );

$plants = array(

    /*
     * EMBED CODE IS REFUSED TWICE OVER, so this plant has to take both layers.
     * Removing the tag guard alone changes nothing, because parse_url() of an
     * <iframe> string returns a path and no host, and the host is required.
     * That is worth knowing rather than worth trusting, so the plant removes
     * the tag guard AND makes a missing host survive, which is the only shape
     * in which embed code could ever be accepted.
     */
    'embed code is accepted, with both of the two guards taken out' => array(
        'from' => "        if ( false !== strpos( \$url, '<' ) || false !== strpos( \$url, '>' ) ) {\n            return false;\n        }\n\n        \$parts = wp_parse_url( \$url );\n        if ( ! is_array( \$parts ) || empty( \$parts['host'] ) ) {\n            return false;\n        }\n\n        \$host = strtolower( \$parts['host'] );",
        'to'   => "        \$parts = wp_parse_url( \$url );\n        if ( ! is_array( \$parts ) ) {\n            return false;\n        }\n        if ( empty( \$parts['host'] ) && preg_match( '#//([^/\"]+)#', \$url, \$hm ) ) {\n            \$parts['host'] = \$hm[1];\n            \$parts['path'] = '/watch';\n            \$parts['query'] = 'v=dQw4w9WgXcQ';\n        }\n        \$host = strtolower( isset( \$parts['host'] ) ? \$parts['host'] : '' );",
    ),

    'any host is accepted, because the YouTube test stopped naming a host' => array(
        'from' => "        if ( 'youtube.com' === \$host || 'm.youtube.com' === \$host || 'music.youtube.com' === \$host ) {",
        'to'   => "        if ( false !== strpos( \$host, 'youtube' ) || true ) {",
    ),

    'the YouTube id is no longer matched on shape' => array(
        'from' => "\$yt = '[A-Za-z0-9_-]{11}';",
        'to'   => "\$yt = '[A-Za-z0-9_-]+';",
    ),

    /*
     * THE PATH PATTERN IS THE GUARD HERE, NOT THE HOST. Adding player.vimeo.com
     * to the host test changes nothing on its own, because /video/123456789
     * does not match a bare id. So the plant widens the path too, which is the
     * shape in which a player address would actually be accepted.
     */
    'a Vimeo player address is taken as a page address' => array(
        'from' => "        if ( 'vimeo.com' === \$host ) {",
        'to'   => "        if ( 'vimeo.com' === \$host || 'player.vimeo.com' === \$host ) {\n            if ( preg_match( '#^/video/(\\d{6,})\$#', \$path, \$pm ) ) {\n                return self::vimeo( \$pm[1] );\n            }",
    ),

    'YouTube is embedded through the cookie-setting host' => array(
        'from' => "'embed'   => 'https://www.youtube-nocookie.com/embed/' . rawurlencode( \$id ),",
        'to'   => "'embed'   => 'https://www.youtube.com/embed/' . rawurlencode( \$id ),",
    ),

    'the frame is no longer lazy loaded' => array(
        'from' => " width=\"560\" height=\"315\" loading=\"lazy\"'",
        'to'   => " width=\"560\" height=\"315\"'",
    ),

    'the frame title is generic rather than the event name' => array(
        'from' => "        \$title = get_the_title( \$event_id );",
        'to'   => "        \$title = 'Video';",
    ),

    'the opt out is read after the event\'s own link' => array(
        'from' => "        if ( self::is_none( \$event_id ) ) {\n            return '';\n        }\n\n        \$own = self::own( \$event_id );",
        'to'   => "        \$own = self::own( \$event_id );",
    ),

    'the series is never consulted, so nothing inherits' => array(
        'from' => "        return SFAF_Series::video( \$term_id );",
        'to'   => "        return '';",
    ),

    'a stored value that no longer parses draws a frame anyway' => array(
        'from' => "        \$v = self::parse( \$url );\n        if ( ! \$v ) {",
        'to'   => "        \$v = self::parse( \$url );\n        if ( ! \$v ) { \$v = array( 'service' => 'youtube', 'id' => 'x', 'embed' => \$url ); }\n        if ( false ) {",
    ),
);

$caught = 0;
$missed = array();

foreach ( $plants as $name => $p ) {
    if ( false === strpos( $good, $p['from'] ) ) {
        $missed[] = $name . '  [the plant no longer matches the source, so it proved nothing]';
        continue;
    }
    $broken = str_replace( $p['from'], $p['to'], $good );
    if ( $broken === $good ) {
        $missed[] = $name . '  [the swap changed nothing]';
        continue;
    }
    file_put_contents( $file, $broken );

    $out  = array();
    $code = 0;
    exec( 'php ' . escapeshellarg( $root . '/.claude/video-test.php' ) . ' 2>&1', $out, $code );

    if ( 0 !== $code ) {
        $caught++;
        echo "  caught: " . $name . "\n";
    } else {
        $missed[] = $name;
        echo "  MISSED: " . $name . "\n";
    }
}

file_put_contents( $file, $good );

// The file must be exactly as it was, or a planted fault has just shipped.
if ( file_get_contents( $file ) !== $good ) {
    echo "RESTORE FAILED: the source is not what it was. Do not build.\n";
    exit( 1 );
}

echo "\n";
if ( $missed ) {
    echo 'PLANTS NOT CAUGHT: ' . count( $missed ) . " of " . count( $plants ) . "\n";
    foreach ( $missed as $m ) { echo '  . ' . $m . "\n"; }
    exit( 1 );
}
echo 'caught ' . $caught . ' of ' . count( $plants ) . " planted faults, and the source is restored.\n";
exit( 0 );
