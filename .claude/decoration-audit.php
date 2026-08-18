<?php
/**
 * DECORATION THAT CARRIES NO INFORMATION.
 *
 * A coloured edge on a box is the most recognisable tell of generated UI, and
 * this plugin was full of it: until 3.38.0 EVERY card in caladmin carried a 3px
 * teal left border with an asymmetric 4/12/12/4 radius. An accent that every
 * card has distinguishes nothing at all, which is the whole test.
 *
 * WHAT THIS CHECKS. Every rule that puts a thick coloured edge on a box, against
 * a WHITELIST of the ones kept deliberately. Anything not on the list fails, so
 * a new one added tomorrow is caught by default rather than by somebody noticing
 * the screens have started looking generated again.
 *
 *     php .claude/decoration-audit.php
 *
 * THE TEST FOR KEEPING ONE is not "does it look nice". It is: IS THE COLOUR OR
 * THE SHAPE THE ONLY CARRIER OF A FACT? If the same information is in the words
 * beside it, the edge is decoration and goes. Three survive and each is listed
 * below with the fact it carries and nothing else does.
 *
 * WHAT IT DOES NOT CHECK. Tinted panels, pills, badges and flash messages, where
 * the tint IS the component's shape rather than an accent on a neutral box, and
 * where the status word is always in the text as well. Those were read by hand
 * during the 3.38.0 sweep and left alone.
 */

$root = dirname( __DIR__ );

$files = array(
    'public/css/portal.css',
    'public/css/calendar.css',
    'admin/css/admin.css',
);

/*
 * THE WHITELIST. selector => the fact the colour carries that nothing else does.
 *
 * Each of these is a case where removing the colour would remove information
 * from the screen, not merely tidy it.
 */
$KEEP = array(
    // The category colour, on the event page's header band. The category system
    // is the one place in this plugin where colour is the signal rather than
    // decoration: the same colour is the card ring (3.31.0), the chip and the
    // placeholder tile, and it is how somebody recognises a programme at a
    // glance across every surface. Removing it would leave the category
    // unstated on a page that never names it in words.
    '.uc-single-header' => 'the event category, which this page states nowhere else',

    // Which field still needs filling in, on a form of roughly thirty. The
    // publish banner names WHAT is missing; only this says WHERE. It is also
    // not a bare stripe: the input's own border and background carry it too.
    '.uc-field-attention' => 'which of ~30 fields is the one still empty; the banner names what, not where',
    '.uc-field-attention input, .uc-field-attention textarea' => 'the same field, on the control itself',
);

$fails  = array();
$found  = 0;
$kept   = 0;

foreach ( $files as $rel ) {
    $path = $root . '/' . $rel;
    $src  = file_get_contents( $path );
    if ( false === $src ) {
        $fails[] = "cannot read $rel";
        continue;
    }

    // Comments first, always: a sweep that matches its own explanatory prose
    // reports a false positive, which has happened twice on this project.
    $code = preg_replace( '#/\*.*?\*/#s', '', $src );

    if ( ! preg_match_all( '#([^{}]+)\{([^{}]*)\}#', $code, $m, PREG_SET_ORDER ) ) {
        continue;
    }

    foreach ( $m as $rule ) {
        $sel   = trim( preg_replace( '/\s+/', ' ', $rule[1] ) );
        $decls = $rule[2];

        /*
         * A THICK edge, 3px or more, on ONE side of a box. That is the pattern
         * and nothing else is.
         *
         * The first cut also flagged every `border-*-color:` declaration, and
         * four of its five findings were not the thing at all: a spinner, whose
         * 2px circle border IS the spinner; two hover underlines; and a 1px
         * hairline under a heading band. An audit that over-reports buries its
         * real findings, which is exactly what the padding audit had to be
         * corrected for in 3.34.0.
         *
         * A colour override on a thin edge is not an accent. A colour override
         * on a thick one does not need catching separately either: it is inert
         * once the base rule that made it thick is gone, and the base rule is
         * what this catches.
         */
        $is_accent = preg_match( '~border-(left|top|right|bottom)\s*:\s*[^;]*\b([3-9]|[1-9][0-9])px~', $decls )
            || preg_match( '~border-(left|top|right|bottom)-width\s*:\s*([3-9]|[1-9][0-9])px~', $decls );

        if ( ! $is_accent ) {
            continue;
        }

        // A transparent edge is spacing, not an accent.
        if ( preg_match( '~border-(left|top|right|bottom)\s*:\s*[^;]*transparent~', $decls ) ) {
            continue;
        }

        // A border-radius of 50% is a circle: a spinner or an avatar, where the
        // border is the shape rather than an accent on a box.
        if ( preg_match( '~border-radius\s*:\s*50%~', $decls ) ) {
            continue;
        }

        $found++;

        if ( isset( $KEEP[ $sel ] ) ) {
            $kept++;
            continue;
        }

        $fails[] = sprintf(
            '%s: `%s` puts a thick coloured edge on a box and is not on the keep list. Either the colour carries a fact nothing else does, in which case add it with that reason, or it is decoration and goes.',
            $rel,
            substr( $sel, 0, 60 )
        );
    }
}

/*
 * AND THE ONES THAT WENT MUST STAY GONE. A whitelist catches new decoration; it
 * cannot notice the old decoration coming back under its original name, because
 * that name would simply be a new failure indistinguishable from any other. So
 * the two that defined the pattern are named.
 */
$portal = file_get_contents( $root . '/public/css/portal.css' );
$portal_code = preg_replace( '#/\*.*?\*/#s', '', $portal );

foreach ( array( '.uc-card', '.uc-bento-card' ) as $sel ) {
    if ( preg_match( '~\\' . $sel . '\s*\{[^}]*border-radius:\s*4px 12px 12px 4px~', $portal_code ) ) {
        $fails[] = "the asymmetric 4/12/12/4 radius is back on $sel. That plus a coloured edge was the pattern 3.38.0 removed.";
    }
}

/* ------------------------------------------------------------------------ */
echo "Decoration audit\n";
echo "checked: every rule putting a thick coloured edge on a box, in three stylesheets\n";
printf( "found:   %d accent edges, %d kept deliberately\n", $found, $kept );
foreach ( $KEEP as $sel => $why ) {
    printf( "  keep   %-52s %s\n", substr( $sel, 0, 52 ), $why );
}
echo "\n";

if ( $fails ) {
    echo 'FAIL: ' . count( $fails ) . "\n";
    foreach ( $fails as $f ) { echo "  . $f\n"; }
    exit( 1 );
}
echo "nothing wears a coloured edge except where the colour is the fact.\n";
