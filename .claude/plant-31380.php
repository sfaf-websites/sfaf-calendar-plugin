<?php
/**
 * THE 3.138.0 CHECKS, MADE TO FAIL ON PURPOSE.
 *
 *     php .claude/plant-31380.php [part of a plant's name]
 *
 * Plants, one at a time, a fault in the queue's Publish, the morning-of
 * reminder's note, the Language filter or embed-isolation-live's wait for its
 * stylesheet, and runs the check that must catch it. Every file is put back
 * from memory. Commit first, and never run this while run-all.sh is running.
 *
 * The two isolation plants blackhole Google Fonts, so each page waits about
 * 27 seconds for the import: they take minutes, not seconds.
 */

$root = dirname( __DIR__ );
$P = 'includes/class-sfaf-portal.php';
$N = 'includes/class-sfaf-notifications.php';
$C = 'public/css/portal.css';
$J = 'public/js/portal.js';
$E = '.claude/embed-isolation-live.php';
$T = 'release-31380-test.php';
$L = 'release-31380-live.php';
$I = 'embed-isolation-live.php';

$plants = array(
    'Publish back in the brand green' => array( $L, 'PLANT P:', array(
        array( $C, ".uc-link-ok { background: none; border: none; color: var(--p-ok-ink);", ".uc-link-ok { background: none; border: none; color: var(--p-green);" ),
    ) ),
    'Publish in green, but the tint' => array( $T, 'FAIL  .uc-link-ok is the green family\'s ink', array(
        array( $C, ".uc-link-ok { background: none; border: none; color: var(--p-ok-ink);", ".uc-link-ok { background: none; border: none; color: var(--p-ok-tint);" ),
    ) ),
    'Publish made a box' => array( $L, 'PLANT P:', array(
        array( $C, ".uc-link-ok { background: none; border: none; color: var(--p-ok-ink);", ".uc-link-ok { background: #F1F8E9; border: 1px solid #46661F; color: var(--p-ok-ink);" ),
    ) ),
    'the reminder note without the sentence' => array( $T, 'FAIL  the Morning-of reminder note ends with', array(
        array( $N, " Your notification list is copied in. Also sent by text or WhatsApp to anyone who chose it on the RSVP form.'", " Your notification list is copied in.'" ),
    ) ),
    'the sentence on the wrong email' => array( $T, 'FAIL  the Confirmation note is unchanged', array(
        array( $N, "and a link to cancel.',", "and a link to cancel. Also sent by text or WhatsApp to anyone who chose it on the RSVP form.'," ),
    ) ),
    'no Language filter' => array( $L, 'PLANT L:', array(
        array( $P, "                    <select data-uc-rcp-lang>", "                    <select data-uc-rcp-lang-off>" ),
    ) ),
    'every row English' => array( $T, 'FAIL  email: every row says its language', array(
        array( $P, "data-uc-rcp-lang=\"<?php echo ( isset( \$p['lang'] ) && 'es' === \$p['lang'] ) ? 'es' : 'en'; ?>\"", "data-uc-rcp-lang=\"en\"" ),
    ) ),
    'Spanish only adds to who is ticked' => array( $L, 'PLANT L:', array(
        array( $J, "tickWhere(function (r) { return r.getAttribute('data-uc-rcp-lang') === want; });", "rows.forEach(function (r) { var b = boxOf(r); if (b && !b.disabled && r.getAttribute('data-uc-rcp-lang') === want) { b.checked = true; } }); update();" ),
    ) ),
    'Language leaves By answer set' => array( $L, 'PLANT L:', array(
        array( $J, "                    if (!lang.value) { return; }\n                    clearFilters(lang);", "                    if (!lang.value) { return; }" ),
    ) ),
    'Tick all leaves Language set' => array( $L, 'PLANT L:', array(
        array( $J, "            function clearFilters(keep) { [answer, missed, lang].forEach(", "            function clearFilters(keep) { [answer, missed].forEach(" ),
    ) ),
    /* Item 4: the fonts import stalled. Without the new readiness test the
       check measures an unstyled block and blames the layout, as in 3 of 42
       runs; with it, the same stall is named for what it is. */
    'isolation: a stalled import, the old readiness test' => array( $I, 'PLANT K: with the grid the taller column, the sidebar is stretched', array(
        array( $E, "return !!l.sheet && applied(l.sheet); });", "return !!l.sheet; });" ),
        array( $E, "MAP fonts.googleapis.com ~NOTFOUND", "MAP fonts.googleapis.com 10.255.255.1" ),
    ) ),
    'isolation: a stalled import, the new readiness test' => array( $I, 'had not loaded after 20 seconds', array(
        array( $E, "MAP fonts.googleapis.com ~NOTFOUND", "MAP fonts.googleapis.com 10.255.255.1" ),
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
    $args = ( false !== strpos( $check, '-live' ) ) ? ' --run' : '';
    $out = shell_exec( 'cd ' . escapeshellarg( $root ) . ' && php ' . escapeshellarg( __DIR__ . '/' . $check ) . $args . ' 2>&1' );
    foreach ( $saved as $sp => $orig ) { file_put_contents( $sp, $orig ); }
    $caught = ( false !== strpos( (string) $out, $want ) );
    echo ( $caught ? 'caught  ' : 'MISSED  ' ) . $name . "\n";
    if ( ! $caught ) { $missed++; echo '        ' . str_replace( "\n", "\n        ", trim( substr( (string) $out, -1500 ) ) ) . "\n"; }
}
echo $missed ? "$missed PLANT(S) MISSED\n" : "every plant caught.\n";
exit( $missed ? 1 : 0 );
