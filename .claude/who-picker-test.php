<?php
/**
 * ORGANIZERS AND GROUPS IN ONE CONTROL (3.85.0).
 *
 *     php .claude/who-picker-test.php
 *
 * WHAT THIS CAN AND CANNOT SETTLE. It reads the rendered markup and the source,
 * so it proves the CONTRACT: the panel is a popover, the boxes post the names
 * the server already reads, every group carries the organizers its events name,
 * and there is a real form with a real submit under it. What it cannot prove is
 * that the panel floats instead of pushing the calendar down, because that is
 * geometry. That half was driven in Chrome for 3.85.0 and measured: the
 * calendar sat at y=88 before the panel opened and y=88 after, with the panel in
 * the top layer at 16,62 under a trigger ending at y=56.
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

/* 2. THE TOP LAYER. A z-index is not enough and never was: an ancestor that has
 *    become a containing block traps it, which is what happened to the hover
 *    preview in 3.75.0. */
if ( strpos( $sc, 'popovertarget=' ) === false ) {
    $fails[] = 'the trigger no longer targets a popover, so the panel is not in the top layer and will push the calendar down';
}
if ( strpos( $sc, 'popover data-uc-who-panel' ) === false ) {
    $fails[] = 'the panel no longer carries the popover attribute';
}
/* SCOPED TO THE RULE, NOT SEARCHED ACROSS THE FILE. The first draft asked
 * whether "right: auto" appeared anywhere in calendar.css, which it does, in
 * rules that have nothing to do with this. Deleting the declaration from the
 * popover changed nothing and the check stayed green. Caught by planting it. */
if ( ! preg_match( '#\.uc-who-panel\[popover\]\s*\{([^}]*)\}#', $css, $panel_rule ) ) {
    $fails[] = 'the popover has no stylesheet rules, so the UA inset:0 and margin:auto will centre it in the viewport';
} else {
    foreach ( array( 'right: auto', 'bottom: auto' ) as $needed ) {
        if ( strpos( $panel_rule[1], $needed ) === false ) {
            $fails[] = 'the popover rule does not set ' . $needed . ', so the UA stylesheet pins that edge at 0 and the auto margins centre the panel instead of placing it';
        }
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
if ( strpos( $css, '[data-uc-who-live] .uc-who-apply' ) === false ) {
    $fails[] = 'Apply is not hidden once the script is listening, so it is a button that repeats what already happened';
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

/* 10. REORDER ON OPEN, NOT WHILE CLICKING. A list that moves the row just
 *     ticked out from under the cursor makes the next click land elsewhere. */
if ( strpos( $js, "addEventListener('toggle'" ) === false ) {
    $fails[] = 'the panel no longer reorders on open, so either it never reorders or it reorders under the cursor';
}
if ( preg_match( "#'change'[^\n]*\n[^\n]*reorder\(#", $js ) ) {
    $fails[] = 'the list reorders on change, which moves the row just ticked out from under the cursor';
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
echo "         never hides a ticked one; the list reorders on open and not on change; and the\n";
echo "         closed trigger says what is selected from both the server and the script\n\n";

if ( empty( $fails ) ) {
    echo "one question, one control, and it floats.\n";
    exit( 0 );
}
echo count( $fails ) . " problem(s):\n";
foreach ( $fails as $f ) { echo '  - ' . $f . "\n"; }
exit( 1 );
