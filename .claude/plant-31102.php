<?php
/**
 * THE 3.110.2 CHECKS, EACH MADE TO FAIL ON PURPOSE.
 *
 *     php .claude/plant-31102.php [part of a plant name]
 *
 * A.4 the organizer sent a second copy; B.1 a user row's Save on before
 * anything changed; B.2 Contributor categories shown for an Editor; C the
 * registration count back in the feed; F a table running off a 390px screen.
 *
 * A plant is one or more replacements, each written into the real file; the
 * check is run and every file is put back from memory, not from git, so an
 * uncommitted change cannot be lost. A replacement whose anchor is not there,
 * or is there twice, stops the run: a plant that did not apply proves nothing.
 * Never run this while run-all.sh is running.
 */

$root = dirname( __DIR__ );
$php  = 'includes/class-sfaf-portal.php';
$live = 'release-31102-live.php --run';

$plants = array(
    'A.4: the organizer gets a second copy' => array( 'registrant-mail-test.php', 'PLANT A.4', array(
        array( 'includes/class-sfaf-registrant-mail.php', "        if ( \$organizer && isset( \$seen[ \$organizer->email ] ) ) {\n            \$organizer = null;\n        }", '' ),
    ) ),
    'B.1: Save on before anything changed' => array( $live, 'PLANT B.1', array(
        array( 'public/js/portal.js', "            fields().forEach(function (f) { f.addEventListener('change', check); });\n            check();\n        });", "            fields().forEach(function (f) { f.addEventListener('change', check); });\n        });" ),
    ) ),
    'B.2: Contributor categories shown for an Editor' => array( $live, 'PLANT B.2', array(
        array( $php, "aria-expanded=\"false\" aria-controls=\"<?php echo esc_attr( \$cats_id ); ?>\"<?php echo \$contrib ? '' : ' hidden'; ?>>Contributor categories</button>", "aria-expanded=\"false\" aria-controls=\"<?php echo esc_attr( \$cats_id ); ?>\">Contributor categories</button>" ),
    ) ),
    'C: the registration count back in the feed' => array( 'team-access-test.php', 'PLANT C', array(
        array( 'includes/class-sfaf-sync.php', "            'faq'             => sfaf_get_faqs( \$post_id ),\n", "            'faq'             => sfaf_get_faqs( \$post_id ),\n            'rsvp_count'      => sfaf_get_rsvp_count( \$post_id ),\n" ),
    ) ),
    'F: the events table runs off a 390px screen' => array( $live, 'PLANT F', array(
        array( $php, "        \$tick_why = ( null !== \$ticks && isset( \$bulk['blocked'] ) ) ? \$bulk['blocked'] : array();\n        ?>\n        <table class=\"uc-table uc-table-cards\">", "        \$tick_why = ( null !== \$ticks && isset( \$bulk['blocked'] ) ) ? \$bulk['blocked'] : array();\n        ?>\n        <table class=\"uc-table\">" ),
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
