<?php
/**
 * THE TWO PUBLIC MODALS OPEN IN THE TOP LAYER, AND STAY THERE.
 *
 * The registration dialog opened underneath resources.sfaf.org's theme header
 * and centred on the page rather than the viewport. Both symptoms came from one
 * cause: `position: fixed` was not resolving against the viewport, which means
 * something above the dialog either became its containing block or overrode its
 * position, and either of those also makes its z-index unreachable. The remedy
 * is showModal(), which paints above every stacking context in the document and
 * whose containing block is the viewport whatever the ancestors do.
 *
 * NOTHING ELSE IN THE BUILD WOULD NOTICE A REGRESSION. There is no browser
 * here, so the fix is invisible to every other check: a `<dialog>` quietly
 * turned back into a `<div>`, or a showModal() replaced by a class toggle,
 * would pass lint, the callable audit and the whole suite while putting the
 * dialog back under the header. This is the check that would not.
 *
 * WHAT IT PROVES, over public/js/calendar.js and public/css/calendar.css:
 *
 *   1. Both overlays are <dialog> elements, opened and closed as elements
 *      rather than by toggling a class.
 *   2. showModal() is what opens them, with a fallback rather than a throw.
 *   3. The CSS overrides every user-agent property that would otherwise shrink
 *      a dialog to a small centred box, so the dim still fills the viewport.
 *   4. `[open]` is what makes it a flex container, because a closed dialog is
 *      display:none from the UA sheet.
 *
 * A SELF-TEST FIRST, because a checker that cannot fail is not evidence.
 * --self-test plants each regression in turn and requires every one caught.
 *
 *     php .claude/modal-toplayer-test.php --self-test
 *     php .claude/modal-toplayer-test.php
 */

$self = in_array( '--self-test', array_slice( $argv, 1 ), true );
$root = dirname( __DIR__ );

/** The user-agent dialog styles that must be overridden, and why each one. */
function ua_overrides() {
    return array(
        'width'      => 'a dialog is width: fit-content',
        'height'     => 'a dialog is height: fit-content',
        'max-width'  => 'a dialog is max-width: calc(100% - 6px - 2em)',
        'max-height' => 'a dialog is max-height: calc(100% - 6px - 2em)',
        'margin'     => 'a dialog is margin: auto',
        'padding'    => 'a dialog has padding',
        'border'     => 'a dialog has a border',
    );
}

function check_modals( $js, $css ) {
    $f = array();

    /* ---- 1. Both overlays are dialogs ---- */
    foreach ( array( 'uc-rsvp-modal', 'uc-follow-modal' ) as $id ) {
        if ( ! preg_match( '/<dialog class="uc-rsvp-modal-overlay" id="' . preg_quote( $id, '/' ) . '">/', $js ) ) {
            $f[] = "#{$id} is not a <dialog>, so it cannot reach the top layer";
        }
    }
    $opens  = preg_match_all( '/<dialog class="uc-rsvp-modal-overlay"/', $js );
    $closes = preg_match_all( '#</dialog>#', $js );
    if ( $opens !== $closes ) {
        $f[] = "{$opens} <dialog> opened and {$closes} closed: the markup is unbalanced";
    }

    /* ---- 2. showModal() opens them, with a fallback ---- */
    if ( ! preg_match( '/function\s+openOverlay\s*\(.*?\n\s{4}\}/s', $js, $m ) ) {
        $f[] = 'openOverlay() is not defined';
    } else {
        $body = $m[0];
        // A CALL, not the identifier: `typeof el.showModal === 'function'`
        // is still in the guard after the call itself has been replaced, so
        // searching for the name alone passed a planted regression.
        if ( ! preg_match( '/\.showModal\s*\(\s*\)/', $body ) ) {
            $f[] = 'openOverlay() does not call showModal(), so the dialog never reaches the top layer';
        }
        if ( false === strpos( $body, "setAttribute('open'" ) ) {
            $f[] = 'openOverlay() has no fallback for a browser without showModal()';
        }
    }
    if ( ! preg_match( '/function\s+closeOverlay\s*\(.*?\n\s{4}\}/s', $js, $m ) ) {
        $f[] = 'closeOverlay() is not defined';
    } elseif ( false === strpos( $m[0], '.close()' ) ) {
        $f[] = 'closeOverlay() does not call close()';
    }

    /* ---- the old mechanism must not come back ---- */
    foreach ( array( 'modal', 'fmodal' ) as $var ) {
        if ( preg_match( '/\b' . $var . '\.addClass\(\s*[\x27"]active[\x27"]\s*\)/', $js ) ) {
            $f[] = "{$var} is opened by toggling the active class again, not by showModal()";
        }
    }

    /* ---- 3 and 4. The CSS ---- */
    if ( ! preg_match( '/dialog\.uc-rsvp-modal-overlay\s*\{(.*?)\}/s', $css, $m ) ) {
        $f[] = 'there is no dialog.uc-rsvp-modal-overlay rule, so the user-agent styles stand';
    } else {
        $block = $m[1];
        foreach ( ua_overrides() as $prop => $why ) {
            if ( ! preg_match( '/(^|;|\s)' . preg_quote( $prop, '/' ) . '\s*:/m', $block ) ) {
                $f[] = "the dialog rule does not set {$prop}, and {$why}";
            }
        }
    }
    if ( ! preg_match( '/dialog\.uc-rsvp-modal-overlay\[open\]\s*\{[^}]*display\s*:\s*flex/s', $css ) ) {
        $f[] = '[open] does not set display: flex, so an open dialog is not a centring flex container';
    }

    return $f;
}

$js  = file_get_contents( $root . '/public/js/calendar.js' );
$css = file_get_contents( $root . '/public/css/calendar.css' );
if ( false === $js || false === $css ) {
    echo "cannot read the calendar sources\n";
    exit( 1 );
}

if ( $self ) {
    $plants = array(
        'the dialog turned back into a div' => function ( $j, $c ) {
            return array( str_replace( '<dialog class="uc-rsvp-modal-overlay" id="uc-rsvp-modal">',
                '<div class="uc-rsvp-modal-overlay" id="uc-rsvp-modal">', $j ), $c );
        },
        'showModal() replaced by a class toggle' => function ( $j, $c ) {
            return array( str_replace( 'el.showModal();', "el.classList.add('active');", $j ), $c );
        },
        'the fallback removed' => function ( $j, $c ) {
            return array( str_replace( "el.setAttribute('open', '');", '', $j ), $c );
        },
        'close() removed' => function ( $j, $c ) {
            return array( str_replace( 'el.close();', '', $j ), $c );
        },
        'the open class used to open again' => function ( $j, $c ) {
            return array( str_replace( 'openOverlay(modal);', "modal.addClass('active');", $j ), $c );
        },
        'max-height override dropped, so the dim shrinks' => function ( $j, $c ) {
            return array( $j, preg_replace( '/\n\s*max-height: none;/', '', $c, 1 ) );
        },
        // ANCHORED IN THE DIALOG BLOCK. The first version replaced the first
        // "margin: 0" anywhere in a 3,000-line stylesheet, which is somewhere
        // else entirely, so the plant never reached the rule under test and the
        // check was recorded as missing a regression it would have caught.
        'margin override dropped, so the dialog centres itself' => function ( $j, $c ) {
            return array( $j, str_replace(
                "    margin: 0;\n    padding: 0;\n    border: 0;",
                "    padding: 0;\n    border: 0;",
                $c ) );
        },
        '[open] no longer sets display: flex' => function ( $j, $c ) {
            return array( $j, str_replace( 'dialog.uc-rsvp-modal-overlay[open] { display: flex; }', '', $c ) );
        },
    );

    $missed = 0;
    foreach ( $plants as $label => $plant ) {
        list( $bj, $bc ) = $plant( $js, $css );
        if ( $bj === $js && $bc === $css ) {
            printf( "%-52s PLANT DID NOT APPLY\n", $label );
            $missed++;
            continue;
        }
        $found = check_modals( $bj, $bc );
        printf( "%-52s %s\n", $label, $found ? 'caught' : 'MISSED' );
        if ( ! $found ) { $missed++; }
    }
    $clean = check_modals( $js, $css );
    printf( "%-52s %s\n", 'the real sources, unmodified', $clean ? 'FALSE POSITIVE' : 'passes' );
    if ( $clean ) {
        foreach ( $clean as $c ) { echo "      {$c}\n"; }
        $missed++;
    }
    echo "\n" . ( $missed ? "FAILED: {$missed}\n" : "self-test passed: every planted regression caught, no false positive.\n" );
    exit( $missed ? 1 : 0 );
}

$findings = check_modals( $js, $css );
echo "Modal top-layer check\n";
if ( $findings ) {
    foreach ( $findings as $x ) { echo "  ! {$x}\n"; }
    echo "\n" . count( $findings ) . " finding(s).\n";
    exit( 1 );
}
echo "both modals are dialogs, opened with showModal(), and the user-agent box is fully overridden.\n";
