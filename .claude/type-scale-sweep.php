<?php
/**
 * IS THE TYPE SCALE ACTUALLY ON THE PAGE?
 *
 *     php .claude/type-scale-sweep.php
 *     php .claude/type-scale-sweep.php --self-test
 *
 * DESIGN.md fixes seven steps and says every one of them is visibly different,
 * and that nothing may sit between two steps:
 *
 *     Page title  24px / 800     Card heading 14px / 600 uppercase, banded
 *     Section     20px / 700     Subhead      16px / 600
 *     Field label 13px / 600     Body         14px / 400
 *     Helper      12px / 400 muted
 *
 * WHY A SWEEP AND NOT A PARAGRAPH. The Classification card reads heavier than
 * every other card in the editor, and the reason is not its heading, which is
 * the same .uc-bento-title as all of them. It is that the card is mostly
 * checkbox labels, and .uc-check was set to 14px/500. 500 is not a step. Inside
 * .uc-check-grid it is overridden to 13px and the 500 is left, so a checkbox in
 * a grid ends up at the FIELD LABEL's size with a weight that is nothing on the
 * ladder, sitting directly under a real field label. Two things a step apart on
 * paper render a half step apart, and a card with thirty of them reads as a wall.
 *
 * That is the whole complaint about the portal that the scale was written to
 * answer in 3.13.0, reappearing because the scale was applied to headings and
 * never to the elements underneath them.
 *
 * WHAT IT CHECKS. Every rule in portal.css that sets a font-size, together with
 * whatever font-weight the same rule sets, and a report of any pair that is not
 * one of the seven. A size with no weight in the same rule is fine and common:
 * it inherits, and inheriting is how a scale stays a scale.
 *
 * WHAT IT DOES NOT CHECK. It reads one declaration block at a time and does not
 * compute the cascade, so a rule setting only a weight and another setting only
 * a size can still combine into something off-ladder. It catches the shape that
 * has actually gone wrong twice, which is a single rule inventing a pair.
 */

$root  = dirname( __DIR__ );
$self  = in_array( '--self-test', $argv, true );
$css   = file_get_contents( $root . '/public/css/portal.css' );

/* THE LADDER, from DESIGN.md. size => allowed weights. */
$ladder = array(
	24 => array( 800 ),
	20 => array( 700 ),
	16 => array( 600 ),
	14 => array( 600, 400 ), // card heading, and body
	13 => array( 600 ),
	12 => array( 400 ),
);

/*
 * NOT EVERY PIXEL IN THIS FILE IS PROSE. Buttons, chips, badges and the pieces
 * of chrome that are neither a heading nor a sentence carry their own sizes on
 * purpose, and forcing them onto the prose ladder would make the file worse.
 * The exemption is by SELECTOR and each one is named, so adding to this list is
 * a decision somebody has to write down rather than a silent widening.
 */
$exempt = array(
	'.uc-btn'          => 'buttons are chrome, and their size ladder is their own',
	'uc-chip'          => 'chips are a coloured pill, sized to the pill',
	'uc-badge'         => 'badges are chrome',
	'uc-pill'          => 'chrome',
	'uc-tab'           => 'chrome',
	'uc-icon'          => 'icons are sized in px like images',
	'uc-brandmark'     => 'the wordmark is a logo',
	'uc-login'         => 'the login card is its own composition and predates the scale',
	'uc-sidebar'       => 'the dark sidebar has its own measured treatment; see DESIGN.md',
	'uc-scope-btn-sub' => 'the second line inside a scope button, which is part of the button',
	'uc-kbd'           => 'keyboard keys are chrome',
	'uc-empty-big'     => 'the empty-state numeral is a graphic, not type',
	'uc-portal-brandtext' => 'the wordmark beside the logo is part of a brand lockup',
	'uc-nav-item'         => 'sidebar navigation; the dark sidebar is measured on its own terms',
	'uc-nav-badge'        => 'a count badge',
	'uc-portal-me-role'   => 'the role caption in the sidebar identity block',
	'uc-portal-signout'   => 'sidebar chrome',
	'uc-stat-num'         => 'a numeral read as a graphic, like the empty-state one',
	'uc-help-btn'         => 'the circular help glyph, sized to its circle',
	'uc-day >'            => 'the day numeral in a calendar cell is a graphic',
	'uc-rchips-label'     => 'the label on the recurrence chip row, which is part of the row',
	'uc-rchip'            => 'a chip',
	'uc-email-pill'       => 'a pill',
	'uc-tag-extra'        => 'a tag',
	'uc-img-source-tag'   => 'a tag',
	'uc-count-badge'      => 'a badge',
	'uc-source-badge'     => 'a badge',
	'uc-field-flag'       => 'the small flag beside a field name, which is a badge',
	'uc-needs-tip'        => 'a tooltip',
	'uc-member-chip'      => 'a chip',
	'uc-optin-yes'        => 'a status marker in a table cell, not a sentence',
	'uc-remove-sub-label' => 'the second line inside a radio option, part of the control',
	'uc-add-toggle'       => 'a filled yellow button that happens to be a <summary>; chrome, like .uc-btn',
	'uc-portal-me-name'   => 'the name in the sidebar identity block, beside the role caption already exempt above; the dark sidebar is measured on its own terms, see DESIGN.md',
);

/* --------------------------------------------------------------------------
 * Every declaration block, with its selector.
 * ----------------------------------------------------------------------- */
function blocks( $css ) {
	$out = array();
	/* Comments out first, so a size quoted in prose is not a rule. */
	$css = preg_replace( '#/\*.*?\*/#s', '', $css );
	if ( ! preg_match_all( '#([^{}]+)\{([^{}]*)\}#s', $css, $m, PREG_SET_ORDER ) ) {
		return $out;
	}
	foreach ( $m as $b ) {
		$sel = trim( preg_replace( '#\s+#', ' ', $b[1] ) );
		if ( '' === $sel || '@' === $sel[0] ) {
			continue;
		}
		$out[] = array( $sel, $b[2] );
	}
	return $out;
}

function offenders( $css, $ladder, $exempt ) {
	$found = array();
	foreach ( blocks( $css ) as $b ) {
		list( $sel, $body ) = $b;

		/*
		 * FRACTIONAL SIZES ARE THE POINT, NOT AN EDGE CASE.
		 *
		 * This read `(\d+)px`, which cannot match `13.5px` at all: the digits
		 * have to be followed immediately by "px", so the rule was skipped
		 * rather than judged. A half pixel is BY DEFINITION between two steps,
		 * so the one value the ladder exists to forbid was the one value the
		 * sweep could not see, and portal.css had a dozen of them. The
		 * self-test did not catch it because every case in it was a whole
		 * number, which is the same fault as the tests this release is about:
		 * it asserted the checker worked on the shape already known to be
		 * wrong.
		 */
		if ( ! preg_match( '#font-size:\s*([0-9]*\.?[0-9]+)px#', $body, $s ) ) {
			continue;
		}
		if ( ! preg_match( '#font-weight:\s*(\d+)#', $body, $w ) ) {
			continue; // inherits its weight, which is how a scale holds
		}

		$raw    = (float) $s[1];
		$size   = (int) $raw;
		$weight = (int) $w[1];

		/* A size that is not a whole pixel is off the ladder whatever weight it
		 * carries, and is reported at its real value rather than truncated. */
		$fractional = ( abs( $raw - $size ) > 0.0001 );

		$skip = false;
		foreach ( $exempt as $needle => $why ) {
			if ( false !== strpos( $sel, $needle ) ) {
				$skip = true;
				break;
			}
		}
		if ( $skip ) {
			continue;
		}

		if ( $fractional || ! isset( $ladder[ $size ] ) || ! in_array( $weight, $ladder[ $size ], true ) ) {
			$found[] = array( $sel, $fractional ? $s[1] : $size, $weight );
		}
	}
	return $found;
}

/* --------------------------------------------------------------------------
 * SELF TEST. Plant pairs that are off the ladder and prove they are rejected,
 * and pairs that are on it and prove they are not.
 * ----------------------------------------------------------------------- */
if ( $self ) {
	$cases = array(
		array( '.uc-planted-a { font-size: 14px; font-weight: 500; }', true, 'the half step this file was written for' ),
		array( '.uc-planted-b { font-size: 13px; font-weight: 500; }', true, 'field-label size at a weight that is not a step' ),
		array( '.uc-planted-c { font-size: 15px; font-weight: 400; }', true, 'a size that is not on the ladder at all' ),
		array( '.uc-planted-d { font-size: 14px; font-weight: 400; }', false, 'body, which is a step' ),
		array( '.uc-planted-e { font-size: 13px; font-weight: 600; }', false, 'a field label, which is a step' ),
		array( '.uc-btn-planted { font-size: 15px; font-weight: 500; }', false, 'exempt: buttons are chrome' ),
		array( "/* .uc-planted-f { font-size: 11px; font-weight: 300; } */", false, 'in a comment, so not a rule' ),
		/* The half pixel, which the first version of this file could not see. */
		array( '.uc-planted-g { font-size: 13.5px; font-weight: 550; }', true, 'a half pixel at a weight that is not a step' ),
		array( '.uc-planted-h { font-size: 13.5px; font-weight: 600; }', true, 'a half pixel is off the ladder even at a real weight' ),
		array( '.uc-planted-i { font-size: 12.5px; font-weight: 700; }', true, 'the other half pixel actually in the file' ),
		array( '.uc-planted-j { font-size: 13px; font-weight: 600; line-height: 1.5; }', false, 'a step, with other properties around it' ),
	);
	$bad = 0;
	echo "SELF TEST\n" . str_repeat( '=', 72 ) . "\n";
	foreach ( $cases as $c ) {
		list( $rule, $should, $why ) = $c;
		$hits = offenders( $rule, $ladder, $exempt );
		$got  = ! empty( $hits );
		$ok   = ( $got === $should );
		printf( "%-8s %s\n", $ok ? 'ok' : 'BROKEN', $why );
		if ( ! $ok ) {
			$bad++;
		}
	}
	echo "\n" . ( $bad ? "$bad case(s) wrong; this checker cannot be trusted.\n" : "the checker rejects what it should and accepts what it should.\n" );
	exit( $bad ? 1 : 0 );
}

/* --------------------------------------------------------------------------
 * The real sweep.
 * ----------------------------------------------------------------------- */
$hits = offenders( $css, $ladder, $exempt );

echo "Type scale sweep\n";
echo "checked: every rule in portal.css that sets a font-size AND a font-weight,\n";
echo "         against the seven steps in DESIGN.md; chrome is exempt by name\n\n";

if ( $hits ) {
	echo 'OFF THE LADDER: ' . count( $hits ) . "\n";
	foreach ( $hits as $h ) {
		printf( "  . %spx / %d  %s\n", $h[1], $h[2], $h[0] );
	}
	echo "\nEach one renders between two steps, so the elements above and below it stop\n";
	echo "reading as a hierarchy. Move it onto a step or exempt it by name with a reason.\n";
	exit( 1 );
}
echo "every size-and-weight pair in portal.css is a step on the ladder.\n";
