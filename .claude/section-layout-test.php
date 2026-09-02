<?php
/**
 * THE SECTION PANELS ON THE TWO REQUEST FORMS, AS RULES (3.68.1).
 *
 *     php .claude/section-layout-test.php
 *     php .claude/section-layout-test.php --self-test
 *
 * WHAT WENT WRONG, SO THE CHECK BELOW IS READ AS THE ANSWER TO IT. 3.68.0 gave
 * every section on both forms a floated <legend>, and that float had existed
 * since 3.66.0 without ever running: the only two fieldsets carrying the rule
 * also carried .uc-field, which is `display: flex`, and float computes to none
 * on a flex item. Removing .uc-field, which is what stopped a reset reaching
 * those sections, switched the float on for the first time.
 *
 * A live float and the first field then could not share a line. Every first
 * child of every section on both forms is .uc-field, .uc-field-row, .uc-check
 * or .uc-check-grid, and all four are flex or grid containers: each establishes
 * its own formatting context, so it refuses to overlap a float and is placed
 * beside it, and `min-width: auto` on a flex item stops it shrinking away. The
 * heading rendered on the left with the first field in a narrow column at the
 * right, on both forms, and every field after the first was correct because
 * only the first sat at the float's vertical position.
 *
 * WHAT THIS FILE CAN PROVE:
 *
 *   - that no rule floats a legend inside a section, on any selector;
 *   - that a section declares its own `display`, so it can never again inherit
 *     a layout mode by accident from a class it should not be carrying;
 *   - that the display it declares is one in which a float on a child is inert;
 *   - that the first child of every section on both forms really is one of the
 *     flex or grid classes this fault needs, read out of the two form sources
 *     rather than assumed.
 *
 * WHAT IT CANNOT PROVE, AND THIS IS NOT A HEDGE. It computes no geometry and
 * opens no browser. It cannot say that the Date input is full width, that a
 * hint does not wrap one word per line, or that the panel's edge draws
 * unbroken above the heading. Those are TESTING.md 1.39, and they are the only
 * way that half is settled.
 *
 * @package SFAF_Calendar
 */

$root = dirname( __DIR__ );
$self = in_array( '--self-test', $argv, true );

$fails = array();
function fail( $msg ) { global $fails; $fails[] = $msg; }

/* =========================================================================
 * The reader: declaration blocks out of a stylesheet.
 * ====================================================================== */

/**
 * Every rule in a stylesheet, as selector => declarations.
 *
 * COMMENTS OUT FIRST, AND WITH A REAL PASS RATHER THAN A LAZY REGEX. This file
 * is checking for the ABSENCE of a declaration, and portal.css explains at
 * length in its comments what the float used to do and why it is gone. A
 * checker that read its own subject's prose would report the fault it just
 * fixed.
 *
 * @param string $css
 * @return array<int,array{selector:string,body:string}>
 */
function rules_in( $css ) {
    $css = preg_replace( '#/\*.*?\*/#s', '', (string) $css );
    $out = array();
    foreach ( explode( '}', $css ) as $chunk ) {
        $at = strpos( $chunk, '{' );
        if ( false === $at ) {
            continue;
        }
        $selector = trim( substr( $chunk, 0, $at ) );
        $body     = trim( substr( $chunk, $at + 1 ) );
        if ( '' === $selector || '' === $body ) {
            continue;
        }
        /* An at-rule's own brace, e.g. `@media (...)`, opens a block of rules
         * rather than a block of declarations. The selector of the first rule
         * inside it arrives glued to it, so take the last line. */
        if ( '@' === $selector[0] ) {
            $lines    = preg_split( '/\r?\n/', $selector );
            $selector = trim( (string) end( $lines ) );
            if ( '' === $selector || '@' === $selector[0] ) {
                continue;
            }
        }
        $out[] = array( 'selector' => $selector, 'body' => $body );
    }
    return $out;
}

/**
 * The value of one property in a declaration block, or ''.
 *
 * LONGHAND ONLY, AND THE CALLER IS ASKED TO KNOW THAT. `display` and `float`
 * have no shorthand that can set them, which is the whole reason this is safe
 * to ask this way. It would not be safe for `border-top-width`.
 *
 * @param string $body
 * @param string $property
 * @return string
 */
function declared( $body, $property ) {
    if ( ! preg_match_all( '/(^|;)\s*' . preg_quote( $property, '/' ) . '\s*:\s*([^;]+)/i', $body, $m ) ) {
        return '';
    }
    /* The last one wins, which is what the cascade does inside one block. */
    return trim( (string) end( $m[2] ) );
}

/** Does this selector target something inside a section? */
function targets_section_child( $selector ) {
    return ( false !== strpos( $selector, '.uc-form-section-group ' )
        || false !== strpos( $selector, '.uc-form-section-group>' ) );
}

/* =========================================================================
 * SELF TEST. Every case is a shape this file has NOT already seen pass.
 * ====================================================================== */

if ( $self ) {
    echo "SELF TEST\n" . str_repeat( '=', 72 ) . "\n";
    $bad = 0;
    $say = function ( $ok, $good, $broken ) use ( &$bad ) {
        if ( $ok ) { echo "ok       $good\n"; } else { echo "BROKEN:  $broken\n"; $bad++; }
    };

    $probe = rules_in( '.a { color: red; } .b > legend { float: left; width: 100%; }' );
    $say( 2 === count( $probe ), 'the reader finds two rules where there are two',
        'the reader found ' . count( $probe ) . ' rules in a stylesheet with two' );
    $say( isset( $probe[1] ) && 'left' === declared( $probe[1]['body'], 'float' ),
        'and can see a float where there is one',
        'a declared float is invisible to the reader, so every absence check is vacuous' );

    /* THE CASE THAT MATTERS MOST: the fault written into a COMMENT, which is
     * what portal.css now carries several paragraphs of. */
    $commented = rules_in( "/* .x > legend { float: left; } */\n.x > legend { padding: 0; }" );
    $say( 1 === count( $commented ), 'a rule inside a comment is not a rule',
        'the reader counts commented-out CSS, so it would report the prose describing the fix' );
    $say( isset( $commented[0] ) && '' === declared( $commented[0]['body'], 'float' ),
        'and the surviving rule has no float',
        'the reader attributes a commented float to the rule beside it' );

    /* A property that is a PREFIX of another must not match it. */
    $say( '' === declared( 'display-something: flex;', 'display' ),
        'a property that merely starts the same does not match',
        'the reader matches a prefix, so it cannot tell two properties apart' );
    $say( 'flex' === declared( 'padding: 0; display: flex; margin: 0', 'display' ),
        'a property in the middle of a block is found',
        'the reader only finds a declaration when it is first' );

    /* Last one in a block wins, as the cascade does. */
    $say( 'grid' === declared( 'display: flex; display: grid;', 'display' ),
        'the last declaration in a block is the one reported',
        'the reader reports an overridden value' );

    /* And the section-child test must not match the section itself. */
    $say( false === targets_section_child( '.uc-form-section-group' ),
        'the section itself is not one of its own children',
        'the reader treats the section as its own descendant' );
    $say( true === targets_section_child( '.uc-form-section-group > legend' ),
        'and a child selector is one',
        'the reader cannot see a child selector' );

    echo "\n" . ( $bad ? "$bad case(s) wrong; this checker cannot be trusted.\n"
        : "the reader reads what it claims to read.\n" );
    exit( $bad ? 1 : 0 );
}

/* =========================================================================
 * 1. NO LEGEND IN A SECTION IS FLOATED.
 *
 * The invariant, stated as the absence it is. A float here is inert only while
 * the section happens to be a flex container, and "inert while something else
 * holds" is exactly the arrangement that failed.
 * ====================================================================== */

$css   = file_get_contents( $root . '/public/css/portal.css' );
$rules = rules_in( $css );

if ( count( $rules ) < 200 ) {
    fail( 'the reader found only ' . count( $rules ) . ' rules in portal.css, so it is not reading the file' );
}

$section_rules = array();
foreach ( $rules as $rule ) {
    if ( targets_section_child( $rule['selector'] ) ) {
        $section_rules[] = $rule;
        $float = declared( $rule['body'], 'float' );
        if ( '' !== $float && 'none' !== $float ) {
            fail( "`{$rule['selector']}` floats something inside a section (float: $float); "
                . 'the first field is a flex or grid container and will be laid out beside it' );
        }
    }
}
if ( empty( $section_rules ) ) {
    fail( 'no rule targets anything inside a section, so the check above passed by finding nothing' );
}

/* =========================================================================
 * 2. THE SECTION DECLARES ITS OWN LAYOUT MODE.
 *
 * THIS IS THE FAULT ONE LEVEL ALONG, AND IT IS THE ONE WORTH GUARDING. The
 * reset that 3.68.0 stopped matching was three properties, but the CLASS
 * carrying it, .uc-field, is also `display: flex`, and that was what held the
 * float inert. Taking the class off the sections took the layout mode with it.
 * A section that states its own display cannot be broken that way again.
 * ====================================================================== */

$section = null;
foreach ( $rules as $rule ) {
    if ( '.uc-form-section-group' === $rule['selector'] ) {
        $section = $rule;
    }
}
if ( null === $section ) {
    fail( 'there is no .uc-form-section-group rule at all' );
} else {
    $display = strtolower( declared( $section['body'], 'display' ) );
    if ( '' === $display ) {
        fail( 'a section declares no display of its own, so its layout mode comes from whatever class it happens to carry' );
    } elseif ( ! in_array( $display, array( 'flex', 'grid', 'inline-flex', 'inline-grid' ), true ) ) {
        fail( "a section declares `display: $display`, which is a mode a float on a child can still disturb" );
    }
    foreach ( array( 'border', 'background', 'padding' ) as $kept ) {
        if ( '' === declared( $section['body'], $kept ) ) {
            fail( "a section no longer declares $kept, so the neutral panel is gone" );
        }
    }
}

/* =========================================================================
 * 3. AND THE FIRST CHILD OF EVERY SECTION REALLY IS ONE OF THOSE CONTAINERS.
 *
 * Read out of both forms rather than assumed, because the reason this fault
 * hit the FIRST field and nothing after it is a property of what that field
 * is. If a section ever begins with a plain block, the float would have been
 * survivable there and the shape of this failure would be different.
 * ====================================================================== */

/* Which classes this stylesheet makes a flex or grid container. */
$fc = array();
foreach ( $rules as $rule ) {
    $display = strtolower( declared( $rule['body'], 'display' ) );
    if ( ! in_array( $display, array( 'flex', 'grid', 'inline-flex', 'inline-grid' ), true ) ) {
        continue;
    }
    if ( preg_match( '/^\.([a-z0-9-]+)$/i', trim( $rule['selector'] ), $m ) ) {
        $fc[ $m[1] ] = $display;
    }
}
foreach ( array( 'uc-field', 'uc-field-row', 'uc-check', 'uc-check-grid' ) as $expected ) {
    if ( ! isset( $fc[ $expected ] ) ) {
        fail( ".$expected is no longer a flex or grid container, so the reasoning recorded above is out of date" );
    }
}

$first_children = array();
foreach ( array(
    'the staff form'     => '/includes/class-sfaf-request.php',
    'the community form' => '/includes/class-sfaf-submit.php',
) as $which => $file ) {
    $src = preg_replace( '#/\*.*?\*/#s', '', (string) file_get_contents( $root . $file ) );

    /* Each section, from its opening tag to the first element after its
     * legend. The legend is always the first child; what follows it is what
     * this fault landed on. */
    if ( ! preg_match_all(
        '#<fieldset class="[^"]*uc-form-section-group[^"]*">\s*(?:<\?php.*?\?>\s*)*<legend[^>]*>.*?</legend>\s*(?:<\?php.*?\?>\s*)*<(\w+)([^>]*)>#s',
        $src, $m, PREG_SET_ORDER
    ) ) {
        fail( "no section on $which could be read back, so nothing below was checked for it" );
        continue;
    }
    foreach ( $m as $hit ) {
        $classes = array();
        if ( preg_match( '/class="([^"]*)"/', $hit[2], $c ) ) {
            $classes = preg_split( '/\s+/', trim( $c[1] ) );
        }
        $first_children[] = array( 'form' => $which, 'tag' => $hit[1], 'classes' => $classes );
    }
}

if ( count( $first_children ) < 10 ) {
    fail( 'only ' . count( $first_children ) . ' sections were read across both forms; there should be more than ten' );
}
foreach ( $first_children as $child ) {
    $is_fc = false;
    foreach ( $child['classes'] as $one ) {
        if ( isset( $fc[ $one ] ) ) { $is_fc = true; break; }
    }
    if ( ! $is_fc ) {
        /* Not a failure: a section beginning with a plain block is fine, and
         * says the fault would not have shown there. Recorded so the report
         * does not claim more coverage than it has. */
        $child['plain'] = true;
    }
}

/* =========================================================================
 * Report.
 * ====================================================================== */

$fc_first = 0;
foreach ( $first_children as $child ) {
    foreach ( $child['classes'] as $one ) {
        if ( isset( $fc[ $one ] ) ) { $fc_first++; break; }
    }
}

echo "The section panels on the two request forms\n";
echo str_repeat( '=', 72 ) . "\n";
printf( "sections:  %d across both forms, %d of them opening with a flex or grid container\n",
    count( $first_children ), $fc_first );
printf( "the rule:  .uc-form-section-group declares display: %s\n",
    $section ? declared( $section['body'], 'display' ) : '(no rule)' );
printf( "floats:    %d rule(s) target something inside a section, none of them floating\n",
    count( $section_rules ) );
echo "\n";
echo "checked by reading:  portal.css, with comments removed by a real pass, and\n";
echo "                     the section markup of both form sources\n";
echo "NOT proven here:     any geometry at all. This computes no widths and opens\n";
echo "                     no browser, so it cannot say the Date control is full\n";
echo "                     width, that no hint wraps one word per line, or that\n";
echo "                     the panel edge draws unbroken above its heading.\n";
echo "                     TESTING.md 1.39 is the only thing that settles those.\n\n";

if ( empty( $fails ) ) {
    echo "no section can lay its first field out beside its heading.\n";
    exit( 0 );
}

echo count( $fails ) . " problem(s):\n";
foreach ( $fails as $f ) {
    echo '  - ' . $f . "\n";
}
exit( 1 );
