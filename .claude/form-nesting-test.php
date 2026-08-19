<?php
/**
 * NO FORM INSIDE ANOTHER FORM, AND ONE ACTION PER FORM.
 *
 * WHY THIS EXISTS, AND WHY IT IS THE THIRD TIME.
 *
 * 3.38.0 rendered the cancel card into the event editor's side column. That card
 * emits its own <form>, and the side column is echoed inside the event <form>.
 * HTML forbids nested forms: every parser drops the inner start tag and keeps
 * its children, so the cancel form's inputs joined the event form and the
 * browser posted
 *
 *     uc_action=save_event & ... & uc_action=cancel_event
 *     uc_nonce=[save]      & ... & uc_nonce=[cancel]
 *
 * PHP takes the LAST value of a repeated key, so every Save posted
 * uc_action=cancel_event with the matching cancel nonce. The nonce check passed,
 * because both halves came from the same card. Every save cancelled the event,
 * emailed everybody registered that it was cancelled, and never ran the save.
 *
 * THE SUITE PASSED THROUGHOUT, AND THAT IS THE FINDING.
 *
 * Every existing assertion about the event editor is a grep over the SOURCE:
 * "does this string appear", "does that method return early". Not one of them
 * looks at the markup the browser receives, and this fault does not exist in the
 * source at all. It exists only once a parser has read it. Three releases running
 * a test here has asserted something other than the behaviour that matters, so
 * this file asserts the shape of the document rather than the shape of the file.
 *
 *     php .claude/form-nesting-test.php
 *
 * HOW IT WORKS. It slices each renderer that opens a <form>, follows the
 * $this->render_*() calls made between that form's open and close, and fails if
 * any of them opens a form of its own. That is the nesting, one level deep,
 * which is as deep as this codebase's renderers go. It then counts uc_action and
 * uc_nonce inputs contributed to each form, because two of either is the same
 * fault arriving by another route.
 */

$root = dirname( __DIR__ );

/*
 * COMMENTS FIRST, BEFORE ANY SLICING.
 *
 * The comment in render_event_form() explaining why the cancel card must not be
 * nested contains the literal strings "<form>" and "</form>", so reading the
 * file as written sliced the event form closed at that comment and reported the
 * access card, which is called after it, as missing.
 *
 * A sweep matching its own explanatory prose is the fourth instance of that on
 * this project, which is why it is the first thing here. Only lines that BEGIN
 * with // are stripped, so a "https://" inside a string survives.
 */
$src = file_get_contents( $root . '/includes/class-sfaf-portal.php' );
$src = preg_replace( '#/\*.*?\*/#s', '', $src );
$src = preg_replace( '#^\s*//.*$#m', '', $src );

$fails = array();

/* ---------------------------------------------------------------------------
 * Slice a method body by brace counting.
 * ------------------------------------------------------------------------ */
function method_body( $src, $name ) {
    if ( ! preg_match( '#\n    (?:public |private |protected )?(?:static )?function ' . preg_quote( $name, '#' ) . '\s*\(.*?\)\s*\{#s', $src, $m, PREG_OFFSET_CAPTURE ) ) {
        return '';
    }
    $start = $m[0][1];
    $open  = strpos( $src, '{', $start );
    $depth = 0;
    for ( $i = $open, $n = strlen( $src ); $i < $n; $i++ ) {
        if ( '{' === $src[ $i ] ) { $depth++; }
        if ( '}' === $src[ $i ] ) {
            $depth--;
            if ( 0 === $depth ) { return substr( $src, $start, $i - $start + 1 ); }
        }
    }
    return '';
}

/** Every method in the file that opens a <form>. */
function renderers_opening_a_form( $src ) {
    $out = array();
    if ( ! preg_match_all( '#\n    (?:public |private |protected )?(?:static )?function ([a-z_]+)\s*\(#', $src, $m ) ) {
        return $out;
    }
    foreach ( array_unique( $m[1] ) as $name ) {
        $body = method_body( $src, $name );
        if ( '' !== $body && preg_match( '#<form\b#', $body ) ) {
            $out[] = $name;
        }
    }
    return $out;
}

$form_renderers = renderers_opening_a_form( $src );

if ( count( $form_renderers ) < 5 ) {
    $fails[] = 'only ' . count( $form_renderers ) . ' form-rendering methods found; the parse is wrong and this test is blind';
}

/* ---------------------------------------------------------------------------
 * For each form, what is emitted between its open and its close.
 * ------------------------------------------------------------------------ */
echo "Forms and what they contain\n";

$checked = 0;

foreach ( $form_renderers as $name ) {
    $body = method_body( $src, $name );

    // Each <form ...> ... </form> region in this method.
    $offset = 0;
    while ( false !== ( $open = strpos( $body, '<form', $offset ) ) ) {
        $close = strpos( $body, '</form>', $open );
        if ( false === $close ) {
            break; // a form opened in one method and closed in another: not the shape here
        }
        $region = substr( $body, $open, $close - $open );
        $offset = $close + 7;
        $checked++;

        /*
         * THE NESTING CHECK. Any renderer called inside this region that opens a
         * form of its own puts a form inside a form.
         */
        if ( preg_match_all( '#\$this->(render_[a-z_]+)\s*\(#', $region, $calls ) ) {
            foreach ( array_unique( $calls[1] ) as $callee ) {
                if ( in_array( $callee, $form_renderers, true ) ) {
                    $fails[] = sprintf(
                        '%s() calls %s() between its <form> and </form>, and %s() opens a form. Forms cannot nest: the browser drops the inner tag and its inputs join the outer form, so the outer form posts the inner one\'s uc_action and uc_nonce.',
                        $name, $callee, $callee
                    );
                }
            }
        }

        /*
         * ONE ACTION AND ONE NONCE PER FORM, counting what the region emits
         * directly plus anything a called renderer contributes. Two of either is
         * the same fault arriving another way: an inner renderer that emits a
         * bare uc_action without a form of its own would be just as destructive.
         */
        $contributed = $region;
        if ( preg_match_all( '#\$this->(render_[a-z_]+)\s*\(#', $region, $calls ) ) {
            foreach ( array_unique( $calls[1] ) as $callee ) {
                if ( in_array( $callee, $form_renderers, true ) ) {
                    continue; // already reported above; its inputs are its own
                }
                $contributed .= "\n" . method_body( $src, $callee );
            }
        }

        $actions = preg_match_all( '#name="uc_action"#', $contributed );
        $nonces  = preg_match_all( "#wp_nonce_field\(#", $contributed );

        if ( $actions > 1 ) {
            $fails[] = sprintf(
                '%s(): a form is given %d uc_action inputs. PHP takes the LAST value of a repeated key, so this form posts whichever action is emitted last, not the one it was built for.',
                $name, $actions
            );
        }
        if ( $nonces > 1 ) {
            $fails[] = sprintf(
                '%s(): a form is given %d nonce fields. The last one wins, and if it matches the last uc_action the security check passes for an action nobody chose.',
                $name, $nonces
            );
        }
    }
}

printf( "checked:  %d form regions across %d renderers\n", $checked, count( $form_renderers ) );

/* ---------------------------------------------------------------------------
 * THE TWO CARDS THIS FAULT WAS ABOUT, NAMED.
 *
 * A general rule catches the class. These two are named because they are the
 * pair that actually broke, and because the fix was to move one of them: naming
 * it means moving it back fails here rather than on a live site.
 * ------------------------------------------------------------------------ */
echo "The cancel card is outside the event form\n";

$editor = method_body( $src, 'render_event_form' );
if ( '' === $editor ) {
    $fails[] = 'render_event_form() not found';
} else {
    $open  = strpos( $editor, '<form' );
    $close = strpos( $editor, '</form>', (int) $open );

    if ( false === $open || false === $close ) {
        $fails[] = 'the event form could not be located inside render_event_form()';
    } else {
        $inside = substr( $editor, $open, $close - $open );
        $after  = substr( $editor, $close );

        if ( false !== strpos( $inside, 'render_cancel_card' ) ) {
            $fails[] = 'render_cancel_card() is called INSIDE the event form again. It emits its own form, so every Save would post uc_action=cancel_event and cancel the event instead of saving it.';
        }
        if ( false === strpos( $after, 'render_cancel_card' ) ) {
            $fails[] = 'render_cancel_card() is not called after the event form closes, so the cancel control is missing from the editor';
        }

        /*
         * The access card is INSIDE the form and must stay there: it contributes
         * fields to the event save. What it must never do is grow a form.
         */
        if ( false === strpos( $inside, 'render_access_card' ) ) {
            $fails[] = 'render_access_card() is no longer inside the event form, so its team fields would not be saved with the event';
        }
        $access = method_body( $src, 'render_access_card' );
        if ( preg_match( '#<form\b#', $access ) ) {
            $fails[] = 'render_access_card() now opens a form, and it is rendered inside the event form. That is the 3.38.0 fault exactly.';
        }
        if ( preg_match( '#name="uc_action"#', $access ) ) {
            $fails[] = 'render_access_card() emits a uc_action, and it is inside the event form. PHP would take the last one.';
        }
    }
}

/* ------------------------------------------------------------------------ */
echo "\nForm nesting test\n";
echo "checked: every method that opens a form, what it calls between its open and close,\n";
echo "         and how many uc_action and nonce fields each form is given; plus that the\n";
echo "         cancel card is outside the event form and the access card is inside it\n";
echo "         without a form or an action of its own\n\n";

if ( $fails ) {
    echo 'FAIL: ' . count( $fails ) . "\n";
    foreach ( $fails as $f ) { echo "  . $f\n"; }
    exit( 1 );
}
echo "no form contains another, and no form carries two actions.\n";
