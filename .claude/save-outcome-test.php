<?php
/**
 * WHAT A SAVE DOES: IT SAVES, AND IT DOES NOT ASK AGAIN.
 *
 *     php .claude/save-outcome-test.php
 *
 * THE FINDING THIS FILE EXISTS TO ANSWER, WHICH IS NOT THE BUG.
 *
 * Three releases running, a test on this screen has asserted something other
 * than the behaviour that matters.
 *
 *   3.31.x  the embed tests asserted both panels were built and neither was
 *           marked hidden, then that the visible cards could be counted. Both
 *           passed while the mode showed nothing, and then while every card was
 *           a 40px strip. Counting is not looking.
 *   3.39.0  the scope work asserted that data-uc-scope-confirm no longer
 *           appears and that dismiss() compares paths. Both were true, and both
 *           stayed true, while the dialog went on appearing after every save,
 *           because the second ask was never on the save button: it was on the
 *           page the save redirects to.
 *   3.40.0  every assertion about the event editor was a grep over the SOURCE.
 *           Meanwhile the cancel card, rendered into a column that is echoed
 *           inside the event form, put uc_action=cancel_event and its matching
 *           nonce into that form. The fault does not exist in the source. It
 *           exists only once a parser has read it, and every save cancelled the
 *           event and emailed everybody registered.
 *
 * The common shape is not "the tests were too weak". It is that each one
 * asserted a PROPERTY OF THE CODE believed to imply the outcome, and never the
 * outcome. "The string is gone" implies nothing about what a manager sees. "The
 * method returns early" implies nothing about what the browser posts. So the two
 * assertions below are written as outcomes, in the words the brief used, and
 * each is decided by working out what happens rather than by finding a string:
 *
 *   1. A save leaves the event published and uncancelled.
 *   2. A save does not present a second scope question.
 *
 * SEE ALSO .claude/form-nesting-test.php, which owns the structural rule: no
 * form inside a form, one action per form. This file owns the outcome. They
 * overlap on purpose. The structural rule is the cheap one to keep; the outcome
 * is the one that was actually promised.
 */

$root  = dirname( __DIR__ );
$fails = array();

/* ---------------------------------------------------------------------------
 * COMMENTS FIRST. A sweep matching its own explanatory prose has happened four
 * times on this project, and the comment above the cancel card in the editor
 * contains the literal strings <form> and </form>.
 * ------------------------------------------------------------------------ */
$src = file_get_contents( $root . '/includes/class-sfaf-portal.php' );
$src = preg_replace( '#/\*.*?\*/#s', '', $src );
$src = preg_replace( '#^\s*//.*$#m', '', $src );

$js = file_get_contents( $root . '/public/js/portal.js' );

function body_of( $src, $name ) {
	if ( ! preg_match( '#\n    (?:public |private |protected )?(?:static )?function ' . preg_quote( $name, '#' ) . '\s*\(.*?\)\s*\{#s', $src, $m, PREG_OFFSET_CAPTURE ) ) {
		return '';
	}
	$start = $m[0][1];
	$open  = strpos( $src, '{', $start );
	$depth = 0;
	for ( $i = $open, $n = strlen( $src ); $i < $n; $i++ ) {
		if ( '{' === $src[ $i ] ) {
			$depth++;
		}
		if ( '}' === $src[ $i ] ) {
			$depth--;
			if ( 0 === $depth ) {
				return substr( $src, $start, $i - $start + 1 );
			}
		}
	}
	return '';
}

/* ===========================================================================
 * 1. A SAVE LEAVES THE EVENT PUBLISHED AND UNCANCELLED.
 *
 * Decided by working out what the browser posts, the way the browser works it
 * out, and then asking what PHP would make of it.
 * ======================================================================== */
echo "A save leaves the event published and uncancelled\n";

$editor = body_of( $src, 'render_event_form' );
if ( '' === $editor ) {
	$fails[] = 'render_event_form() not found, so nothing below is decided';
}

/**
 * The name/value pairs a browser would submit from the event form.
 *
 * FORMS DO NOT NEST, and that is the whole mechanism. A parser meeting a <form>
 * start tag inside an open form drops the tag and keeps the children, so every
 * input a nested renderer emits belongs to the OUTER form. This flattens the
 * same way: the region between the event form's <form> and its </form>, with the
 * body of every $this->render_*() called inside it spliced in where it is
 * called, so the result is in document order.
 */
function submitted_pairs( $src, $editor ) {
	$open  = strpos( $editor, '<form' );
	$close = strpos( $editor, '</form>', (int) $open );
	if ( false === $open || false === $close ) {
		return null;
	}
	$region = substr( $editor, $open, $close - $open );

	$flat = preg_replace_callback(
		'#\$this->(render_[a-z_]+)\s*\(#',
		function ( $m ) use ( $src ) {
			return body_of( $src, $m[1] );
		},
		$region
	);

	$pairs = array();
	if ( preg_match_all( '#name="([a-z_]+)"\s+value="([a-z_]+)"#i', $flat, $mm, PREG_SET_ORDER ) ) {
		foreach ( $mm as $m ) {
			$pairs[] = array( $m[1], $m[2] );
		}
	}
	if ( preg_match_all( "#wp_nonce_field\(\s*'([a-z_]+)'#", $flat, $mm ) ) {
		foreach ( $mm[1] as $n ) {
			$pairs[] = array( 'uc_nonce', $n );
		}
	}
	return $pairs;
}

$pairs = submitted_pairs( $src, $editor );

if ( null === $pairs ) {
	$fails[] = 'the event form could not be located, so what a save posts is unknown';
} else {
	/*
	 * PHP TAKES THE LAST VALUE OF A REPEATED KEY. That single rule is what
	 * turned a nested form into a destructive one, so it is applied here
	 * rather than described.
	 */
	$post = array();
	foreach ( $pairs as $p ) {
		$post[ $p[0] ] = $p[1];
	}

	$action = isset( $post['uc_action'] ) ? $post['uc_action'] : '(none)';
	$nonce  = isset( $post['uc_nonce'] ) ? $post['uc_nonce'] : '(none)';

	printf( "posts:    uc_action=%s, uc_nonce=%s\n", $action, $nonce );

	if ( 'save_event' !== $action ) {
		$fails[] = sprintf(
			'pressing Save on the event editor posts uc_action=%s, not save_event. Whatever that action does is what a save does, and the save itself never runs.',
			$action
		);
	}
	if ( 'uc_portal_save_event' !== $nonce ) {
		$fails[] = sprintf(
			'the event form carries the %s nonce. A nonce matching an action nobody chose is what lets the wrong action pass its security check instead of failing.',
			$nonce
		);
	}

	$actions = 0;
	foreach ( $pairs as $p ) {
		if ( 'uc_action' === $p[0] ) {
			$actions++;
		}
	}
	if ( $actions > 1 ) {
		$fails[] = sprintf( 'the event form submits %d uc_action values. Only the last one has any effect, and which one is last is an accident of render order.', $actions );
	}

	/*
	 * THE FIRST SUBMIT BUTTON IS THE ENTER KEY. A browser's implicit
	 * submission activates the first submit button in the form, so whatever
	 * that button does is what pressing Enter in a text field does. It must
	 * not be a button that unpublishes.
	 *
	 * THE PHP IS TAKEN OUT FIRST, AND 3.97.1 IS WHY. This matched with
	 * `[^>]*` between the attributes, and `[^>]*` cannot cross the `>` in
	 * `?>`. So it only ever matched a button whose whole tag was literal, and
	 * for four releases that was PUBLISH, not Save draft: it reported the
	 * value of whichever button its pattern could parse and called it the
	 * first one. The moment Publish gained a `form="<?php … ?>"` attribute,
	 * nothing matched at all and this failed on a form that was correct.
	 *
	 * Replacing each PHP block with a placeholder that contains no angle
	 * bracket leaves the attribute ORDER intact and lets the scan see every
	 * button, which is what "the first one" was supposed to mean.
	 */
	$flat = preg_replace( '#<\?php.*?\?>#s', '{PHP}', $editor );
	if ( preg_match( '#<button[^>]*type="submit"[^>]*name="save_mode"[^>]*value="([^"]*)"#', $flat, $m ) ) {
		$first = $m[1];
		if ( false !== strpos( $first, 'draft' ) && false === strpos( $first, 'keep' ) ) {
			$fails[] = 'the first submit button in the event form posts save_mode=draft unconditionally. That is the button a browser presses when somebody hits Enter in a text field, so Enter would take a published event off the calendar.';
		}
	} else {
		$fails[] = 'no save_mode submit button was found in the event form, so which button Enter would activate is unknown';
	}

	/*
	 * AND EVERY save_mode BUTTON NAMES THE EVENT FORM (3.97.1).
	 *
	 * The row is outside the form, so each of these is associated by its
	 * `form=` attribute and by nothing else. Publish and Submit for Review
	 * went without one from 3.94.0 to 3.97.0 and did nothing when pressed.
	 * Counted, not found: two of three carrying it is the shape that shipped.
	 */
	$save_buttons = preg_match_all( '#<button[^>]*name="save_mode"[^>]*>#', $flat, $sb );
	$with_form    = 0;
	foreach ( $sb[0] as $tag ) {
		if ( false !== strpos( $tag, 'form=' ) ) { $with_form++; }
	}
	if ( $save_buttons < 1 ) {
		$fails[] = 'the event form emits no save_mode button at all';
	} elseif ( $with_form !== $save_buttons ) {
		$fails[] = sprintf(
			'%d of the %d save_mode buttons carry a form attribute. The row is outside the form, so one without it has no form owner and does nothing when pressed.',
			$with_form,
			$save_buttons
		);
	}
}

/*
 * AND THE SAVE PATH ITSELF DOES NOT UNPUBLISH OR CANCEL. There are two ways to
 * end up cancelled by a save: dispatching to the wrong action, tested above, or
 * the right action doing it. This is the second.
 */
$save = body_of( $src, 'save_event_from_post' );
if ( '' === $save ) {
	$fails[] = 'save_event_from_post() not found';
} else {
	/*
	 * THE STATUS RULE IS NOT "NEVER TOUCH IT". The editor has Publish and
	 * Save Draft buttons and they are meant to work. The rule is that a save
	 * only ever applies a status somebody CHOSE, so a save that names no mode
	 * has to leave the event exactly as it found it.
	 *
	 * Written as "never touch post_status" first, this failed on the real
	 * code and the failure was the finding: the fallback was 'draft', so a
	 * save arriving without a save_mode took a published event off the public
	 * calendar, and "Save Draft" was the first submit button in the form,
	 * which is the one a browser activates on Enter.
	 */
	if ( ! preg_match( "#\\\$save_mode\s*=\s*isset\(\s*\\\$_POST\['save_mode'\]\s*\).*?:\s*'keep'#s", $save ) ) {
		$fails[] = 'a save that names no save_mode does not fall back to keep. Any other fallback applies a status nobody chose, and the one that was there took published events off the calendar.';
	}
	if ( ! preg_match( "#\\\$existing\s*=\s*\\\$event_id\s*\?\s*get_post_status\(\s*\\\$event_id\s*\)#", $save ) ) {
		$fails[] = 'the keep mode does not read the status the event already has, so it cannot keep it';
	}
	if ( preg_match( '#_uc_cancelled#', $save ) || preg_match( '#SFAF_Cancellation::(cancel|set)#', $save ) ) {
		$fails[] = 'save_event_from_post() writes cancellation state. Cancelling is its own action with its own button.';
	}
	if ( preg_match( '#SFAF_Announce::cancelled#', $save ) ) {
		$fails[] = 'save_event_from_post() sends the cancellation email. A save must not tell anybody the event is off.';
	}
}

/* The dispatcher's save_event case reaches the save and nothing else. */
$dispatch = body_of( $src, 'dispatch_post' );
if ( ! preg_match( "#case 'save_event':(.*?)break;#s", $dispatch, $m ) ) {
	$fails[] = 'the save_event case was not found in dispatch_post(), so this check is blind';
} else {
	$case = $m[1];
	if ( false === strpos( $case, 'save_event_from_post' ) ) {
		$fails[] = 'the save_event case does not call save_event_from_post()';
	}
	if ( preg_match( '#cancel#i', $case ) ) {
		$fails[] = 'the save_event case reaches cancellation';
	}
}

/* ===========================================================================
 * 2. A SAVE DOES NOT PRESENT A SECOND SCOPE QUESTION.
 *
 * Answered once, and the answer survives the round trip. Every clause below is
 * a link in that chain, and the chain is only as good as its weakest link,
 * which is why they are all here and not only the one that broke.
 * ======================================================================== */
echo "A save does not present a second scope question\n";

/* a. The save knows which scope it used and says so. */
if ( '' !== $save && ! preg_match( "#'scope'\s*=>\s*\\\$scope#", $save ) ) {
	$fails[] = 'save_event_from_post() does not return the scope it applied, so nothing downstream can know it';
}

/* b. The redirect carries it. */
if ( ! preg_match( "#'edit_scope'\s*=>\s*\\\$result\['scope'\]#", $dispatch ) ) {
	$fails[] = 'the save redirect does not carry edit_scope, so the editor it lands on cannot know the question was answered and asks it again';
}

/* c. And it is edit_scope, not scope, which already means something else here. */
if ( preg_match( "#'scope'\s*=>\s*\\\$result\['scope'\]#", $dispatch ) ) {
	$fails[] = 'the recurrence answer is carried in the scope parameter, which this portal already uses for the mine/all filter. One name, two meanings, on one screen.';
}

/* d. The header reads it, and there is one reader. */
$reader = body_of( $src, 'carried_edit_scope' );
if ( '' === $reader ) {
	$fails[] = 'carried_edit_scope() not found';
} elseif ( ! preg_match( "#in_array\(.*?'this'.*?'all_upcoming'#s", $reader ) ) {
	$fails[] = 'carried_edit_scope() does not check the value against the two real scopes before using it';
}

$header = body_of( $src, 'render_scope_header' );
if ( '' === $header ) {
	$fails[] = 'render_scope_header() not found';
} else {
	if ( false === strpos( $header, 'carried_edit_scope' ) ) {
		$fails[] = 'render_scope_header() does not read the carried answer, so it puts the question again after every save';
	}
	/* Hidden block, open banner: the answered state, drawn by the server. */
	/* Anchored on the one expression that emits it. An unanchored search with
	 * .*? found the `hidden` on the dismiss row forty lines further down and
	 * passed while the panel rendered wide open, which is the same class of
	 * mistake this whole file is about. */
	if ( ! preg_match( '#data-uc-scope-answered="\' \. esc_attr\( \$answered \) \. \'" hidden\'#', $header ) ) {
		$fails[] = 'the scope block is not hidden when the answer is already known, so the panel is still on the page asking';
	}
	if ( ! preg_match( '#data-uc-scope-banner<\?php echo \$answered#', $header ) ) {
		$fails[] = 'the banner is not opened by the server on an answered load, so with scripting off nothing states which scope is in force';
	}
	if ( ! preg_match( '#name="edit_scope" value="<\?php echo esc_attr\( \$answered#', $header ) ) {
		$fails[] = 'the hidden edit_scope field does not carry the answer forward, so a second save from an answered load would silently fall back to "this"';
	}
}

/* e. The fieldset does not arrive locked on an answered load. A form nobody can
 *    type into is the question by another means. */
if ( '' !== $editor && ! preg_match( "#\\\$scope_locked\s*=\s*\\\$has_bulk\s*&&\s*''\s*===\s*\\\$this->carried_edit_scope\(\)#", $editor ) ) {
	$fails[] = 'the scope fieldset is disabled regardless of whether the question has been answered, so an answered load still cannot be edited without a script';
}

/* f. The script applies a carried answer instead of opening the dialog. */
if ( false === strpos( $js, "getAttribute('data-uc-scope-answered')" ) ) {
	$fails[] = 'portal.js does not read the carried answer, so it opens the dialog on a load that arrived already answered';
} else {
	/* Order matters: applying the answer has to come BEFORE openModal(), and
	 * has to return, or the dialog opens anyway. */
	$apply = strpos( $js, "getAttribute('data-uc-scope-answered')" );
	$modal = strpos( $js, 'openModal();', $apply );
	$ret   = strpos( $js, 'return;', $apply );
	if ( false === $ret || false === $modal || $ret > $modal ) {
		$fails[] = 'portal.js reads the carried answer but does not return before openModal(), so the question is asked anyway';
	}
}

/* g. Change reopens the question, which it cannot do while the URL still
 *    answers it. */
if ( false === strpos( $js, 'edit_scope=[^&]' ) ) {
	$fails[] = 'the Change control does not strip edit_scope before reloading, so it re-applies the answer it was pressed to change and the question can never be reopened';
}

/* h. The 3.39.0 removal stays removed. It was not the cause, and it was also
 *    not wrong. */
if ( false !== strpos( $js, 'data-uc-scope-confirm' ) || false !== strpos( $src, 'data-uc-scope-confirm' ) ) {
	$fails[] = 'the confirmation on the save buttons is back. The banner already states the scope permanently, two inches above the button.';
}

/* ------------------------------------------------------------------------ */
echo "\nSave outcome test\n";
echo "checked: what the event form actually posts once forms are flattened and PHP\n";
echo "         has taken the last of every repeated key; which button the Enter key\n";
echo "         would press; that a save naming no mode keeps the status the event\n";
echo "         already has and writes no cancellation; and every link in the chain\n";
echo "         that carries an answered scope through a save without re-asking\n\n";

if ( $fails ) {
	echo 'FAIL: ' . count( $fails ) . "\n";
	foreach ( $fails as $f ) {
		echo "  . $f\n";
	}
	exit( 1 );
}
echo "a save saves, keeps the event published and uncancelled, and asks nothing twice.\n";
