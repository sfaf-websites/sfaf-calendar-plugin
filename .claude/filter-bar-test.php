<?php
/**
 * The filter bar: four kinds of control, and the cascade that decides whether
 * any of it renders.
 *
 * WHY THIS IS A SCRIPT AND NOT A LOOK IN A BROWSER. Four of the six faults
 * this area has had were EMBED-ONLY: they looked right on the calendar site
 * and were dead on sfaf.org, because a component rule at one class lost to a
 * host base rule at one class plus one element, or because a `@media` query
 * about the WINDOW never fired in a 280px column of a 1440px page. Neither is
 * visible from here and neither is visible from the calendar site either. Both
 * are decidable from the source, so they are decided here.
 *
 * THREE THINGS ARE CHECKED.
 *
 *   1. THE GLYPHS ARE ELEMENTS. The search magnifier and the two chevrons are
 *      markup, not `background-image`. A theme's `background: #fff` resets
 *      background-image to none, which is how the select arrow was lost in
 *      3.18.0, and an affordance that can be deleted by a rule nobody wrote
 *      about us is not an affordance.
 *
 *   2. EVERY CONTROL OUTRANKS THE RESETS. `.uc-calendar button` is (0,1,1) and
 *      declares `min-height: 0` and `box-shadow: none`. A host's bare
 *      `input[type="search"]` or `.entry-content select` is (0,1,1) too. Any
 *      rule of ours that claims one of those properties on one of these
 *      controls must be at least (0,2,0) or it is dead without looking dead.
 *
 *   3. NOTHING ABOUT THIS BAR ASKS THE WINDOW HOW WIDE IT IS. The bar is
 *      inside a query container and must ask that.
 *
 * Run: php .claude/filter-bar-test.php
 *      php .claude/filter-bar-test.php --self-test
 */

$root = dirname( __DIR__ );
$self = in_array( '--self-test', $argv, true );

$css_path = $root . '/public/css/calendar.css';
$php_path = $root . '/includes/class-sfaf-shortcodes.php';
$css      = file_get_contents( $css_path );
$php      = file_get_contents( $php_path );

if ( false === $css || false === $php ) {
	fwrite( STDERR, "cannot read the sources\n" );
	exit( 1 );
}

/* ---------------------------------------------------------------------------
 * Specificity, computed rather than eyeballed.
 *
 * `:where()` contributes zero and is how this file writes its floors, so it
 * has to be honoured or the floors read as failures. `:has()` and `:is()` take
 * the specificity of their most specific argument. Combinators contribute
 * nothing. Returned as a comparable integer, a*100 + b*10 + c, which is safe
 * because nothing here comes near ten of anything.
 * ------------------------------------------------------------------------ */
function spec( $sel ) {
	$sel = trim( $sel );

	// :where(...) is zero. Strip it whole, innermost first.
	while ( preg_match( '/:where\(/i', $sel ) ) {
		$sel = strip_functional( $sel, 'where', true );
	}
	// :has(...) and :is(...) and :not() take their most specific argument.
	foreach ( array( 'has', 'is', 'not', 'matches' ) as $fn ) {
		while ( preg_match( '/:' . $fn . '\(/i', $sel ) ) {
			$sel = strip_functional( $sel, $fn, false );
		}
	}

	$a = preg_match_all( '/#[A-Za-z0-9_-]+/', $sel );
	// Classes, attribute selectors and pseudo-classes.
	$b  = preg_match_all( '/\.[A-Za-z0-9_-]+/', $sel );
	$b += preg_match_all( '/\[[^\]]+\]/', $sel );
	$b += preg_match_all( '/(?<!:):(?!:)[A-Za-z-]+/', $sel );
	// Elements and pseudo-elements.
	$stripped = preg_replace( '/(\.[A-Za-z0-9_-]+|#[A-Za-z0-9_-]+|\[[^\]]+\]|:{1,2}[A-Za-z-]+)/', ' ', $sel );
	$c        = preg_match_all( '/(?<![A-Za-z0-9_-])[A-Za-z][A-Za-z0-9]*/', $stripped );
	$c       += preg_match_all( '/::[A-Za-z-]+/', $sel );

	return ( $a * 100 ) + ( $b * 10 ) + $c;
}

/**
 * Remove one functional pseudo-class, either dropping its argument entirely
 * (`:where()`) or replacing the whole thing with its most specific argument.
 */
function strip_functional( $sel, $fn, $zero ) {
	$at = stripos( $sel, ':' . $fn . '(' );
	if ( false === $at ) {
		return $sel;
	}
	$open  = $at + strlen( $fn ) + 2;
	$depth = 1;
	$i     = $open;
	$len   = strlen( $sel );
	while ( $i < $len && $depth > 0 ) {
		if ( '(' === $sel[ $i ] ) {
			$depth++;
		} elseif ( ')' === $sel[ $i ] ) {
			$depth--;
		}
		$i++;
	}
	$inner = substr( $sel, $open, $i - $open - 1 );
	if ( $zero ) {
		$replace = ' ';
	} else {
		$best = 0;
		$rep  = '';
		foreach ( explode( ',', $inner ) as $arg ) {
			$s = spec( $arg );
			if ( $s >= $best ) {
				$best = $s;
				$rep  = $arg;
			}
		}
		$replace = ' ' . $rep . ' ';
	}
	return substr( $sel, 0, $at ) . $replace . substr( $sel, $i );
}

/**
 * Every rule in the stylesheet, as selector => declaration block, comments and
 * at-rule wrappers removed. Nested blocks (@media, @container) are flattened,
 * which is right for this: a query changes WHEN a rule applies, never how
 * strongly, and that is the trap being tested for.
 */
function rules( $css ) {
	$css = preg_replace( '#/\*.*?\*/#s', '', $css );
	$out = array();
	// Drop the at-rule headers but keep their contents in the stream.
	$css = preg_replace( '/@(media|container|supports)[^{]*\{/i', '', $css );
	if ( preg_match_all( '/([^{}]+)\{([^{}]*)\}/', $css, $m, PREG_SET_ORDER ) ) {
		foreach ( $m as $one ) {
			$sel = trim( $one[1] );
			if ( '' === $sel || '@' === $sel[0] ) {
				continue;
			}
			$out[] = array( 'sel' => $sel, 'body' => $one[2] );
		}
	}
	return $out;
}

$fail = array();
$note = array();

/* -------------------------------------------------------------------------
 * 1. THE GLYPHS ARE ELEMENTS.
 * ---------------------------------------------------------------------- */
/*
 * uc-select-chevron IS GONE WITH THE SELECT IT POINTED AT (3.85.0). The
 * organizer dropdown and the groups disclosure are one control now, so there is
 * one chevron rather than two and it belongs to the combined trigger. The claim
 * is unchanged and is the reason this list exists: a glyph is an ELEMENT drawn
 * by sfaf_icon(), never a background-image, because the recorded way an
 * affordance disappears on a host page is a `background:` shorthand in the
 * theme resetting it to none. That is how the select arrow was lost in 3.18.0.
 *
 * uc-groups-chevron stays: render_group_row() still exists and still draws it,
 * it simply has no caller this release. If that method goes, this line goes
 * with it.
 */
$glyphs = array(
	'uc-search-icon'     => "the search field's magnifier",
	'uc-who-chevron'     => "the organizers and groups trigger's chevron",
	'uc-groups-chevron'  => "the folded groups list's chevron",
);
foreach ( $glyphs as $class => $what ) {
	if ( ! preg_match( "/sfaf_icon\(\s*'[a-z]+'\s*,\s*array\(\s*'class'\s*=>\s*'" . preg_quote( $class, '/' ) . "'/", $php ) ) {
		$fail[] = "$what is not rendered by sfaf_icon() as an element";
	}
}
if ( preg_match( '/\.(uc-search|uc-organizer-select)[^{}]*\{[^{}]*background-image/s', $css ) ) {
	$fail[] = 'a control on the filter bar draws an affordance with background-image, which a theme can reset to none';
}

/* -------------------------------------------------------------------------
 * 2. THE CATEGORY DOT AND THE GROUP TICK, WHICH ARE WHAT MAKE THE TWO PILL
 *    ROWS DIFFERENT KINDS OF THING RATHER THAN THE SAME THING TWICE.
 *
 *    "All Events" must NOT carry a dot: it is not a category, and a dot there
 *    would claim it is. Checked by position, because both buttons are printed
 *    by the same renderer a few lines apart.
 * ---------------------------------------------------------------------- */
/*
 * NOT `[^>]*`, WHICH IS WHAT THIS SAID AND WHY IT WENT VACUOUS ON ITS FIRST
 * RUN. The attributes between `data-category="all"` and the label are PHP
 * short echoes, and `?>` puts a `>` inside the tag, so a "no closing angle
 * bracket yet" match ended early and found nothing. A not-found is a FAILURE
 * rather than a note: this button is not optional, so if it cannot be located
 * the test has stopped testing and must say so rather than pass quietly.
 */
/*
 * FOUND BY OFFSET RATHER THAN BY A REGEX ACROSS THE WHOLE FILE (3.75.0).
 *
 * The pattern this replaces worked and then stopped, without the thing it
 * checks changing at all: `(?:(?!<button\b).)*?` followed by `.*?</button>`
 * over a five-thousand-line file exhausts PCRE's JIT stack once the file grows
 * past some size nobody can predict, and preg_match() returns FALSE. The test
 * read that as "not found" and failed, correctly by its own rules and for
 * entirely the wrong reason.
 *
 * SO THE SEARCH IS STRING WORK, WHICH CANNOT BACKTRACK. Find the attribute,
 * walk back to the `<button` that opens it and forward to the `</button>` that
 * closes it. The question is unchanged and the answer no longer depends on how
 * long the file happens to be.
 */
$all_at = strpos( $php, 'data-category="all"' );
if ( false === $all_at ) {
	$fail[] = 'the All Events button could not be located, so the dot check is not running';
} else {
	$open  = strrpos( substr( $php, 0, $all_at ), '<button' );
	$close = strpos( $php, '</button>', $all_at );
	if ( false === $open || false === $close ) {
		$fail[] = 'the All Events button is not inside a <button> element, so the dot check is not running';
	} elseif ( false !== strpos( substr( $php, $open, $close - $open ), 'uc-filter-dot' ) ) {
		$fail[] = 'the All Events button carries a category dot, and it is not a category';
	}
}
if ( ! preg_match( '/style="--cat-color.*?uc-filter-dot/s', $php ) ) {
	$fail[] = 'the category buttons do not carry a dot reading --cat-color';
}
if ( ! preg_match( '/uc-group-pill.*?uc-group-tick/s', $php ) ) {
	$fail[] = 'the group pills do not carry a tick';
}
if ( ! preg_match( '/\.uc-calendar \.uc-group-pill\.active \.uc-group-tick\s*\{[^}]*display:\s*block/', $css ) ) {
	$fail[] = 'the tick is never revealed on a chosen group pill';
}

/* -------------------------------------------------------------------------
 * 3. THE CASCADE. Every rule of ours that claims one of the properties a
 *    reset also claims, on one of these controls, must outrank (0,1,1).
 * ---------------------------------------------------------------------- */
$controls = array(
	'uc-search',
	'uc-organizer-select',
	'uc-filter-btn',
	'uc-group-pill',
	'uc-group-clear',
	'uc-filters',
	'uc-groups',
);
// What the button reset and a plausible host base rule both declare.
$contested = array(
	'min-height', 'box-shadow', 'background', 'background-color', 'border',
	'border-color', 'border-radius', 'padding', 'width', 'font-family',
	'line-height', 'text-transform', 'letter-spacing', 'margin', 'appearance',
);
$RESET = 11; // (0,1,1)

foreach ( rules( $css ) as $rule ) {
	foreach ( explode( ',', $rule['sel'] ) as $sel ) {
		$sel = trim( $sel );
		if ( '' === $sel ) {
			continue;
		}
		// Only the rules that are ABOUT one of these controls, and only where
		// the control is the subject rather than an ancestor.
		$hit = '';
		foreach ( $controls as $c ) {
			if ( preg_match( '/\.' . preg_quote( $c, '/' ) . '(?![A-Za-z0-9_-])(?![^,]*\s[.\[a-zA-Z])/', $sel ) ) {
				$hit = $c;
				break;
			}
		}
		if ( '' === $hit ) {
			continue;
		}
		$s = spec( $sel );
		foreach ( $contested as $prop ) {
			if ( ! preg_match( '/(^|;|\s)' . preg_quote( $prop, '/' ) . '\s*:/i', $rule['body'] ) ) {
				continue;
			}
			if ( $s <= $RESET ) {
				$fail[] = sprintf(
					'%s declares %s at specificity %d and loses to a (0,1,1) reset or host base rule',
					$sel,
					$prop,
					$s
				);
			}
		}
	}
}

/* -------------------------------------------------------------------------
 * 4. NOTHING ABOUT THIS BAR ASKS THE WINDOW.
 * ---------------------------------------------------------------------- */
$stripped = preg_replace( '#/\*.*?\*/#s', '', $css );
if ( preg_match_all( '/@media[^{]*\((?:max|min)-width[^{]*\{(.*?)\n\}/s', $stripped, $m ) ) {
	foreach ( $m[1] as $block ) {
		foreach ( array( 'uc-filters', 'uc-search-wrap', 'uc-organizer-filter', 'uc-filter-buttons', 'uc-groups' ) as $c ) {
			if ( preg_match( '/\.' . preg_quote( $c, '/' ) . '(?![A-Za-z0-9_-])/', $block ) ) {
				$fail[] = ".$c is laid out inside a WINDOW media query; an embed in a narrow column never reaches it";
			}
		}
	}
}
$has_container = (bool) preg_match( '/@container\s+uc-calendar[^{]*\{[^@]*?\.uc-calendar \.uc-filters/s', $stripped );
if ( ! $has_container ) {
	$fail[] = 'the filter bar has no @container rule, so it does not measure its own column at all';
}

/* -------------------------------------------------------------------------
 * SELF-TEST. A checker that has never failed is a checker nobody has reason
 * to believe, and two of these have silently passed everything before.
 * ---------------------------------------------------------------------- */
if ( $self ) {
	$checks = array(
		'.uc-filter-btn'                          => 10,
		'.uc-calendar .uc-filter-btn'             => 20,
		'.uc-calendar button'                     => 11,
		'input[type="search"]'                    => 11,
		':where(.uc-calendar) button'             => 1,
		'.uc-calendar .uc-filters:has(+ .uc-groups)' => 30,
		'.uc-groups-list[open] > summary .uc-groups-chevron' => 31,
		'.uc-organizer-filter:focus-within .uc-select-chevron' => 30,
		'.uc-calendar .uc-search:focus-visible'   => 30,
		// One class, then TWO elements: `li` and the pseudo-element. This
		// expectation was written as 21 and the checker said 12, which was the
		// checker being right; left here as the case that proves the
		// pseudo-element is counted once, in c, and not again in b.
		'.uc-calendar li::before'                 => 12,
	);
	$bad = 0;
	echo "self-test: specificity\n";
	foreach ( $checks as $sel => $want ) {
		$got = spec( $sel );
		$ok  = ( $got === $want );
		if ( ! $ok ) {
			$bad++;
		}
		printf( "  %s  %-52s want %3d  got %3d\n", $ok ? 'ok  ' : 'FAIL', $sel, $want, $got );
	}
	echo $bad ? "\nself-test FAILED: $bad wrong\n" : "\nself-test passed: specificity is computed, not guessed.\n";
	exit( $bad ? 1 : 0 );
}

/* ---------------------------------------------------------------------- */

echo "The filter bar: four kinds of control, and the cascade under them\n";
echo str_repeat( '=', 74 ) . "\n\n";
printf( "glyphs:   %d drawn as elements, none as a background a theme can reset\n", count( $glyphs ) );
printf( "marks:    a category dot reading --cat-color, no dot on All Events, a tick on a chosen group\n" );
printf( "cascade:  every rule on %d controls checked against (0,1,1), our button reset and a host base rule\n", count( $controls ) );
printf( "width:    the bar asks its own column through @container uc-calendar, never the window\n\n" );

foreach ( $note as $n ) {
	echo "note: $n\n";
}
if ( $note ) {
	echo "\n";
}

if ( $fail ) {
	echo count( $fail ) . " problem(s):\n";
	foreach ( $fail as $f ) {
		echo "  . $f\n";
	}
	exit( 1 );
}

echo "the four control kinds are distinguishable in the markup, and every rule that\n";
echo "says so outranks the resets it has to survive.\n";
exit( 0 );
