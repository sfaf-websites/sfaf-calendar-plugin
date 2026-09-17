<?php
/**
 * EVERY SUBMIT BUTTON HAS A FORM, AND THAT FORM IS ON THE SAME PAGE.
 *
 *     php .claude/form-owner-audit.php
 *     php .claude/form-owner-audit.php --self-test
 *     php .claude/form-owner-audit.php --report      every button and its owner
 *
 * THE FAULT THIS EXISTS FOR. 3.94.0 moved the event editor's button row outside
 * the event form and gave Save draft a `form=` attribute. Publish and Submit
 * for Review were not touched, so both had NO FORM OWNER and pressing either
 * did nothing at all: no error, no refusal, no submit event, and therefore
 * nothing in portal.js either, because every handler there listens on a form.
 * It was live through 3.94.0, 3.95.0, 3.95.1, 3.96.0 and 3.97.0.
 *
 * NOTHING COULD HAVE CAUGHT IT. The file parses. The callable audit is clean.
 * The button is there, it is a submit, it has a label and its class is right,
 * and a rendering test that counts buttons finds it. What was wrong is a
 * RELATIONSHIP between two elements, and a relationship is invisible to every
 * question asked one element at a time.
 *
 * SO THIS READS THE TAG STREAM, NOT LINES.
 * ---------------------------------------------------------------------------
 * PROJECT.md 7 records the 3.79.0 checker failing twice on exactly this: a
 * regex over a line cannot see whether a form was opened above and closed
 * below, because that is a fact about ORDER across a file. The whole question
 * here is order, so the reader is a scanner over the emitted markup in
 * sequence.
 *
 * HOW THE MARKUP IS RECOVERED. `token_get_all()` hands back T_INLINE_HTML for
 * everything outside `<?php ?>`, in order. Concatenating those, with a marker
 * where each PHP block was, gives exactly the literal HTML the file emits with
 * the dynamic parts flagged. A `form="<?php echo $x; ?>"` therefore arrives as
 * `form="{{PHP:…}}"`, which is enough to see that the attribute is THERE, which
 * is the thing that was missing. It also means a `<form>` written inside a PHP
 * comment or a PHP string is never mistaken for markup, which is the trap a
 * raw-text scanner falls into.
 *
 * AND IT FOLLOWS ONE METHOD CALL, BECAUSE THE FIRST VERSION CRIED WOLF.
 * ---------------------------------------------------------------------------
 * The public filter bar's Apply button is written in `render_who_picker()` and
 * that method is CALLED from inside `<form class="uc-filter-form">` in another
 * method further down the same file. Read as one flat stream the button looks
 * ownerless, and the first version of this reported it as the bug. It is not:
 * at runtime it is inside the form.
 *
 * A checker that reports a healthy control as broken is worse than no checker,
 * because the next person learns to ignore it. So the scan is per METHOD, and
 * a method whose every call site sits inside an open form has its buttons
 * treated as inside one. That is one level of indirection, which is what the
 * code actually does; a button three renderers deep would still be reported,
 * and should be, because nobody can follow that by eye either.
 *
 * WHAT IT DECIDES, per submit button:
 *
 *   inside an open <form>         fine
 *   in a method always called
 *     from inside a form          fine
 *   outside, with form="id"       fine IF that id is opened in the same file
 *   outside, with no form=        A FAULT. This is the 3.94.0 bug.
 *
 * WHY "in the same file" AND NOT "above it". `form=` is an association by id,
 * not by position: the delete form is deliberately emitted AFTER the row whose
 * button points at it, because forms cannot nest. The id must exist on the
 * page; where is not the question.
 *
 * TWO KINDS OF ID, AND BOTH ARE CHECKED. A literal `id="uc-thing"` matches
 * literally. A PHP-built one matches on the EXPRESSION: a button saying
 * `form="<?php echo esc_attr( $form_id ); ?>"` and a form saying
 * `id="<?php echo esc_attr( $form_id ); ?>"` are the same id by construction.
 * Comparing rendered values is impossible here; comparing what the author
 * wrote is exactly right.
 */

$root = dirname( __DIR__ );

$mode = 'check';
if ( in_array( '--self-test', $argv, true ) ) { $mode = 'self'; }
if ( in_array( '--report', $argv, true ) )    { $mode = 'report'; }

$fails = array();

/* ---------------------------------------------------------------------------
 * RECOVERING THE MARKUP, AND THE CALLS INSIDE IT.
 * ------------------------------------------------------------------------ */
/**
 * Turn PHP source into the literal HTML it emits, with two kinds of marker.
 *
 *   {{PHP:hash}}    a PHP block, hashed on its own normalised text so two
 *                   blocks writing the same expression compare equal
 *   {{CALL:name}}   a `$this->name(` inside that block, so the scanner can
 *                   record the form depth at each call site
 *
 * @param string $src PHP source.
 * @return string
 */
function fo_markup( $src ) {
    $out = '';
    $php = '';
    $in  = false;

    $flush = function () use ( &$out, &$php, &$in ) {
        if ( ! $in ) { return; }
        $calls = array();
        if ( preg_match_all( '/\$this->([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $php, $m ) ) {
            $calls = $m[1];
        }
        $out .= '{{PHP:' . md5( preg_replace( '/\s+/', ' ', trim( $php ) ) ) . '}}';
        foreach ( $calls as $c ) {
            $out .= '{{CALL:' . $c . '}}';
        }
        $php = '';
        $in  = false;
    };

    foreach ( token_get_all( $src ) as $t ) {
        if ( is_array( $t ) && T_INLINE_HTML === $t[0] ) {
            $flush();
            $out .= $t[1];
            continue;
        }
        $in   = true;
        $php .= is_array( $t ) ? $t[1] : $t;
    }
    $flush();
    return $out;
}

/**
 * Walk the tag stream and report what it found, in order.
 *
 * A SCANNER, NOT A PATTERN: it reads tags one at a time and keeps a form depth,
 * which is the only way to know that a form opened far above had closed before
 * a button far below.
 *
 * @param string $markup
 * @return array{buttons:array,ids:array,calls:array}
 */
function fo_scan( $markup ) {
    $buttons = array();
    $ids     = array();
    $calls   = array();
    $depth   = 0;
    $len     = strlen( $markup );
    $i       = 0;

    while ( $i < $len ) {
        /* A call marker is not a tag, so it is picked up on the way past. */
        $next_call = strpos( $markup, '{{CALL:', $i );
        $next_lt   = strpos( $markup, '<', $i );

        if ( false !== $next_call && ( false === $next_lt || $next_call < $next_lt ) ) {
            $end = strpos( $markup, '}}', $next_call );
            if ( false === $end ) { break; }
            $name = substr( $markup, $next_call + 7, $end - $next_call - 7 );
            $calls[] = array( 'name' => $name, 'depth' => $depth );
            $i = $end + 2;
            continue;
        }
        if ( false === $next_lt ) { break; }

        $gt = strpos( $markup, '>', $next_lt );
        if ( false === $gt ) { break; }
        $tag = substr( $markup, $next_lt, $gt - $next_lt + 1 );
        $i   = $gt + 1;

        if ( ! preg_match( '#^</?([a-zA-Z][a-zA-Z0-9]*)#', $tag, $m ) ) {
            continue;
        }
        $name  = strtolower( $m[1] );
        $close = ( '</' === substr( $tag, 0, 2 ) );

        if ( 'form' === $name ) {
            if ( $close ) {
                $depth = max( 0, $depth - 1 );
            } else {
                $depth++;
                if ( preg_match( '#\sid\s*=\s*"([^"]*)"#i', $tag, $idm ) ) {
                    $ids[] = $idm[1];
                }
            }
            continue;
        }

        if ( ( 'button' !== $name && 'input' !== $name ) || $close ) {
            continue;
        }

        /*
         * IS IT A SUBMIT. A <button> with NO type attribute is a submit button:
         * that is the HTML default, and it is the case somebody forgets.
         */
        $has_type  = preg_match( '#\stype\s*=\s*"([^"]*)"#i', $tag, $tm );
        $type      = $has_type ? strtolower( $tm[1] ) : '';
        $computed  = ( $has_type && false !== strpos( $type, '{{php' ) );
        if ( 'button' === $name ) {
            $is_submit = ( ! $has_type || 'submit' === $type || $computed );
        } else {
            $is_submit = ( 'submit' === $type || 'image' === $type || $computed );
        }
        if ( ! $is_submit ) { continue; }

        $owner = null;
        if ( preg_match( '#\sform\s*=\s*"([^"]*)"#i', $tag, $fm ) ) {
            $owner = $fm[1];
        }

        $buttons[] = array(
            'tag'    => preg_replace( '/\s+/', ' ', $tag ),
            'inside' => ( $depth > 0 ),
            'owner'  => $owner,
        );
    }

    return array( 'buttons' => $buttons, 'ids' => $ids, 'calls' => $calls );
}

/**
 * Split a PHP file into its method bodies, by name.
 *
 * Brace-balanced from the tokenizer rather than by pattern, for the reason
 * PROJECT.md 7 gives about `function &name()` and `"{$a}"`: a regex over a
 * declaration is not a parse.
 *
 * @param string $src
 * @return array name => source text of the body
 */
function fo_methods( $src ) {
    $toks = token_get_all( $src );
    $out  = array();
    $n    = count( $toks );

    for ( $i = 0; $i < $n; $i++ ) {
        if ( ! is_array( $toks[ $i ] ) || T_FUNCTION !== $toks[ $i ][0] ) {
            continue;
        }
        // The name, skipping whitespace and a by-reference ampersand.
        $j = $i + 1;
        while ( $j < $n && ( ( is_array( $toks[ $j ] ) && T_WHITESPACE === $toks[ $j ][0] ) || '&' === $toks[ $j ] ) ) {
            $j++;
        }
        if ( $j >= $n || ! is_array( $toks[ $j ] ) || T_STRING !== $toks[ $j ][0] ) {
            continue; // a closure
        }
        $name = $toks[ $j ][1];

        // Walk to the opening brace, then balance.
        $k = $j;
        while ( $k < $n && '{' !== $toks[ $k ] ) {
            if ( ';' === $toks[ $k ] ) { break; } // abstract or interface
            $k++;
        }
        if ( $k >= $n || '{' !== $toks[ $k ] ) { continue; }

        $depth = 0;
        $body  = '';
        for ( $m = $k; $m < $n; $m++ ) {
            $t    = $toks[ $m ];
            $text = is_array( $t ) ? $t[1] : $t;
            if ( '{' === $t ) { $depth++; }
            if ( is_array( $t ) && in_array( $t[0], array( T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ), true ) ) {
                $depth++;
            }
            $body .= $text;
            if ( '}' === $t ) {
                $depth--;
                if ( 0 === $depth ) { break; }
            }
        }
        $out[ $name ] = $body;
    }
    return $out;
}

/* ---------------------------------------------------------------------------
 * THE FILES.
 *
 * NOT ONLY THE PORTAL. A submit button with no form owner is inert wherever it
 * is drawn, so the sweep takes every renderer that emits one: the caladmin
 * screens, both public forms, the public calendar's own filter bar, the test
 * send control and the WordPress admin.
 * ------------------------------------------------------------------------ */
$files = array(
    'includes/class-sfaf-portal.php',
    'includes/class-sfaf-request.php',
    'includes/class-sfaf-submit.php',
    'includes/class-sfaf-submissions.php',
    'includes/class-sfaf-media.php',
    'includes/class-sfaf-cancellation.php',
    'includes/class-sfaf-follow.php',
    'includes/class-sfaf-rich-text.php',
    'includes/class-sfaf-reminders.php',
    'includes/class-sfaf-shortcodes.php',
    'admin/class-sfaf-admin.php',
);

$rows    = array();
$checked = 0;

foreach ( $files as $rel ) {
    $path = $root . '/' . $rel;
    if ( ! file_exists( $path ) ) { continue; }
    $src = file_get_contents( $path );

    /* The whole file, for the ids it opens and for the call sites' depths. */
    $whole = fo_scan( fo_markup( $src ) );
    $ids   = $whole['ids'];

    /* Which methods are only ever called from inside an open form. */
    $call_depth = array();
    foreach ( $whole['calls'] as $c ) {
        if ( ! isset( $call_depth[ $c['name'] ] ) ) {
            $call_depth[ $c['name'] ] = array( 'in' => 0, 'out' => 0 );
        }
        $call_depth[ $c['name'] ][ $c['depth'] > 0 ? 'in' : 'out' ]++;
    }

    foreach ( fo_methods( $src ) as $method => $body ) {
        $scan = fo_scan( fo_markup( '<?php ' . $body ) );
        foreach ( $scan['buttons'] as $b ) {
            $checked++;
            $short = substr( $b['tag'], 0, 92 );

            if ( $b['inside'] ) {
                $rows[] = array( $rel, $method, 'inside a form', $short );
                continue;
            }

            /*
             * RESCUED BY ITS CALLER. A method whose every call site is inside
             * an open form emits its markup inside that form, so a button here
             * is inside one too. Only when EVERY call site is: a method called
             * from two places, one of them outside, is a real hazard.
             */
            if ( isset( $call_depth[ $method ] )
                && $call_depth[ $method ]['in'] > 0
                && 0 === $call_depth[ $method ]['out'] ) {
                $rows[] = array( $rel, $method, 'inside its caller\'s form', $short );
                continue;
            }

            if ( null === $b['owner'] ) {
                $fails[] = sprintf(
                    '%s::%s  a submit button with no form around it and no form attribute, so pressing it does nothing: %s',
                    $rel,
                    $method,
                    $short
                );
                $rows[] = array( $rel, $method, 'NO OWNER', $short );
                continue;
            }
            if ( ! in_array( $b['owner'], $ids, true ) ) {
                $fails[] = sprintf(
                    '%s::%s  a submit button names form="%s", and no form with that id is opened in this file: %s',
                    $rel,
                    $method,
                    $b['owner'],
                    $short
                );
                $rows[] = array( $rel, $method, 'DANGLING', $short );
                continue;
            }
            $rows[] = array(
                $rel,
                $method,
                'form=' . ( false !== strpos( $b['owner'], '{{PHP' ) ? '(computed)' : $b['owner'] ),
                $short
            );
        }
    }
}

/* ---------------------------------------------------------------------------
 * SELF-TEST.
 * ------------------------------------------------------------------------ */
if ( 'self' === $mode ) {
    $bad = array();

    if ( $fails ) {
        echo "SELF-TEST FAILED: the clean tree already fails.\n";
        foreach ( $fails as $f ) { echo '  . ' . $f . "\n"; }
        exit( 1 );
    }

    $worlds = array(
        'a submit button after the form closes, with no form attribute' =>
            array( '<form id="f1"><input type="text"></form><button type="submit">Publish</button>', 'fail' ),
        'the same button with a form attribute naming that form' =>
            array( '<form id="f1"></form><button type="submit" form="f1">Publish</button>', 'pass' ),
        'a form attribute naming a form that is not on the page' =>
            array( '<form id="f1"></form><button type="submit" form="nowhere">Publish</button>', 'fail' ),
        'a button inside the form needs no attribute' =>
            array( '<form id="f1"><button type="submit">Save</button></form>', 'pass' ),
        'a button with NO type is a submit button by default' =>
            array( '<form id="f1"></form><button>Publish</button>', 'fail' ),
        'a plain button is not a submit button' =>
            array( '<form id="f1"></form><button type="button">Open</button>', 'pass' ),
        'the form the button points at may be emitted AFTER it' =>
            array( '<button type="submit" form="f2">Delete</button><form id="f2"></form>', 'pass' ),
        'a nested element does not end the form early' =>
            array( '<form id="f1"><div></div><button type="submit">Save</button></form>', 'pass' ),
        'two forms, and the button falls between them' =>
            array( '<form id="f1"></form><button type="submit">X</button><form id="f2"></form>', 'fail' ),
    );

    foreach ( $worlds as $name => $w ) {
        list( $markup, $expect ) = $w;
        $s   = fo_scan( $markup );
        $bad_here = false;
        foreach ( $s['buttons'] as $b ) {
            if ( $b['inside'] ) { continue; }
            if ( null === $b['owner'] || ! in_array( $b['owner'], $s['ids'], true ) ) { $bad_here = true; }
        }
        $got = $bad_here ? 'fail' : 'pass';
        if ( $got !== $expect ) {
            $bad[] = $name . ': the scanner said ' . $got . ', expected ' . $expect;
        }
    }

    /* PHP IS NOT MARKUP. A `<form` in a comment or a string must not open one,
     * which is the trap a raw-text scanner falls into. */
    $ghost = "<?php\n/* <form id=\"ghost\"> */\n\$s = '<form id=\"also\">';\n?>\n<button type=\"submit\">Publish</button>\n";
    $s = fo_scan( fo_markup( $ghost ) );
    if ( ! empty( $s['ids'] ) ) {
        $bad[] = 'a <form> inside a PHP comment or string was read as markup';
    }
    if ( 1 !== count( $s['buttons'] ) || null !== $s['buttons'][0]['owner'] || $s['buttons'][0]['inside'] ) {
        $bad[] = 'the button after that PHP was not seen as an ownerless submit';
    }

    /* AND THE ONE LEVEL OF INDIRECTION, which is what stopped this crying wolf
     * over the public filter bar's Apply button. */
    $indirect = "<?php class X {\n"
        . "function inner() { ?><button type=\"submit\">Apply</button><?php }\n"
        . "function outer() { ?><form id=\"f\"><?php \$this->inner(); ?></form><?php }\n"
        . "}\n";
    $w = fo_scan( fo_markup( $indirect ) );
    $depths = array();
    foreach ( $w['calls'] as $c ) { $depths[ $c['name'] ] = $c['depth']; }
    if ( ! isset( $depths['inner'] ) || $depths['inner'] < 1 ) {
        $bad[] = 'a call made from inside a form was not recorded at a form depth, so the wolf-crying fix does not work';
    }
    $ms = fo_methods( $indirect );
    if ( ! isset( $ms['inner'] ) || ! isset( $ms['outer'] ) ) {
        $bad[] = 'the method splitter did not find both methods';
    }

    if ( $bad ) {
        echo "SELF-TEST FAILED\n";
        foreach ( $bad as $b ) { echo '  . ' . $b . "\n"; }
        exit( 1 );
    }
    echo "PASS  form-owner --self-test         self-test passed: order is read as order, PHP is not read as markup, and one call is followed.\n";
    exit( 0 );
}

if ( 'report' === $mode ) {
    echo "EVERY SUBMIT BUTTON, AND WHAT OWNS IT\n\n";
    foreach ( $rows as $r ) {
        printf( "  %-30s %-28s %-24s %s\n", basename( $r[0] ), $r[1], $r[2], $r[3] );
    }
    echo "\n  " . $checked . " submit buttons checked.\n";
    exit( 0 );
}

if ( $fails ) {
    echo 'FAIL: ' . count( $fails ) . "\n";
    foreach ( array_unique( $fails ) as $f ) { echo '  . ' . $f . "\n"; }
    exit( 1 );
}

echo 'every one of the ' . $checked . " submit buttons resolves to a form on its own page:\n";
echo "inside one, inside its caller's, or naming one by an id that file opens.\n";
exit( 0 );
