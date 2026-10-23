<?php
/**
 * THE 3.115.0 CHECKS, EACH MADE TO FAIL ON PURPOSE.
 *
 *     php .claude/plant-31150.php [part of a plant name]
 *
 * One fault per plant, written into the real file; the check is run, and every
 * file is put back from memory, not from git. An anchor that is not there, or
 * is there twice, stops the run. Commit first, and never run this while
 * run-all.sh is running.
 */

$root = dirname( __DIR__ );

$plants = array(
    'A: RSVP Here is not stored' => array( 'release-31150-test.php', 'A: here is stored as here', array(
        array( 'includes/class-sfaf-register-elsewhere.php', "        if ( 'here' === \$choice || 'elsewhere' === \$choice ) {", "        if ( 'elsewhere' === \$choice ) {" ),
    ) ),
    'B: a new event ignores RSVP Here' => array( 'release-31150-test.php', 'B: a new event in an RSVP Here series starts on RSVP Here', array(
        array( 'includes/class-sfaf-register-elsewhere.php', "            return self::series_choice( (int) \$series_id );", "            return 'elsewhere' === self::series_mode( (int) \$series_id ) ? 'elsewhere' : 'none';" ),
    ) ),
    'C: anybody may change the series RSVP' => array( 'release-31150-test.php', 'C: and a hand-made post changes nothing', array(
        array( 'includes/class-sfaf-portal.php', "        if ( ! \$term_id || ! \$this->can_manage_series_events( \$user, \$term_id ) ) {\n            return;\n        }\n        // The series' RSVP choice (3.115.0)", "        if ( ! \$term_id ) {\n            return;\n        }\n        // The series' RSVP choice (3.115.0)" ),
    ) ),
    'C: the control is drawn for anybody' => array( 'release-31150-test.php', 'C: they see it read-only', array(
        array( 'includes/class-sfaf-portal.php', "                    <?php if ( \$this->can_manage_series_events( \$user, (int) \$term_id ) ) : ?>\n                        <div class=\"uc-reg-choice\" data-uc-reg-choice>", "                    <?php if ( true ) : ?>\n                        <div class=\"uc-reg-choice\" data-uc-reg-choice>" ),
    ) ),
    'D: no series, no Language' => array( 'release-31150-test.php', 'D: with no series, Add event keeps Language', array(
        array( 'includes/class-sfaf-portal.php', "        \$all_series = (array) \$all_series;\n        \$has_series = ! empty( \$all_series );", "        \$all_series = (array) \$all_series;\n        \$has_series = ! empty( \$all_series );\n        if ( ! \$has_series ) { return; }" ),
    ) ),
    'L: the series page reads the event form\'s field' => array( 'release-31150-live.php', 'series: External RSVP does not open the link box', array(
        array( 'public/js/portal.js', "                var choice = boxChoice();\n                var away = choice === 'elsewhere';", "                var choice = regChoice();\n                var away = choice === 'elsewhere';" ),
    ) ),
    'L: Add event ignores the series\' choice' => array( 'release-31150-live.php', 'add: an RSVP Here series does not give RSVP Here', array(
        array( 'public/js/portal.js', "                    var want = d.choice || (d.mode === 'elsewhere' ? 'elsewhere' : 'none');", "                    var want = d.mode === 'elsewhere' ? 'elsewhere' : 'none';" ),
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
