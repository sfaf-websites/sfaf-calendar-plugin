<?php
/**
 * WHICH ADMIN CARDS PAD THEIR CONTENTS, AND WHICH ONLY LOOK LIKE THEY DO.
 *
 * WHY THIS EXISTS. The Automation screen's headings and form controls sat flush
 * against the card border, and the reason was not a rule that lost a cascade
 * fight: there was no rule at all. `.uc-admin-card` declared a background, a
 * border and a radius and no padding, so a card looked correct only when every
 * child inside it happened to carry padding of its own. Tables do (their cells
 * are padded), the Calendar Users explainer and search bar do (they were given
 * their own), and headings, paragraphs and forms do not.
 *
 * That is the same shape as the unstyled controls found in 3.16.0 and the
 * spacing baseline written in 3.20.0: a thing is styled by accident of what it
 * sits inside, so the next thing added is unstyled and nobody can see why.
 * 3.20.0 answered it for `/caladmin` control rows by keying a baseline on the
 * `-actions` naming convention. That convention does not reach WordPress admin
 * screens at all, and this is the check that says so out loud.
 *
 * WHAT IT DOES. It reads the admin renderers, finds every element that carries
 * a card class, lists the direct children each one renders, and asks the
 * stylesheet whether the card or that child declares any horizontal padding.
 * A child with neither is a child that renders against the border.
 *
 *     php .claude/admin-padding-audit.php
 *
 * It is a static reading of markup and CSS, not a rendering, so it proves that
 * a rule EXISTS rather than that it reaches the screen. That distinction is the
 * whole of §3 of CLAUDE.md and it is why this script fails loudly if it stops
 * finding the cards it is supposed to be reading: an audit that silently
 * matches nothing reports a clean result, which is worse than no audit.
 */

$root = dirname( __DIR__ );

$php_files = array(
    'admin/class-sfaf-admin.php',
);
$css_file = 'admin/css/admin.css';

/* ---------------------------------------------------------------------------
 * The stylesheet, stripped of comments.
 *
 * A source sweep once matched its own explanatory comment and reported a false
 * positive (3.20.0, twice). Comments go first, always.
 * ------------------------------------------------------------------------ */
$css = file_get_contents( $root . '/' . $css_file );
if ( false === $css ) {
    fwrite( STDERR, "FAIL: cannot read $css_file\n" );
    exit( 2 );
}
$css = preg_replace( '#/\*.*?\*/#s', '', $css );

/**
 * Does any rule give this exact selector token horizontal padding?
 *
 * Deliberately crude and deliberately generous: it asks whether the class
 * appears in a selector whose block declares padding, padding-left or
 * padding-right. Being generous is the safe direction here, because a false
 * "it is padded" makes the audit report FEWER problems than exist, and every
 * one it does report is then real.
 */
function declares_horizontal_padding( $css, $class ) {
    $quoted = preg_quote( $class, '#' );

    // Every rule block whose selector list mentions the class.
    if ( ! preg_match_all( '#([^{}]*\.' . $quoted . '(?![\w-])[^{}]*)\{([^{}]*)\}#', $css, $m, PREG_SET_ORDER ) ) {
        return false;
    }

    foreach ( $m as $rule ) {
        $decls = $rule[2];
        if ( preg_match( '#(^|;)\s*padding(-left|-right)?\s*:#i', $decls ) ) {
            return true;
        }
    }
    return false;
}

/* ---------------------------------------------------------------------------
 * The markup: every card, and the direct children it renders.
 *
 * The renderers are PHP templates with control flow in them, so this does not
 * try to parse a DOM. It walks the file line by line tracking depth from the
 * card's opening tag, and records the tag of anything opened at depth 1. That
 * is enough to answer the question being asked, which is "what sits directly
 * inside a card", and it cannot be fooled by a conditional because it counts
 * tags rather than evaluating branches.
 * ------------------------------------------------------------------------ */
$CARD_CLASS = 'uc-admin-card';

/** Children whose own padding is not the point: they are full-bleed by design. */
$cards = array();

foreach ( $php_files as $file ) {
    $lines = file( $root . '/' . $file );
    if ( false === $lines ) {
        fwrite( STDERR, "FAIL: cannot read $file\n" );
        exit( 2 );
    }

    $open  = false;
    $depth = 0;
    $card  = null;

    foreach ( $lines as $n => $line ) {
        if ( ! $open && preg_match( '#<div class="([^"]*\b' . preg_quote( $CARD_CLASS, '#' ) . '\b[^"]*)"#', $line, $m ) ) {
            $open  = true;
            $depth = 0;
            $card  = array(
                'file'     => $file,
                'line'     => $n + 1,
                'classes'  => preg_split( '#\s+#', trim( $m[1] ) ),
                'children' => array(),
            );
            // Fall through: the same line may also close, but ours never do.
            continue;
        }

        if ( ! $open ) {
            continue;
        }

        // A direct child is anything opened while depth is 0.
        //
        // DEPTH COUNTS EVERY CONTAINER, NOT ONLY DIVS. The first cut of this
        // counted `<div>` alone, and reported forty problems: every <span> in a
        // table cell and every control inside a padded <form> came back as a
        // direct child of the card, because a table and a form did not raise
        // the depth. Half of what it found was not there. A checker that
        // over-reports is not the safe direction either, because the real
        // findings are then read as noise along with the rest.
        if ( 0 === $depth && preg_match( '#<(h2|h3|p|form|table|ul|ol|div|span|button)\b([^>]*)>#', $line, $m ) ) {
            $tag     = $m[1];
            $attrs   = $m[2];
            $classes = array();
            if ( preg_match( '#class="([^"]*)"#', $attrs, $cm ) ) {
                $classes = preg_split( '#\s+#', trim( $cm[1] ) );
            }
            $card['children'][] = array(
                'line'    => $n + 1,
                'tag'     => $tag,
                'classes' => array_filter( $classes ),
            );
        }

        foreach ( array( 'div', 'form', 'table', 'ul', 'ol' ) as $container ) {
            $depth += preg_match_all( '#<' . $container . '\b#', $line );
            $depth -= preg_match_all( '#</' . $container . '>#', $line );
        }

        if ( $depth < 0 ) {
            $cards[] = $card;
            $open    = false;
            $card    = null;
        }
    }
}

if ( count( $cards ) < 3 ) {
    fwrite( STDERR, "FAIL: found only " . count( $cards ) . " cards. The markup moved and this audit is now blind.\n" );
    exit( 2 );
}

/* ---------------------------------------------------------------------------
 * The reading.
 * ------------------------------------------------------------------------ */
$unpadded = array();
$screens  = array();

echo "Admin card padding audit\n";
echo "Cards found: " . count( $cards ) . "\n\n";

foreach ( $cards as $card ) {
    // Does the card itself carry padding, by any of its classes?
    $card_padded = false;
    foreach ( $card['classes'] as $c ) {
        if ( declares_horizontal_padding( $css, $c ) ) {
            $card_padded = true;
            break;
        }
    }

    $label = implode( '.', $card['classes'] ) . '  (' . $card['file'] . ':' . $card['line'] . ')';
    printf( "%-64s %s\n", $label, $card_padded ? 'card is padded' : 'CARD HAS NO PADDING' );

    if ( $card_padded ) {
        continue;
    }

    foreach ( $card['children'] as $child ) {
        // A table pads itself through its cells; that is what made these cards
        // look correct and is exactly the accident being audited, so it counts
        // as padded but is reported as the reason.
        $child_padded = ( 'table' === $child['tag'] );
        $why          = 'table cells';

        foreach ( $child['classes'] as $c ) {
            if ( declares_horizontal_padding( $css, $c ) ) {
                $child_padded = true;
                $why          = '.' . $c;
                break;
            }
        }

        if ( $child_padded ) {
            printf( "    ok      %-8s line %-5d padded by %s\n", '<' . $child['tag'] . '>', $child['line'], $why );
            continue;
        }

        $cls = $child['classes'] ? '.' . implode( '.', $child['classes'] ) : '(no class)';
        printf( "    FLUSH   %-8s line %-5d %s\n", '<' . $child['tag'] . '>', $child['line'], $cls );
        $unpadded[] = $card['file'] . ':' . $child['line'] . '  <' . $child['tag'] . '> ' . $cls;
        $screens[ $card['file'] . ':' . $card['line'] ] = true;
    }
}

echo "\n";
if ( $unpadded ) {
    echo "elements rendering against the card border: " . count( $unpadded ) . "\n";
    foreach ( $unpadded as $u ) {
        echo "  $u\n";
    }
    echo "\nA card owns its padding. Anything relying on a child that happens to carry\n";
    echo "its own is unstyled the moment a child without one is added.\n";
    exit( 1 );
}

echo "every card pads its own contents; nothing renders against the border.\n";
