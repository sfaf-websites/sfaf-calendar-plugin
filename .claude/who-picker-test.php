<?php
/**
 * ORGANIZERS AND GROUPS IN ONE CONTROL (3.85.0).
 *
 *     php .claude/who-picker-test.php
 *
 * WHAT THIS CAN AND CANNOT SETTLE. It reads the rendered markup and the source,
 * so it proves the CONTRACT: the control opens without script or any platform
 * feature, the boxes post the names the server already reads, every group
 * carries the organizers its events name, and there is a real form with a real
 * submit under it. What it cannot prove is geometry, which is driven in Chrome
 * and recorded in the release notes.
 *
 * 3.86.0 REVERSED THE OPENING MECHANISM and these assertions reversed with it.
 * The panel was a popover placed by script from its `toggle` event, and on
 * sfaf.org it opened in the top left corner of the viewport. Section 2 now
 * asserts the opposite: nothing about opening this may depend on the popover
 * API, on CSS anchor positioning, or on script running.
 *
 * THE COMMENTS ARE STRIPPED BEFORE ANY SOURCE MATCH. Every docblock around this
 * code names the classes and the parameters, so a raw match reads prose. That
 * trap cost a round in 3.84.0 and another in 3.85.0.
 */
$root  = dirname( __DIR__ );
$fails = array();

function wp_code( $path ) {
    $out = '';
    foreach ( token_get_all( file_get_contents( $path ) ) as $tok ) {
        if ( is_array( $tok ) ) {
            if ( T_COMMENT === $tok[0] || T_DOC_COMMENT === $tok[0] ) { continue; }
            $out .= $tok[1];
        } else {
            $out .= $tok;
        }
    }
    return $out;
}

$sc  = wp_code( $root . '/includes/class-sfaf-shortcodes.php' );
$css = file_get_contents( $root . '/public/css/calendar.css' );
$js  = file_get_contents( $root . '/public/js/calendar.js' );

echo "The control\n";

/* 1. ONE CONTROL, AND THE TWO IT REPLACED ARE NOT STILL RENDERING. */
if ( false === strpos( $sc, 'render_who_picker' ) ) {
    $fails[] = 'the combined organizer and group picker is gone';
}
if ( strpos( $sc, '<select class="uc-organizer-select"' ) !== false ) {
    $fails[] = 'the single-value organizer select is back, which cannot express an event with three organizers';
}
if ( strpos( $sc, '$this->render_group_row(' ) !== false ) {
    $fails[] = 'the separate groups row is being rendered again alongside the merged picker, so the same question is asked twice';
}

/* 2. IT OPENS UNDER ITS TRIGGER IN EVERY BROWSER (3.86.0), WHICH THE POPOVER
 *    VERSION DID NOT.
 *
 * WHAT THESE USED TO ASSERT, and why the reversal is a strengthening rather
 * than a retreat. 3.85.0 required a popover, a popovertarget, and `right: auto`
 * and `bottom: auto` on the popover rule to defeat the UA's `inset: 0`. All of
 * that was correct ABOUT A POPOVER and all of it was load-bearing on a platform
 * feature that is not everywhere. On sfaf.org the panel opened in the top left
 * corner of the viewport: `position: fixed` at 0,0 was the pre-measurement
 * value and the script that moved it ran from the popover's `toggle` event.
 *
 * WORSE THAN A WRONG POSITION. The rule that HID the panel was
 * `:not(:popover-open)`, and a browser that does not know that selector throws
 * the whole rule away, so the panel stands open permanently.
 *
 * So the claim now is the opposite one and it is checkable without a browser:
 * NOTHING about opening this control may depend on the popover API, on anchor
 * positioning, or on script. */
if ( strpos( $sc, 'popovertarget' ) !== false || preg_match( '#<div class="uc-who-panel"[^>]*\spopover#', $sc ) ) {
    $fails[] = 'the panel is a popover again, which is what opened it in the top left corner on sfaf.org';
}
if ( strpos( $css, ':popover-open' ) !== false && preg_match( '#uc-who[^\n]*:popover-open#', $css ) ) {
    $fails[] = 'the who panel is hidden or styled by :popover-open again; a browser that does not know that selector discards the rule and the panel stands open';
}
if ( ! preg_match( '#<details class="uc-who"#', $sc ) ) {
    $fails[] = 'the control is no longer a <details>, so it needs script or a platform feature to open';
}
if ( ! preg_match( '#<summary class="uc-who-trigger#', $sc ) ) {
    $fails[] = 'the trigger is no longer a <summary>, so opening it needs script';
}
/* THE PLACEMENT IS IN THE STYLESHEET AND NEEDS NO MEASUREMENT. */
if ( ! preg_match( '#\.uc-who\s*\{([^}]*)\}#', $css, $wrap_rule ) || strpos( $wrap_rule[1], 'position: relative' ) === false ) {
    $fails[] = 'the wrapper is not positioned, so the panel has nothing to be absolute against and falls back to the viewport';
}
/* ANCHORED AT THE START OF A LINE, so this matches the panel's OWN rule and not
 * `.uc-who:not([open]) .uc-who-panel`, which also contains that string and has
 * no positioning in it. The unanchored version reported the panel unpositioned
 * while it was positioned correctly. */
if ( ! preg_match( '#^\.uc-who-panel\s*\{([^}]*)\}#m', $css, $panel_rule ) ) {
    $fails[] = 'the panel has no placement rules at all';
} else {
    if ( strpos( $panel_rule[1], 'position: absolute' ) === false ) {
        $fails[] = 'the panel is not absolutely positioned, so it is placed by script or not at all';
    }
    /* THE PROPERTY, NOT THE GAP. This pinned `top: calc(100% + 6px)` and so
     * failed when the gap was opened to 8px for spacing, which is a design
     * decision rather than a regression. What must hold is that the panel is
     * placed BELOW its trigger by the stylesheet, whatever the gap. */
    if ( ! preg_match( '#top:\s*calc\(\s*100%#', $panel_rule[1] ) ) {
        $fails[] = 'the panel is no longer placed under its trigger by the stylesheet';
    }
}
/* NO ANCHOR POSITIONING ANYWHERE. It is unimplemented in Safari and Firefox, so
 * a control that leans on it is a control that is wrong in two engines. */
if ( preg_match( '#anchor-name|position-anchor|position-try|\banchor\(#', $css ) ) {
    $fails[] = 'the stylesheet now uses CSS anchor positioning, which Safari and Firefox do not implement';
}
/* AND NOTHING SCRIPTED PLACES IT, which is the dependency that actually broke. */
if ( preg_match( '#panel\.style\.(top|left)\s*=#', $js ) && preg_match( '#uc-who#', $js ) ) {
    if ( preg_match( '#function place\(panel#', $js ) ) {
        $fails[] = 'the who panel is positioned by script again, so wherever that code does not run it opens in the corner';
    }
}

/* 3. THE NAMES THE SERVER ALREADY READS. uc_org and uc_group are read from
 *    $_GET, so the no-script submit has to use exactly those. */
if ( strpos( $sc, 'name="uc_org[]"' ) === false ) {
    $fails[] = 'the organizer boxes do not post uc_org[], which is the name the server reads';
}
if ( strpos( $sc, 'name="uc_group[]"' ) === false ) {
    $fails[] = 'the group boxes do not post uc_group[], which is the name the server reads';
}

/* 4. AN ARRAY IS A REAL SHAPE. Checkboxes submit one, and slug_list() used to
 *    cast it straight to a string, which is "Array" and matches no term. */
if ( ! preg_match( '#function slug_list\([^)]*\)\s*\{\s*if\s*\(\s*is_array#', $sc ) ) {
    $fails[] = 'slug_list() no longer handles an array, so a no-script submit of uc_org[] becomes the slug "array" and empties the calendar';
}

/* 5. IT WORKS WITH NO SCRIPT, which is new here rather than preserved. */
if ( strpos( $sc, 'class="uc-filter-form" method="get"' ) === false ) {
    $fails[] = 'the filter bar has no GET form, so with script off the controls do nothing at all';
}
if ( strpos( $sc, 'data-uc-who-apply' ) === false ) {
    $fails[] = 'there is no Apply submit, so a no-script visitor cannot apply a filter';
}
/* APPLY EXISTS ONLY FOR NO-SCRIPT (3.87.0), and this assertion reversed with
 * it. 3.85.0 hid the button with a CSS rule driven by an attribute the script
 * stamped, which is a hidden button rather than no button. It is inside
 * <noscript> now, so a browser with scripting on never parses it at all. The
 * claim is stronger: not "it is hidden" but "it is not there". */
/* THE WHOLE FOOTER IS INSIDE <noscript> FROM 3.93.0, NOT JUST THE BUTTON.
 * Clear moved out of that row, and an empty div still carries its own padding
 * and margin, which was 46px of panel height charged to everybody with script.
 * So the pattern allows the wrapper between the two. The claim is unchanged and
 * is still the strong one: a browser with scripting on never parses this
 * button, which is what <noscript> in front of it guarantees however much
 * markup sits in between. */
if ( ! preg_match( '#<noscript>\s*(<div class="uc-who-foot">\s*)?(<\?php.*?\?>\s*)?<button type="submit" class="uc-who-apply"#s', $sc ) ) {
    $fails[] = 'Apply is not inside <noscript>, so it exists for people who have script and every other filter here applies immediately';
}
if ( strpos( $css, '[data-uc-who-live]' ) !== false ) {
    $fails[] = 'the data-uc-who-live hiding rule is back; Apply is removed by <noscript> now, not hidden by CSS';
}

echo "The two columns\n";

/* 5b. ONE THIRD AND TWO THIRDS, HELD BY THE GRID (3.86.0).
 *
 * THE FRACTIONS ARE THE ASSERTION. Narrowing can take the right column from
 * twenty-five names to two, and a template sized by its contents would jump on
 * every tick. `1fr 2fr` does not care what is left inside it, which is the only
 * reason the layout holds still. */
if ( ! preg_match( '#\.uc-who-cols\s*\{([^}]*)\}#', $css, $cols_rule ) ) {
    $fails[] = 'the panel no longer lays its two sections out as columns';
} else {
    if ( strpos( $cols_rule[1], 'grid-template-columns: 1fr 2fr' ) === false ) {
        $fails[] = 'the columns are not one third and two thirds in fractions, so the layout is sized by its contents and will jump as groups are hidden';
    }
}
if ( strpos( $css, '.uc-who-list-2col' ) === false ) {
    $fails[] = 'the groups no longer run in two sub-columns, so twenty-five names are one long drop';
}
/* THE CONTAINER DECIDES THE STACK, NOT THE WINDOW. This block is embedded on
 * another site inside a column it does not control, so a media query about the
 * window is a lie in there. The media query is a floor beside it, not instead. */
if ( ! preg_match( '#@container\s+uc-calendar\s*\(max-width:\s*620px\)#', $css ) ) {
    $fails[] = 'the stack breakpoint is not a container query, so it measures the window rather than the column the block is in';
}

echo "The narrowing\n";

/* 6. ORGANIZER IS THE CONTROLLING FILTER, IN ONE DIRECTION ONLY. */
if ( strpos( $sc, 'data-uc-who-group-orgs' ) === false ) {
    $fails[] = 'groups no longer carry the organizers their events name, so nothing can narrow them';
}
if ( strpos( $js, 'function narrow(' ) === false ) {
    $fails[] = 'the narrowing function is gone from calendar.js';
}

/* 7. A GROUP WITH NO ORGANIZERED EVENTS IS ALWAYS SHOWN. On the current data
 *    that is roughly a third of them, so hiding them empties most of the list
 *    and reads as a broken control rather than as a filter. */
if ( strpos( $js, '!mine.length' ) === false ) {
    $fails[] = 'a group with no organizered events is no longer always shown, so about a third of the groups vanish the moment an organizer is picked';
}

/* 8. A TICKED GROUP IS NEVER HIDDEN, or a filter runs with nothing on screen
 *    to see it by or clear it with. */
if ( strpos( $js, 'box && box.checked' ) === false ) {
    $fails[] = 'a selected group can now be hidden by narrowing, which leaves a filter running that nobody can see or clear';
}

/* 9. HIDDEN, NOT GREYED. */
/* THE RULE HAS TO ACTUALLY HIDE. Asking only whether the selector exists let
 * `opacity: .4` pass as hiding, which is the greying this decision rejected. */
if ( ! preg_match( '#\.uc-who-opt\[hidden\]\s*\{([^}]*)\}#', $css, $hidden_rule ) ) {
    $fails[] = 'non-matching groups have no hiding rule at all';
} elseif ( strpos( $hidden_rule[1], 'display: none' ) === false ) {
    $fails[] = 'non-matching groups are greyed rather than hidden; greying keeps a line of a thirty-four item list for something unusable';
}

echo "The ordering and the label\n";

/* 10. NOTHING REORDERS THE LIST AT ALL (3.93.0), AND THIS ASSERTION REVERSED
 *     WITH THE BEHAVIOUR IT WAS PINNING.
 *
 *     It used to require a reorder on open and forbid one on change. That was
 *     the right pair while ticked items moved to the top: the on-open timing
 *     existed only so a row could not move out from under the cursor between
 *     one press and the next. The sort is gone, so the timing has nothing left
 *     to protect and the only thing worth asserting is that neither came back.
 *
 *     The toggle handler is still required, because narrowing runs from it: a
 *     block can arrive with organizers already ticked from the query string. */
if ( ! preg_match( "#on\('toggle', '\[data-uc-who\]'#", $js ) ) {
    $fails[] = 'the open handler is gone, so narrowing never runs for a panel opened after a redraw';
}
/*     COMMENTS STRIPPED FIRST. The removal is explained in a comment that
 *     names reorder(), so the raw file contains the string this is looking
 *     for and the check failed on its own documentation. That is the same
 *     trap the docblock at the top of this file names for the PHP side, met
 *     for the first time on the JS side. */
$js_code = preg_replace( '#/\*.*?\*/#s', '', $js );
$js_code = preg_replace( '#^\s*//.*$#m', '', $js_code );
if ( false !== strpos( $js_code, 'reorder(' ) ) {
    $fails[] = 'the list reorders again; with every option visible in two columns, moving a ticked row loses somebody their place in it';
}

/* 11. THE CLOSED TRIGGER SAYS WHAT IS SELECTED, from the server AND the script,
 *     so the wording does not change when the script takes over. */
if ( strpos( $sc, 'Organizers and groups' ) === false ) {
    $fails[] = 'the server no longer labels the closed trigger with its resting text';
}
if ( strpos( $js, 'Organizers and groups' ) === false ) {
    $fails[] = 'the script no longer labels the closed trigger with its resting text';
}
if ( strpos( $sc, "' selected'" ) === false && strpos( $sc, "' selected'" ) === false ) {
    $fails[] = 'the server no longer reports a count on the closed trigger';
}
if ( strpos( $js, "' selected'" ) === false ) {
    $fails[] = 'the script no longer reports a count on the closed trigger';
}

/* 12. THE CATEGORY PILLS ARE UNTOUCHED. They are colour-coded and readable at a
 *     glance, and folding them into this control would lose that. */
if ( strpos( $sc, 'uc-filter-btn' ) === false ) {
    $fails[] = 'the category pills are gone; they stay visible and unchanged';
}

echo "\nOrganizers and groups, one control\n";
echo str_repeat( '=', 72 ) . "\n";
echo "checked: one control replaces two and neither of the old ones still renders; the panel\n";
echo "         is a popover with the UA inset trap answered, so it floats instead of pushing\n";
echo "         the calendar down; the boxes post the names the server already reads and\n";
echo "         slug_list() accepts the array a checkbox group submits; there is a real GET\n";
echo "         form and a real submit under it, which the filter bar has never had before;\n";
echo "         narrowing runs one way only, keeps a group with no organizered events and\n";
echo "         never hides a ticked one; nothing reorders the list, ticked or not; and the\n";
echo "         closed trigger says what is selected from both the server and the script\n\n";

if ( empty( $fails ) ) {
    echo "one question, one control, and it floats.\n";
    exit( 0 );
}
echo count( $fails ) . " problem(s):\n";
foreach ( $fails as $f ) { echo '  - ' . $f . "\n"; }
exit( 1 );
