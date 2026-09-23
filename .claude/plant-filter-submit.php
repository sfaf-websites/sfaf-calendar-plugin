<?php
/**
 * PLANT THE 3.98.1 FAULT BACK, RUN THE CHECK, PUT IT RIGHT.
 *
 *     php .claude/plant-filter-submit.php
 *
 * THE FIRST TWO PLANTS ARE THE ONE THAT SHIPPED: the pill handler with no
 * `e.preventDefault()`, in each script in turn. That is exactly the state
 * 3.85.0 left behind when it put a real GET form round the filter bar, and it
 * was live on sfaf.org from that release. The browser check must go red on it,
 * or the browser check is decoration.
 *
 * The rest are the other ways the same relationship breaks: a pill that stops
 * saying what type it is, a pill that stops carrying its own category, and the
 * hidden field coming back beside the pills so the address holds two answers to
 * one question.
 *
 * SAFE ON A DIRTY TREE. Every file is read once, restored from that copy, and
 * compared byte for byte before this exits. Nothing here runs `git checkout`.
 *
 * SLOW ON PURPOSE. Four of the five plants are judged by driving Chrome twice,
 * which is the only place a missing default is visible at all.
 */

$root = dirname( __DIR__ );

$live  = 'php ' . escapeshellarg( $root . '/.claude/filter-submit-live.php' ) . ' --run';
$audit = 'php ' . escapeshellarg( $root . '/.claude/form-owner-audit.php' );

$plants = array(

	'THE 3.98.1 FAULT: embed.js stops preventing the pill default' => array(
		'file'  => 'public/js/embed.js',
		'from'  => "                    e.preventDefault();\n                    for (var j = 0; j < buttons.length; j++) {",
		'to'    => "                    for (var j = 0; j < buttons.length; j++) {",
		'check' => $live,
	),

	'THE SAME FAULT IN calendar.js' => array(
		'file'  => 'public/js/calendar.js',
		'from'  => "            e.preventDefault();\n            var \$btn   = \$(this);",
		'to'    => "            var \$btn   = \$(this);",
		'check' => $live,
	),

	'a pill goes back to having no type, so it submits by default' => array(
		'file'  => 'includes/class-sfaf-shortcodes.php',
		'from'  => '<button type="submit" name="uc_cat" value=""' . "\n"
			. '                            class="uc-filter-btn',
		'to'    => '<button name="uc_cat" value=""' . "\n"
			. '                            class="uc-filter-btn',
		'check' => $audit,
	),

	'a pill stops carrying its own category, so the no-script reload keeps the old one' => array(
		'file'  => 'includes/class-sfaf-shortcodes.php',
		'from'  => '<button type="submit" name="uc_cat" value="<?php echo esc_attr( $cat->slug ); ?>"',
		'to'    => '<button type="submit"',
		'check' => $live,
	),

	'Apply stops carrying the chosen category, so a no-script Apply clears it' => array(
		'file'  => 'includes/class-sfaf-shortcodes.php',
		'from'  => '<button type="submit" name="uc_cat" value="<?php echo esc_attr( $active_category ); ?>"' . "\n"
			. '                            class="uc-who-apply"',
		'to'    => '<button type="submit"' . "\n"
			. '                            class="uc-who-apply"',
		'check' => 'php ' . escapeshellarg( $root . '/.claude/embed-modes-test.php' ),
	),

	'the hidden uc_cat comes back, so the address holds two categories' => array(
		'file'  => 'includes/class-sfaf-shortcodes.php',
		'from'  => '            <form class="uc-filter-form" method="get" action="" data-uc-filter-form>',
		'to'    => '            <form class="uc-filter-form" method="get" action="" data-uc-filter-form>' . "\n"
			. '                <input type="hidden" name="uc_cat" value="<?php echo esc_attr( $active_category ); ?>" />',
		'check' => $live,
	),
);

$good   = array();
$caught = 0;
$missed = array();

foreach ( $plants as $p ) {
	if ( ! isset( $good[ $p['file'] ] ) ) {
		$good[ $p['file'] ] = file_get_contents( $root . '/' . $p['file'] );
	}
}

/* THE CLEAN TREE HAS TO BE GREEN FIRST, or every plant below "fails" for a
 * reason that has nothing to do with the plant. */
$out = array();
$code = 0;
exec( $live . ' 2>&1', $out, $code );
if ( 0 !== $code ) {
	echo "REFUSING: the clean tree already fails filter-submit-live.php.\n";
	foreach ( array_slice( $out, -12 ) as $l ) { echo '  ' . $l . "\n"; }
	exit( 2 );
}

foreach ( $plants as $name => $p ) {
	$path = $root . '/' . $p['file'];
	$src  = $good[ $p['file'] ];

	if ( false === strpos( $src, $p['from'] ) ) {
		$missed[] = $name . '  [the plant no longer matches the source, so it proved nothing]';
		echo '  NOT PLANTED: ' . $name . "\n";
		continue;
	}
	$at     = strpos( $src, $p['from'] );
	$broken = substr( $src, 0, $at ) . $p['to'] . substr( $src, $at + strlen( $p['from'] ) );
	file_put_contents( $path, $broken );

	$out  = array();
	$code = 0;
	exec( $p['check'] . ' 2>&1', $out, $code );

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

/* AND THE GENERATED PAGES GO BACK TO THE CLEAN TREE'S. The last plant left
 * them written from broken source, and they are committed. */
exec( 'php ' . escapeshellarg( $root . '/.claude/filter-submit-live.php' ) . ' >' . ( DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null' ) . ' 2>&1' );

echo "\n";
if ( $missed ) {
	echo 'PLANTS NOT CAUGHT: ' . count( $missed ) . ' of ' . count( $plants ) . "\n";
	foreach ( $missed as $m ) { echo '  . ' . $m . "\n"; }
	exit( 1 );
}
echo 'caught ' . $caught . ' of ' . count( $plants ) . " planted faults, and every file is restored.\n";
exit( 0 );
