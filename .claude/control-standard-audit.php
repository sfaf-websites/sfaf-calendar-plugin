<?php
/**
 * THE CONTROL STANDARD AUDIT (3.64.0).
 *
 * WHY THIS EXISTS. Three separate reports, on three screens, said the same
 * thing: controls in /caladmin are white boxes with thin gray outlines, and
 * controls that DO DIFFERENT THINGS look identical. Two of those reports were
 * fixed by restyling the screen they were reported on, which is what made the
 * third one inevitable. The defect is not "this button looks wrong"; it is that
 * ONE KIND OF CONTROL HAS SEVERAL DEFINITIONS, and the one that reaches the
 * screen is decided by which wrapper the control happens to sit inside.
 *
 * So this does not check appearance. It checks that each kind of control has
 * exactly ONE definition of the properties that say what kind it is, and that
 * two kinds cannot look the same.
 *
 * The same structural argument as the 3.16.0 control sweep and the card-heading
 * rule: a property of BEING a control, not a property of sitting in a wrapper
 * somebody remembered to add.
 *
 * Usage:
 *   php .claude/control-standard-audit.php              run the checks
 *   php .claude/control-standard-audit.php --self-test  prove it can fail
 *   php .claude/control-standard-audit.php --report     the Part A enumeration
 */

$root = dirname( __DIR__ );
$css  = $root . '/public/css/portal.css';

$mode = 'run';
foreach ( array_slice( $argv, 1 ) as $arg ) {
    if ( '--self-test' === $arg ) { $mode = 'selftest'; }
    if ( '--report' === $arg )    { $mode = 'report'; }
}

/* =====================================================================
 * Contrast, so every number below is measured rather than chosen by eye.
 * ================================================================== */

function ch_lin( $c ) {
    $c = $c / 255;
    return $c <= 0.03928 ? $c / 12.92 : pow( ( $c + 0.055 ) / 1.055, 2.4 );
}

function ch_lum( $hex ) {
    $hex = ltrim( $hex, '#' );
    if ( 3 === strlen( $hex ) ) {
        $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    }
    $r = hexdec( substr( $hex, 0, 2 ) );
    $g = hexdec( substr( $hex, 2, 2 ) );
    $b = hexdec( substr( $hex, 4, 2 ) );
    return 0.2126 * ch_lin( $r ) + 0.7152 * ch_lin( $g ) + 0.0722 * ch_lin( $b );
}

function ch_ratio( $a, $b ) {
    $la = ch_lum( $a );
    $lb = ch_lum( $b );
    $hi = max( $la, $lb );
    $lo = min( $la, $lb );
    return ( $hi + 0.05 ) / ( $lo + 0.05 );
}

/* =====================================================================
 * A very small CSS reader: selector, declarations, line, specificity.
 *
 * NOT A PARSER, AND IT SAYS SO. It reads top-level rules and skips the
 * contents of at-rules, which is right for this file: nothing inside a
 * @media block here declares a control's fill or boundary, and a rule that
 * did would be a per-width exception this audit should be told about
 * separately rather than silently folded in.
 * ================================================================== */

/**
 * Blank every comment, keeping the newlines so line numbers stay true.
 *
 * THIS IS WHY IT IS DONE UP FRONT AND NOT IN THE LOOP. The first version
 * skipped comments while scanning for a selector and then, having found `{`,
 * took the whole block through to `}` RAW. Every comment written between two
 * declarations therefore arrived inside a declaration, and `explode(':')` read
 * the comment's own first colon as the property name. The baseline's fill sits
 * directly under a five-line comment, so the check asking whether it declares
 * one answered no, correctly reported a failure, and the failure was the
 * audit's. That is the shape of the 3.20.0 fault, and it is why a new checker
 * has to be made to fail on purpose before it is believed.
 */
function css_strip_comments( $src ) {
    return preg_replace_callback(
        '~/\*.*?\*/~s',
        function ( $m ) { return str_repeat( "\n", substr_count( $m[0], "\n" ) ); },
        $src
    );
}

function css_rules( $src ) {
    $src   = css_strip_comments( $src );
    $rules = array();
    $len   = strlen( $src );
    $buf   = '';
    $line  = 1;
    $start = 1;
    $i     = 0;

    while ( $i < $len ) {
        $ch = $src[ $i ];

        if ( "\n" === $ch ) { $line++; }

        if ( '{' === $ch ) {
            $sel = trim( $buf );
            $buf = '';
            if ( '' === $sel ) { $i++; continue; }

            // An at-rule: keep its whole block out of the rule list, but keep
            // reading past it so the line numbers after it stay true.
            if ( '@' === $sel[0] ) {
                $depth = 1;
                $i++;
                while ( $i < $len && $depth > 0 ) {
                    if ( '{' === $src[ $i ] ) { $depth++; }
                    if ( '}' === $src[ $i ] ) {
                        $depth--;
                        if ( 0 === $depth ) { $i++; break; }
                    }
                    if ( "\n" === $src[ $i ] ) { $line++; }
                    $i++;
                }
                $start = $line;
                continue;
            }

            $close = strpos( $src, '}', $i );
            $close = false === $close ? $len : $close;
            $body  = substr( $src, $i + 1, $close - $i - 1 );

            $rules[] = array(
                'selector' => preg_replace( '/\s+/', ' ', $sel ),
                'decls'    => css_decls( $body ),
                'line'     => $start,
            );

            $line += substr_count( substr( $src, $i, $close - $i + 1 ), "\n" );
            $i = $close + 1;
            $start = $line;
            continue;
        }

        $buf .= $ch;
        if ( '' === trim( $buf ) ) { $start = $line; }
        $i++;
    }

    return $rules;
}

function css_decls( $body ) {
    $out = array();
    foreach ( explode( ';', $body ) as $piece ) {
        $piece = trim( $piece );
        if ( '' === $piece || false === strpos( $piece, ':' ) ) { continue; }
        list( $prop, $val ) = explode( ':', $piece, 2 );
        $prop = strtolower( trim( $prop ) );
        $val  = trim( preg_replace( '/\s+/', ' ', $val ) );
        if ( '' === $prop || '' === $val ) { continue; }
        $out[ $prop ] = $val;
    }
    return $out;
}

/**
 * Specificity of one compound selector, as (a,b,c).
 *
 * :where() CONTRIBUTES ZERO, which is the whole reason this file uses it and
 * therefore the one thing this function must not get wrong. Its contents are
 * removed before anything is counted.
 */
function css_spec( $sel ) {
    while ( false !== stripos( $sel, ':where(' ) ) {
        $pos = stripos( $sel, ':where(' );
        $i   = $pos + 7;
        $d   = 1;
        $len = strlen( $sel );
        while ( $i < $len && $d > 0 ) {
            if ( '(' === $sel[ $i ] ) { $d++; }
            if ( ')' === $sel[ $i ] ) { $d--; }
            $i++;
        }
        $sel = substr( $sel, 0, $pos ) . ' ' . substr( $sel, $i );
    }

    // :not(), :is() and :has() contribute their most specific argument; for
    // this file counting the arguments is close enough and never under-counts.
    $sel = str_ireplace( array( ':not(', ':is(', ':has(' ), ' (', $sel );

    $a = preg_match_all( '/#[A-Za-z0-9_-]+/', $sel );
    $b = preg_match_all( '/\.[A-Za-z0-9_-]+/', $sel )
       + preg_match_all( '/\[[^\]]+\]/', $sel )
       + preg_match_all( '/:(?!:)[a-z-]+/i', $sel );
    $c = preg_match_all( '/(^|[\s>+~(])([a-z][a-z0-9]*)/i', $sel )
       + preg_match_all( '/::[a-z-]+/i', $sel );

    return array( $a, $b, $c );
}

function spec_str( $s ) { return '(' . $s[0] . ',' . $s[1] . ',' . $s[2] . ')'; }

/* =====================================================================
 * Which kind of control a selector is talking about.
 * ================================================================== */

/**
 * Selectors that style a text-entry control's own appearance.
 *
 * THE :not() CONTENTS ARE REMOVED FIRST, and leaving them in is what made the
 * first run of this audit report that the baseline declared no fill. The
 * baseline names every type it is NOT — checkbox, radio, submit, file — inside
 * a :not(), so a naive exclusion test sees "checkbox" and throws away the one
 * rule the whole check is about. An audit that fails to find the thing it is
 * looking for reports the absence, not the mistake.
 */
function targets_text_control( $sel ) {
    while ( false !== stripos( $sel, ':not(' ) ) {
        $pos = stripos( $sel, ':not(' );
        $i   = $pos + 5;
        $d   = 1;
        $len = strlen( $sel );
        while ( $i < $len && $d > 0 ) {
            if ( '(' === $sel[ $i ] ) { $d++; }
            if ( ')' === $sel[ $i ] ) { $d--; }
            $i++;
        }
        $sel = substr( $sel, 0, $pos ) . substr( $sel, $i );
    }
    if ( preg_match( '/input\[type\s*=\s*[\'"]?(checkbox|radio|submit|button|reset|file|hidden|range|color)/i', $sel ) ) {
        return false;
    }
    if ( preg_match( '/::(file-selector-button|-webkit-calendar-picker-indicator|placeholder)/i', $sel ) ) {
        return false;
    }
    return (bool) preg_match( '/(^|[\s>+~,(])(input|select|textarea)([\s\[:.,)]|$)/i', $sel );
}

/**
 * A state, not the resting appearance: locked, invalid, chosen, hovered.
 *
 * :where() CONTENTS COME OUT FIRST. What is inside one is a list of what an
 * element IS, never what state it is in, and the baseline's list names every
 * input type it excludes inside a :not() inside a :where(). Left in, the
 * baseline reads as a state rule and drops out of every check that matters.
 */
function is_state_selector( $sel ) {
    while ( false !== stripos( $sel, ':where(' ) ) {
        $pos = stripos( $sel, ':where(' );
        $i   = $pos + 7;
        $d   = 1;
        $len = strlen( $sel );
        while ( $i < $len && $d > 0 ) {
            if ( '(' === $sel[ $i ] ) { $d++; }
            if ( ')' === $sel[ $i ] ) { $d--; }
            $i++;
        }
        $sel = substr( $sel, 0, $pos ) . ' ' . substr( $sel, $i );
    }
    return (bool) preg_match(
        '/(:hover|:focus|:active|:checked|:disabled|:invalid|:not\(|\[disabled\]|\[aria-|\[open\]|'
        . 'uc-field-locked|uc-field-nobulk|uc-field-attention|uc-faq-row-locked|uc-invalid|'
        . 'uc-searching|is-on|uc-scope-btn-all|uc-remove-option-danger)/i',
        $sel
    );
}

/* The properties that decide WHAT KIND of control something reads as. Width,
   flex and margin are layout and are allowed to differ per place; these are
   not. */
$IDENTITY = array( 'background', 'background-color', 'border', 'border-radius', 'padding', 'font-size' );

$src   = file_get_contents( $css );
$rules = css_rules( $src );

$fail  = array();

/* =====================================================================
 * CHECK 1. A text control's appearance is declared in ONE place.
 *
 * Thirteen rules declared it before 3.64.0, seven of them giving the control
 * the DECORATIVE hairline --p-border (1.26:1) instead of the boundary token
 * --p-border-strong (3.33:1), which DESIGN.md reserves for exactly this. So
 * the same select had a visible edge in the event editor and effectively none
 * on the Users screen.
 * ================================================================== */

$text_decls = array();
foreach ( $rules as $r ) {
    if ( ! targets_text_control( $r['selector'] ) ) { continue; }
    if ( is_state_selector( $r['selector'] ) ) { continue; }
    $hits = array_intersect_key( $r['decls'], array_flip( $IDENTITY ) );
    if ( ! $hits ) { continue; }
    $text_decls[] = array( 'sel' => $r['selector'], 'line' => $r['line'], 'decls' => $hits );
}

if ( 'report' !== $mode ) {
    foreach ( $text_decls as $d ) {
        $spec = css_spec( $d['sel'] );
        if ( 0 === $spec[0] && 0 === $spec[1] && 0 === $spec[2] ) {
            continue; // The baseline. This is the one place allowed to say it.
        }
        /*
         * RESTATING THE FACE IS NOT A SECOND DEFINITION. A field inside a
         * tinted row has to say white out loud, because the surface under it
         * is not white and "white means you type in this" is the statement
         * being made. It is allowed as long as it names the token and says
         * nothing else about the box.
         */
        if ( array( 'background-color' ) === array_keys( $d['decls'] )
            && false !== strpos( $d['decls']['background-color'], '--p-control-face' ) ) {
            continue;
        }
        $fail[] = sprintf(
            'portal.css:%d  a text control appearance declared outside the baseline: `%s` %s sets %s',
            $d['line'], $d['sel'], spec_str( $spec ), implode( ', ', array_keys( $d['decls'] ) )
        );
    }
}

/* =====================================================================
 * CHECK 2. No control anywhere takes the decorative hairline as its own
 * boundary.
 *
 * --p-border is the line BETWEEN things. --p-border-strong is a control's own
 * edge and is the only one measured against WCAG 1.4.11's 3:1.
 * ================================================================== */

$CONTROL_CLASSES = array(
    'uc-btn', 'uc-help-btn', 'uc-seg', 'uc-day', 'uc-radio-opt', 'uc-remove-option',
    'uc-picker-filter', 'uc-repeat-num', 'uc-repeat-date', 'uc-email-add',
    'uc-disclosure-chevron', 'uc-add-toggle', 'uc-team-add-toggle',
);

foreach ( $rules as $r ) {
    $sel = $r['selector'];
    $is_control = targets_text_control( $sel );
    foreach ( $CONTROL_CLASSES as $cls ) {
        if ( preg_match( '/\.' . preg_quote( $cls, '/' ) . '($|[\s,:>.\[])/', $sel ) ) { $is_control = true; }
    }
    if ( ! $is_control || is_state_selector( $sel ) ) { continue; }

    foreach ( array( 'border', 'border-color', 'border-width' ) as $prop ) {
        if ( ! isset( $r['decls'][ $prop ] ) ) { continue; }
        $val = $r['decls'][ $prop ];
        if ( preg_match( '/var\(\s*--p-border\s*[,)]/', $val ) ) {
            $fail[] = sprintf(
                'portal.css:%d  `%s` takes the decorative hairline as a control boundary: %s: %s',
                $r['line'], $sel, $prop, $val
            );
        }
    }
}

/* =====================================================================
 * CHECK 3. A BUTTON AND A FIELD MAY NOT SHARE A FILL.
 *
 * This is the reported complaint stated as an assertion. Until 3.64.0
 * `.uc-btn` was `background: #fff` and a text input was `background-color:
 * #fff`, with the same 1px --p-border-strong and the same 8px radius, so the
 * only difference between "you press this" and "you type in this" was
 * font-weight. DESIGN.md's own rule from 3.61.0 is that white means "you type
 * in this, or it opens"; a button must therefore have a surface.
 * ================================================================== */

$field_fill = null;
$btn_fill   = null;

foreach ( $rules as $r ) {
    $spec = css_spec( $r['selector'] );
    if ( targets_text_control( $r['selector'] ) && ! is_state_selector( $r['selector'] )
        && 0 === $spec[0] && 0 === $spec[1] && 0 === $spec[2] ) {
        foreach ( array( 'background-color', 'background' ) as $p ) {
            if ( isset( $r['decls'][ $p ] ) ) { $field_fill = $r['decls'][ $p ]; }
        }
    }
    foreach ( explode( ',', $r['selector'] ) as $one ) {
        if ( '.uc-btn' !== trim( $one ) ) { continue; }
        foreach ( array( 'background-color', 'background' ) as $p ) {
            if ( isset( $r['decls'][ $p ] ) ) { $btn_fill = $r['decls'][ $p ]; }
        }
    }
}

if ( 'report' !== $mode ) {
    if ( null === $field_fill ) {
        $fail[] = 'The zero-specificity text-control baseline declares no fill. It has to say white out loud, because white is the statement.';
    }
    if ( null === $btn_fill ) {
        $fail[] = '`.uc-btn` declares no fill, so a secondary button is whatever is behind it.';
    }
    if ( null !== $field_fill && null !== $btn_fill
        && strtolower( str_replace( '#ffffff', '#fff', $field_fill ) ) === strtolower( str_replace( '#ffffff', '#fff', $btn_fill ) ) ) {
        $fail[] = sprintf(
            'A secondary button and a text field share one fill (%s). Nothing on the screen says which is pressable.',
            $btn_fill
        );
    }
}

/* =====================================================================
 * CHECK 4. ONE CLASS, DECLARED ONCE.
 *
 * 3.39.0 split a fill from its ink across two declarations of .uc-btn-danger
 * and shipped red on red at 1.19:1. The same shape was live again in 3.63.0:
 * `.uc-disclosure-chevron` was declared at portal.css:1027 as a bare glyph and
 * again at 2381 as a 28px ring, so the cancel disclosure wore a circle nobody
 * chose and the FIRST declaration was dead code that read as if it worked.
 *
 * Same specificity, same property, twice: the later one silently wins and the
 * earlier one is a rule somebody will read and believe.
 * ================================================================== */

$by_class = array();
foreach ( $rules as $r ) {
    /*
     * ONE SELECTOR ONLY. A class that appears in a GROUP is a different thing:
     * `.uc-form-links-open` is listed with `.uc-btn` for the button geometry
     * and again with `.uc-btn-primary` for the yellow fill, which is an alias
     * taking two halves of a definition on purpose and is how this file avoids
     * writing the geometry twice. What this check is for is a class declared
     * ALONE, twice, where the second is invisible to anyone reading the first.
     */
    $parts = array_map( 'trim', explode( ',', $r['selector'] ) );
    if ( 1 !== count( $parts ) ) { continue; }
    if ( ! preg_match( '/^\.([A-Za-z0-9_-]+)$/', $parts[0], $m ) ) { continue; }
    foreach ( $r['decls'] as $prop => $val ) {
        $by_class[ $m[1] ][ $prop ][] = array( 'line' => $r['line'], 'val' => $val );
    }
}

if ( 'report' !== $mode ) {
    foreach ( $by_class as $cls => $props ) {
        foreach ( $props as $prop => $hits ) {
            if ( count( $hits ) < 2 ) { continue; }
            $lines = array();
            foreach ( $hits as $h ) { $lines[] = $h['line'] . ' (' . $h['val'] . ')'; }
            $fail[] = sprintf(
                '`.%s` declares %s %d times at the same specificity: portal.css:%s. All but the last are dead.',
                $cls, $prop, count( $hits ), implode( ', portal.css:', $lines )
            );
        }
    }
}

/* =====================================================================
 * CHECK 5. EVERY DISCLOSURE CARRIES THE SAME MARK.
 *
 * A <summary> with no marker is a heading that happens to be clickable. There
 * were three answers in one stylesheet: the shared .uc-disclosure-chevron
 * element, a text glyph in .uc-picker-toggle::after, and nothing at all.
 * ================================================================== */

$php_files = array(
    $root . '/includes/class-sfaf-portal.php',
    $root . '/includes/class-sfaf-faq-sets.php',
);

/** The renderers that emit caladmin markup, for the checks that read it. */
function php_files_for_markup() {
    $root = dirname( __DIR__ );
    return array(
        $root . '/includes/class-sfaf-portal.php',
        $root . '/includes/class-sfaf-faq-sets.php',
        $root . '/includes/class-sfaf-request.php',
        $root . '/includes/class-sfaf-submit.php',
    );
}

$summaries = array();
foreach ( $php_files as $file ) {
    if ( ! is_file( $file ) ) { continue; }
    $lines = explode( "\n", file_get_contents( $file ) );
    foreach ( $lines as $n => $line ) {
        if ( ! preg_match( '/<summary\b/i', $line ) ) { continue; }
        /*
         * A COMMENT THAT SAYS "<summary>" IS NOT ONE, and this file is full of
         * them, because every disclosure carries a paragraph explaining why it
         * is a real interactive element rather than a styled div. Without this
         * the audit reported two findings that were prose.
         */
        $before = trim( substr( $line, 0, stripos( $line, '<summary' ) ) );
        $before = trim( preg_replace( '~^<\?php~', '', $before ) );
        if ( '' !== $before && preg_match( '~^(\*|//|/\*)~', $before ) ) { continue; }
        // The marker may be on this line or on one of the next few, because a
        // summary with a count or a badge in it is written over several lines.
        $window = implode( ' ', array_slice( $lines, $n, 10 ) );
        $stop   = strpos( $window . '</summary>', '</summary>' );
        $window = substr( $window, 0, $stop );
        $summaries[] = array(
            'file'   => basename( $file ),
            'line'   => $n + 1,
            'marked' => (bool) preg_match( '/uc-disclosure-chevron/', $window ),
            'text'   => trim( preg_replace( '/\s+/', ' ', substr( $window, 0, 110 ) ) ),
        );
    }
}

if ( 'report' !== $mode ) {
    foreach ( $summaries as $s ) {
        if ( $s['marked'] ) { continue; }
        $fail[] = sprintf(
            '%s:%d  a <summary> with no disclosure mark: %s',
            $s['file'], $s['line'], $s['text']
        );
    }
}

/* =====================================================================
 * CHECK 7. A VARIANT THAT REPLACES THE BUTTON FACE MUST REPLACE IT ON HOVER
 * TOO.
 *
 * THIS TRAP WAS SPRUNG THREE TIMES BY 3.64.0 ITSELF, which is why it is a
 * check and not a note. `.uc-btn` had no resting background worth the name
 * until this build, so `.uc-btn:hover` set none either, and five variants
 * quietly relied on their own resting fill surviving the hover: the yellow
 * primary, the yellow Get-a-form-link pill, the amber all-occurrences scope
 * button, and the two quiet link-shaped actions that must have no surface at
 * all. The moment `.uc-btn:hover` named a fill, all five hovered to grey.
 *
 * It is the 3.39.0 lesson in the other direction. There, a second declaration
 * set an ink and inherited a fill and got red on red. Here a state rule
 * inherits a fill from a resting rule and is correct right up until the
 * resting rule changes. Either way: DECLARE THE PAIR.
 * ================================================================== */

$rest_fill  = array();
$hover_fill = array();
foreach ( $rules as $r ) {
    $bg = null;
    foreach ( array( 'background', 'background-color' ) as $p ) {
        if ( isset( $r['decls'][ $p ] ) ) { $bg = $r['decls'][ $p ]; }
    }
    if ( null === $bg ) { continue; }
    foreach ( explode( ',', $r['selector'] ) as $one ) {
        $one = trim( $one );
        if ( preg_match( '/^\.([A-Za-z0-9_-]+):hover$/', $one, $m ) ) {
            $hover_fill[ $m[1] ] = $r['line'];
        } elseif ( preg_match( '/^\.([A-Za-z0-9_-]+)$/', $one, $m ) ) {
            $rest_fill[ $m[1] ] = $r['line'];
        }
    }
}

if ( 'report' !== $mode ) {
    foreach ( $rest_fill as $cls => $line ) {
        if ( 'uc-btn' === $cls ) { continue; }
        // Only classes that are actually worn alongside .uc-btn in the markup.
        /*
         * WHOLE CLASS TOKENS, NOT \b. A word boundary sits happily in the
         * middle of a hyphenated class, so `\buc-scope\b` matches
         * `uc-scope-btn` and this check reported that the edit-scope PANEL was
         * a button with a missing hover. Split the attribute and compare.
         */
        $worn = false;
        foreach ( php_files_for_markup() as $file ) {
            if ( ! is_file( $file ) ) { continue; }
            if ( ! preg_match_all( '/class="([^"]*)"/', file_get_contents( $file ), $mm ) ) { continue; }
            foreach ( $mm[1] as $attr ) {
                $tokens = preg_split( '/\s+/', trim( $attr ) );
                if ( in_array( 'uc-btn', $tokens, true ) && in_array( $cls, $tokens, true ) ) {
                    $worn = true;
                    break 2;
                }
            }
        }
        if ( ! $worn || isset( $hover_fill[ $cls ] ) ) { continue; }
        $fail[] = sprintf(
            'portal.css:%d  `.%s` replaces the button face but names no fill on :hover, so it hovers to the secondary grey.',
            $line, $cls
        );
    }
}

/* =====================================================================
 * CHECK 6. THE MEASURED PAIRS.
 *
 * The secondary button's face is the one new value in 3.64.0 and it is the
 * one that had to be measured rather than picked: too light and it is white
 * again, too dark and it reads as the DISABLED state, which in this portal is
 * a pale fill with muted ink (.uc-field-locked, .uc-field-nobulk).
 * ================================================================== */

$MEASURED = array(
    // label                                        fg         bg         floor
    array( 'secondary ink on its face',            '#373433', '#D1D3D4', 4.5 ),
    array( 'secondary face vs a white card',       '#D1D3D4', '#FFFFFF', 1.45 ),
    array( 'secondary face vs the page',           '#D1D3D4', '#F5F6F7', 1.35 ),
    array( 'secondary face vs the locked fill',    '#D1D3D4', '#F3F4F6', 1.30 ),
    array( 'hovered secondary ink on its face',    '#373433', '#BFC2C4', 4.5 ),
    array( 'hovered face vs the resting face',     '#BFC2C4', '#D1D3D4', 1.15 ),
    array( 'control boundary on a card',           '#8C8D8E', '#FFFFFF', 3.0 ),
    array( 'control boundary on the page',         '#8C8D8E', '#F5F6F7', 3.0 ),
    array( 'primary ink on yellow',                '#373433', '#FFD900', 4.5 ),
    array( 'chosen segment: white on teal ink',    '#FFFFFF', '#0E7680', 4.5 ),
);

$measured_out = array();
foreach ( $MEASURED as $m ) {
    list( $label, $fg, $bg, $floor ) = $m;
    $ratio = ch_ratio( $fg, $bg );
    $ok    = $ratio + 0.005 >= $floor;
    $measured_out[] = sprintf( '  %-44s %s on %s  %5.2f:1  floor %.2f  %s',
        $label, $fg, $bg, $ratio, $floor, $ok ? 'ok' : 'FAIL' );
    if ( ! $ok && 'report' !== $mode ) {
        $fail[] = sprintf( 'measured: %s is %.2f:1, under its %.2f floor', $label, $ratio, $floor );
    }
}

/* =====================================================================
 * Output.
 * ================================================================== */

if ( 'report' === $mode ) {
    echo "TEXT CONTROLS: every rule declaring one's appearance\n";
    foreach ( $text_decls as $d ) {
        printf( "  %-5d %-9s %s\n", $d['line'], spec_str( css_spec( $d['sel'] ) ), $d['sel'] );
        foreach ( $d['decls'] as $p => $v ) { printf( "          %s: %s\n", $p, $v ); }
    }
    echo "\nDISCLOSURES\n";
    foreach ( $summaries as $s ) {
        printf( "  %s %s:%d  %s\n", $s['marked'] ? 'mark' : 'NONE', $s['file'], $s['line'], $s['text'] );
    }
    echo "\nMEASURED\n" . implode( "\n", $measured_out ) . "\n";
    exit( 0 );
}

if ( 'selftest' === $mode ) {
    /*
     * A NEW CHECKER FAILS ON PURPOSE BEFORE IT IS TRUSTED (3.20.0). Two of
     * these are the traps that made earlier audits report success over
     * nothing: :where() counted as specificity, and a comment containing a
     * brace read as a rule.
     */
    $t = 0; $bad = 0;
    $probe = function ( $label, $got, $want ) use ( &$t, &$bad ) {
        $t++;
        $ok = $got === $want;
        if ( ! $ok ) { $bad++; }
        printf( "  %s  %-52s got %s want %s\n", $ok ? 'ok  ' : 'FAIL', $label,
            var_export( $got, true ), var_export( $want, true ) );
    };

    $probe( ':where() contributes zero',
        spec_str( css_spec( ':where(.uc-portal) :where(input, select)' ) ), '(0,0,0)' );
    $probe( 'one class plus one type',
        spec_str( css_spec( '.uc-portal a' ) ), '(0,1,1)' );
    $probe( 'one class alone',
        spec_str( css_spec( '.uc-nav-item' ) ), '(0,1,0)' );
    $probe( 'a comment holding a brace is not a rule',
        count( css_rules( '/* a { b } */ .x { color: red; }' ) ), 1 );
    // The one that made this audit lie to itself.
    $r = css_rules( ".x {\n  padding: 1px;\n  /* note: with a colon in it */\n  background: red;\n}" );
    $probe( 'a comment INSIDE a block does not eat the next declaration',
        isset( $r[0]['decls']['background'] ) ? $r[0]['decls']['background'] : null, 'red' );
    $probe( 'and does not invent a property of its own',
        count( $r[0]['decls'] ), 2 );
    $probe( 'a comment keeps the line numbers true',
        css_rules( "/* one\ntwo */\n.x { color: red }" )[0]['line'], 3 );
    $probe( 'an @media block is skipped whole',
        count( css_rules( '@media (min-width: 1px) { .a { color: red } } .b { color: blue }' ) ), 1 );
    $probe( 'checkbox is not a text control',
        targets_text_control( '.uc-field input[type="checkbox"]' ), false );
    $probe( 'a bare select is a text control',
        targets_text_control( '.uc-inline-form select' ), true );
    $probe( 'a hover rule is a state',
        is_state_selector( '.uc-btn:hover' ), true );
    $probe( 'a resting rule is not a state',
        is_state_selector( '.uc-btn' ), false );
    $probe( 'the baseline is not a state rule',
        is_state_selector( ':where(.uc-portal) :where(input:not([type="checkbox"]), select)' ), false );
    $probe( 'the baseline is a text control',
        targets_text_control( ':where(.uc-portal) :where(input:not([type="checkbox"]), select)' ), true );
    $probe( 'contrast: black on white',
        round( ch_ratio( '#000000', '#ffffff' ), 2 ), 21.0 );
    $probe( 'contrast: the known #0E7680 on white',
        round( ch_ratio( '#0E7680', '#ffffff' ), 2 ), 5.35 );
    $probe( 'contrast: the known brand teal on white',
        round( ch_ratio( '#16BECF', '#ffffff' ), 2 ), 2.26 );

    // The checks themselves must be able to fail. Each of these is the exact
    // defect the corresponding check exists to catch.
    $probe( 'check 2 sees a hairline used as a boundary',
        (bool) preg_match( '/var\(\s*--p-border\s*[,)]/', '1px solid var(--p-border)' ), true );
    $probe( 'check 2 passes the boundary token',
        (bool) preg_match( '/var\(\s*--p-border\s*[,)]/', '1px solid var(--p-border-strong)' ), false );

    echo "\n$t checks, $bad failed\n";
    exit( $bad ? 1 : 0 );
}

echo "CONTROL STANDARD AUDIT\n\n";
echo "Measured pairs:\n" . implode( "\n", $measured_out ) . "\n\n";

if ( $fail ) {
    echo count( $fail ) . ' FAILURE' . ( 1 === count( $fail ) ? '' : 'S' ) . ":\n";
    foreach ( $fail as $f ) { echo "  - $f\n"; }
    exit( 1 );
}

echo "PASS. One definition per kind of control.\n";
exit( 0 );
