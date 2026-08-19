<?php
/**
 * EVERY caladmin SCREEN ASKS FOR WHAT IT USES, AND USES ONLY WHAT IT ASKED FOR.
 *
 *     php .claude/screen-assets-test.php
 *     php .claude/screen-assets-test.php --self-test
 *
 * WHY THIS FILE EXISTS. 3.44.0 shipped a screen that returned HTTP 500, past a
 * build gate of thirty-nine checks. PHP lint could never have seen it: the file
 * parsed perfectly. The callable audit could not either, because every function
 * involved exists. The fault was that a function which is only available once
 * something has been ENQUEUED was reached on a request that never enqueued it.
 *
 * That is the third fatal on this project that lint could not see.
 *
 * WHAT WOULD ACTUALLY HAVE CAUGHT IT, AND WHY IT IS NOT WHAT THIS FILE DOES.
 * ---------------------------------------------------------------------------
 * Rendering the screen and asserting a complete document comes back. That is a
 * different class of check from parsing a file, and it is the right one. It
 * cannot be done here: there is no WordPress in this environment, no database
 * and no HTTP server, and stubbing enough of WordPress to render a portal
 * screen would mean this file deciding which functions exist, which is the
 * exact question the bug turned on. A stub set that defines
 * wp_print_media_templates() proves nothing about a host where it is only
 * defined after wp_enqueue_media().
 *
 * SAID PLAINLY: this does not prove the screens load. Loading them is a manual
 * pass and it is in HANDOVER.md.
 *
 * SO THIS CHECKS THE CONTRACT INSTEAD, which is what was actually broken:
 *
 *   1. A screen that declares load_media calls wp_enqueue_media().
 *   2. A screen that renders a rich text control declares load_editor.
 *   3. Media-stack functions are reached only under load_media.
 *   4. The flags are set BEFORE chrome_open(), which writes the head.
 *
 * Rules 1 and 3 each catch the 3.44.0 fatal on their own.
 */

$root  = dirname( __DIR__ );
$fails = array();
$self  = in_array( '--self-test', $argv, true );
$src   = file_get_contents( $root . '/includes/class-sfaf-portal.php' );

/**
 * Functions that are only safe once something has been enqueued.
 *
 * SEEDED WITH THE ONE THAT BIT, and it is a list rather than a special case
 * because the next one will be the same shape: a WordPress function that exists
 * in the admin, or after an enqueue, and not on an arbitrary front-end request
 * where this portal builds its own document.
 */
$REQUIRES = array(
    'wp_print_media_templates' => array( 'enqueue' => 'wp_enqueue_media', 'flag' => 'load_media' ),
);

/* --------------------------------------------------------------------------
 * The portal, as methods.
 * ----------------------------------------------------------------------- */
function methods_of( $src ) {
    $tok = token_get_all( $src );
    $out = array();
    $n   = count( $tok );
    for ( $i = 0; $i < $n; $i++ ) {
        if ( ! is_array( $tok[ $i ] ) || T_FUNCTION !== $tok[ $i ][0] ) { continue; }
        $name = '';
        for ( $j = $i + 1; $j < $n; $j++ ) {
            if ( is_array( $tok[ $j ] ) && T_STRING === $tok[ $j ][0] ) { $name = $tok[ $j ][1]; break; }
            if ( is_string( $tok[ $j ] ) && '(' === $tok[ $j ] ) { break; }
        }
        if ( '' === $name ) { continue; }
        $depth = 0; $started = false; $body = '';
        for ( $j = $i; $j < $n; $j++ ) {
            $text = is_array( $tok[ $j ] ) ? $tok[ $j ][1] : $tok[ $j ];
            if ( is_string( $tok[ $j ] ) && '{' === $tok[ $j ] ) { $depth++; $started = true; }
            if ( is_array( $tok[ $j ] ) && in_array( $tok[ $j ][0], array( T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ), true ) ) { $depth++; }
            if ( $started ) { $body .= $text; }
            if ( is_string( $tok[ $j ] ) && '}' === $tok[ $j ] ) {
                $depth--;
                if ( $started && 0 === $depth ) { break; }
            }
        }
        $out[ $name ] = $body;
    }
    return $out;
}
$methods = methods_of( $src );

/*
 * WHITESPACE COLLAPSED BEFORE ANY OF THIS IS MATCHED.
 *
 * The first version looked for the literal 'load_media = true' and missed
 * `$this->load_media  = true;`, which is the same line with the assignments
 * aligned. It reported a screen as not declaring a flag it declares on the line
 * above. A checker that reads source has to read it the way PHP does, not the
 * way it happens to be spaced.
 */
foreach ( $methods as $k => $body ) {
    $methods[ $k ] = preg_replace( '/[ \t]+/', ' ', $body );
}
if ( count( $methods ) < 50 ) {
    $fails[] = 'the tokenizer found only ' . count( $methods ) . ' methods; it is not reading the portal';
}

/** Everything $name reaches, following $this-> calls. */
function reaches( $methods, $name, $needle, $seen = array() ) {
    if ( isset( $seen[ $name ] ) || ! isset( $methods[ $name ] ) ) { return false; }
    $seen[ $name ] = true;
    $body = $methods[ $name ];
    if ( false !== strpos( $body, $needle ) ) { return true; }
    foreach ( $methods as $callee => $unused ) {
        if ( $callee === $name ) { continue; }
        if ( false !== strpos( $body, '$this->' . $callee . '(' ) ) {
            if ( reaches( $methods, $callee, $needle, $seen ) ) { return true; }
        }
    }
    return false;
}

/* A screen is a method that opens the portal chrome. */
$screens = array();
foreach ( $methods as $name => $body ) {
    if ( false !== strpos( $body, '$this->chrome_open(' ) ) {
        $screens[] = $name;
    }
}
if ( count( $screens ) < 8 ) {
    $fails[] = 'only ' . count( $screens ) . ' screens found; the walk is not working';
}

/* --------------------------------------------------------------------------
 * 1. DECLARING load_media MEANS CALLING wp_enqueue_media().
 *
 * This is the 3.44.0 fatal, stated as a rule. The FAQ Sets screen set the flag
 * to get its scripts printed, never enqueued media, and foot() then reached a
 * media-stack function on a request that had no media stack.
 * ----------------------------------------------------------------------- */
$report = array();
foreach ( $screens as $screen ) {
    $body        = $methods[ $screen ];
    $says_media  = ( false !== strpos( $body, 'load_media = true' ) );
    $says_editor = ( false !== strpos( $body, 'load_editor = true' ) );
    $enqueues    = ( false !== strpos( $body, 'wp_enqueue_media()' ) );
    $uses_editor = reaches( $methods, $screen, 'SFAF_Rich_Text::render(' )
        || reaches( $methods, $screen, 'SFAF_Rich_Text::deferred(' );

    if ( $says_media && ! $enqueues ) {
        $fails[] = "$screen declares load_media and never calls wp_enqueue_media(); foot() will print media templates on a request with no media stack, which is the 3.44.0 five hundred";
    }
    if ( $enqueues && ! $says_media ) {
        $fails[] = "$screen enqueues the media library and does not declare load_media, so nothing it enqueued is ever printed and the picker will not open";
    }
    if ( $uses_editor && ! $says_editor ) {
        $fails[] = "$screen renders a rich text control and does not declare load_editor, so the editor's scripts are never printed into this document";
    }

    if ( $says_media || $says_editor || $uses_editor ) {
        $report[ $screen ] = ( $says_media ? 'media ' : '' ) . ( $says_editor ? 'editor' : '' );
    }
}

/* --------------------------------------------------------------------------
 * 2. THE MEDIA-STACK FUNCTIONS ARE GATED ON THE RIGHT FLAG.
 * ----------------------------------------------------------------------- */
foreach ( $REQUIRES as $fn => $needs ) {
    foreach ( $methods as $name => $body ) {
        if ( false === strpos( $body, $fn . '(' ) ) { continue; }
        /* The call must sit inside a test of its own flag, and not inside a
         * test that also lets the other flag through. */
        if ( ! preg_match( '/if \(\s*\$this->' . preg_quote( $needs['flag'], '/' ) . '\s*\)\s*\{[^}]*' . preg_quote( $fn, '/' ) . '\(/s', $body ) ) {
            $fails[] = "$name calls $fn() without gating it on \$this->{$needs['flag']} alone; it is only safe on a request that called {$needs['enqueue']}()";
        }
    }
}

/* --------------------------------------------------------------------------
 * 3. THE FLAGS ARE SET BEFORE THE HEAD IS WRITTEN.
 *
 * chrome_open() calls head(), which is what prints the enqueued styles and
 * scripts. Setting a flag afterwards enqueues into a head that has already
 * gone out, which is the ordering that has made a control do nothing before.
 * ----------------------------------------------------------------------- */
foreach ( $screens as $screen ) {
    $body = $methods[ $screen ];
    foreach ( array( 'load_media', 'load_editor' ) as $flag ) {
        $at_flag = strpos( $body, $flag . ' = true' );
        if ( false === $at_flag ) { continue; }
        /*
         * THE LAST chrome_open(), NOT THE FIRST.
         *
         * Most screens open with a permission branch that renders a refusal
         * through chrome_open() and returns. Comparing against the first one
         * reported every screen as setting its flags too late, which is a false
         * alarm that would have sent somebody to move working code.
         */
        $at_open = strrpos( $body, '$this->chrome_open(' );
        if ( false !== $at_open && $at_flag > $at_open ) {
            $fails[] = "$screen sets $flag after chrome_open(), so the head was written before anything was enqueued";
        }
    }
}

/* --------------------------------------------------------------------------
 * SELF TEST.
 * ----------------------------------------------------------------------- */
if ( $self ) {
    echo "SELF TEST\n" . str_repeat( '=', 72 ) . "\n";
    $bad = 0;

    $fake = "{ \$this->load_media = true; \$this->chrome_open( \$user, 'x' ); }";
    $hit = ( false !== strpos( $fake, 'load_media = true' ) && false === strpos( $fake, 'wp_enqueue_media()' ) );
    echo $hit ? "ok       a flag with no enqueue is seen\n" : "BROKEN: rule one sees nothing\n";
    if ( ! $hit ) { $bad++; }

    $ungated = "{ wp_print_media_templates(); }";
    $gated   = preg_match( '/if \(\s*\$this->load_media\s*\)\s*\{[^}]*wp_print_media_templates\(/s', $ungated );
    echo $gated ? "BROKEN: an ungated media call would pass\n" : "ok       an ungated media call is seen\n";
    if ( $gated ) { $bad++; }

    $late = "{ \$this->chrome_open( \$user, 'x' ); \$this->load_editor = true; }";
    $wrong = ( strpos( $late, 'load_editor = true' ) > strrpos( $late, '$this->chrome_open(' ) );
    echo $wrong ? "ok       a flag set after chrome_open() is seen\n" : "BROKEN: the ordering rule sees nothing\n";
    if ( ! $wrong ) { $bad++; }

    echo "\n" . ( $bad ? "$bad case(s) wrong; this checker cannot be trusted.\n" : "the checker can see each rule broken.\n" );
    exit( $bad ? 1 : 0 );
}

/* --------------------------------------------------------------------------
 * Report.
 * ----------------------------------------------------------------------- */
echo "caladmin screen assets\n";
echo str_repeat( '=', 72 ) . "\n";
printf( "screens: %d, of which %d ask for something\n", count( $screens ), count( $report ) );
foreach ( $report as $screen => $what ) {
    printf( "  %-26s %s\n", $screen, trim( $what ) );
}
echo "\nnot proven here: that any of these screens actually renders. There is no\n";
echo "WordPress in this environment. See HANDOVER.md for the manual pass.\n\n";

if ( $fails ) {
    foreach ( $fails as $f ) {
        echo "  FAIL  $f\n";
    }
    echo "\n" . count( $fails ) . " failure(s).\n";
    exit( 1 );
}
echo "every screen asks for what it uses, and reaches nothing it did not ask for.\n";
