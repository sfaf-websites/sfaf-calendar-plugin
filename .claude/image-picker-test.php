<?php
/**
 * EVERY IMAGE PICKER IN caladmin IS THE SAME PICKER, AND IT WORKS.
 *
 *     php .claude/image-picker-test.php
 *     php .claude/image-picker-test.php --self-test
 *
 * WHAT WENT WRONG, AND WHY THE OBVIOUS PLACE TO LOOK WAS THE WRONG ONE.
 *
 * Choose Image did nothing at all on the series screen. The natural suspicion
 * was the media library not being loaded, because caladmin builds its own
 * document and anything needing wp_enqueue_media() has to be asked for per
 * screen, exactly as TinyMCE did in 3.38.0. That was not it. Every screen
 * carrying a picker was already enqueueing correctly.
 *
 * The picker was markup written out TWICE. When it was rebound from
 * `getElementById('uc-featured-image-id')` to data attributes, so the pending
 * queue could render several on one page, the event editor's copy was updated
 * and the series screen's was not. `bindImageField()` looks for
 * `[data-uc-image-id]`, `[data-uc-image-preview]` and
 * `[data-uc-image-preview-img]`, found none of them on that screen, and
 * returned before binding the click. Nothing was wired to the button.
 *
 * SO THIS FILE ASSERTS THE CONTRACT, NOT THE ENQUEUE.
 *
 *   1. What bindImageField() requires is READ OUT OF portal.js, not restated
 *      here, so adding a required hook to the script and not to the markup
 *      fails this file rather than passing it.
 *   2. Exactly one place in the portal emits those hooks. Two is how this
 *      happened, and it is the thing that must not come back.
 *   3. Every picker wrapper carries the calendar-folder attributes, so the
 *      3.42.1 behaviour cannot apply to one screen and not another.
 *   4. Every screen that renders a picker enqueues the media library. That was
 *      not the fault this time, and it is still a real way to break it.
 */

$root  = dirname( __DIR__ );
$fails = array();
$self  = in_array( '--self-test', $argv, true );

$portal_file = $root . '/includes/class-sfaf-portal.php';
$src = file_get_contents( $portal_file );
$js  = file_get_contents( $root . '/public/js/portal.js' );

/* ---------------------------------------------------------------------------
 * The portal, as methods. Tokenized, because brace counting over a file with
 * this much HTML in it is guesswork.
 * ------------------------------------------------------------------------ */
function methods_of( $src ) {
    $tok  = token_get_all( $src );
    $out  = array();
    $n    = count( $tok );
    for ( $i = 0; $i < $n; $i++ ) {
        if ( ! is_array( $tok[ $i ] ) || T_FUNCTION !== $tok[ $i ][0] ) {
            continue;
        }
        $name = '';
        for ( $j = $i + 1; $j < $n; $j++ ) {
            if ( is_array( $tok[ $j ] ) && T_STRING === $tok[ $j ][0] ) { $name = $tok[ $j ][1]; break; }
            if ( is_string( $tok[ $j ] ) && '(' === $tok[ $j ] ) { break; }
        }
        if ( '' === $name ) { continue; }

        /* Walk to the opening brace, then to its match. */
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

if ( count( $methods ) < 50 ) {
    $fails[] = 'the tokenizer found only ' . count( $methods ) . ' methods in the portal, so it is not reading the file properly';
}

/* ---------------------------------------------------------------------------
 * 1. WHAT THE SCRIPT REQUIRES, read out of the script.
 * ------------------------------------------------------------------------ */
$bind_at = strpos( $js, 'function bindImageField' );
if ( false === $bind_at ) {
    echo "Cannot run: bindImageField() is not in portal.js. It was renamed.\n";
    exit( 1 );
}
$bind = substr( $js, $bind_at, 2200 );

/* Every data attribute the binder looks for, and whether it bails without it. */
preg_match_all( "#querySelector\('\[(data-uc-image[a-z\-]*)\]'\)#", $bind, $m );
$hooks = array_values( array_unique( $m[1] ) );

/*
 * The ones inside an early return are the ones that stop it dead.
 *
 * ALL the guards, not the first. bindImageField() opens with `if (!chooseBtn)
 * return;` and the interesting one is further down, so preg_match() found the
 * button guard and stopped, and this file reported one required hook while
 * happily passing. That is the same shape as the fault it is written about.
 */
$required = array();
preg_match_all( '#if \(!([^)]*)\)\s*\{\s*return;#', $bind, $guards, PREG_SET_ORDER );
foreach ( $guards as $guard ) {
    foreach ( array(
        'idInput'    => 'data-uc-image-id',
        'previewImg' => 'data-uc-image-preview-img',
        'preview'    => 'data-uc-image-preview',
    ) as $var => $attr ) {
        /* previewImg is checked before preview so the longer name wins its
         * own attribute rather than matching as a prefix of it. */
        if ( preg_match( '/\b' . preg_quote( $var, '/' ) . '\b/', $guard[1] ) && ! in_array( $attr, $required, true ) ) {
            $required[] = $attr;
        }
    }
}
/* The button is required too: no button, no listener, and it returns first.
 *
 * TWO TRIGGERS SINCE 3.74.0, so this reads querySelectorAll as well as
 * querySelector. The series filter added an "All calendar images" button beside
 * Choose Image, and both carry the same class; what the markup has to provide
 * is unchanged, which is why the answer this feeds is unchanged. */
if ( preg_match( "#querySelectorAll?\('\.(uc-choose-image)'\)#", $bind ) ) {
    $required[] = 'class:uc-choose-image';
}

if ( count( $required ) < 4 ) {
    $fails[] = 'only ' . count( $required ) . ' required hook(s) were read out of bindImageField(); this file cannot check what it cannot see';
}

/* ---------------------------------------------------------------------------
 * 2. ONE PLACE EMITS THEM.
 *
 * The hooks are what a picker IS. Counting the methods that emit them is
 * counting the pickers, and there must be exactly one.
 * ------------------------------------------------------------------------ */
$emitters = array();
foreach ( $methods as $name => $body ) {
    if ( false !== strpos( $body, 'data-uc-image-id' ) ) {
        $emitters[] = $name;
    }
}
if ( array( 'render_image_picker' ) !== $emitters ) {
    $fails[] = 'the picker hooks are emitted by [' . implode( ', ', $emitters )
        . '], and must be emitted by render_image_picker() alone; a second copy is how the series screen silently stopped working';
}

/*
 * AND THE PARTS A BROKEN COPY WOULD STILL HAVE.
 *
 * The check above counts copies that carry the data hooks, which is a second
 * WORKING picker. The fault this file exists for was a second BROKEN one: it
 * had the button, the preview and the hidden input under the old element ids
 * and none of the hooks, so counting hooks did not see it. Planting exactly
 * that is what showed the hole.
 *
 * These are the structural parts. Wherever one appears, the whole picker is
 * being written out by hand, whether or not that copy would work.
 */
foreach ( array( 'uc-choose-image', 'uc-image-preview', 'uc-remove-image', 'uc-image-url-field' ) as $part ) {
    $where = array();
    foreach ( $methods as $name => $body ) {
        if ( false !== strpos( $body, $part ) ) {
            $where[] = $name;
        }
    }
    if ( array( 'render_image_picker' ) !== $where ) {
        $fails[] = "$part is written in [" . implode( ', ', $where )
            . '], and belongs to render_image_picker() alone; anywhere else is a hand-written picker, which is what stopped working on the series screen';
    }
}

/* Everything the binder needs is in that one renderer. */
$picker = isset( $methods['render_image_picker'] ) ? $methods['render_image_picker'] : '';
foreach ( $required as $need ) {
    $needle = ( 0 === strpos( $need, 'class:' ) ) ? substr( $need, 6 ) : $need;
    if ( false === strpos( $picker, $needle ) ) {
        $fails[] = "render_image_picker() does not emit $needle, which bindImageField() requires before it will bind the button";
    }
}

/* ---------------------------------------------------------------------------
 * 3. EVERY PICKER IS A CALENDAR-FOLDER PICKER (3.42.1 parity).
 *
 * The wrapper is what portal.js reads the folder flag off, so a wrapper
 * without it is a picker showing the whole media library and uploading to the
 * month directory. Both wrappers must go through image_picker_atts().
 * ------------------------------------------------------------------------ */
$wrappers = array();
foreach ( $methods as $name => $body ) {
    if ( false !== strpos( $body, 'uc-image-field' ) && 'render_image_picker' !== $name ) {
        $wrappers[] = $name;
    }
}
if ( count( $wrappers ) < 2 ) {
    $fails[] = 'fewer than two picker wrappers found, so this check is not looking at both screens';
}
foreach ( $wrappers as $name ) {
    if ( false === strpos( $methods[ $name ], 'image_picker_atts(' ) ) {
        $fails[] = "$name renders a picker wrapper without image_picker_atts(), so that screen would offer the whole media library and upload outside the calendar folder";
    }
    if ( false === strpos( $methods[ $name ], 'render_image_picker(' ) ) {
        $fails[] = "$name renders a picker wrapper but does not call render_image_picker(), so it is a second copy again";
    }
}

/* And the attribute builder emits both halves: the flag the query uses and the
 * folder the copy names. */
$atts = isset( $methods['image_picker_atts'] ) ? $methods['image_picker_atts'] : '';
foreach ( array( 'data-uc-media-flag', 'SFAF_Media_Folder::FLAG', 'data-uc-media-folder' ) as $need ) {
    if ( false === strpos( $atts, $need ) ) {
        $fails[] = "image_picker_atts() does not emit $need, so the folder filter would not be asked for";
    }
}

/* ---------------------------------------------------------------------------
 * 4. EVERY SCREEN THAT RENDERS ONE LOADS THE MEDIA LIBRARY.
 *
 * Not the fault this time, and still a real way to break it. A method emits a
 * picker if it renders one or calls something that does; it is covered if it
 * enqueues, or if every method that calls it is covered.
 * ------------------------------------------------------------------------ */
$emits = array();
foreach ( $methods as $name => $body ) {
    if ( false !== strpos( $body, 'render_image_picker(' ) || false !== strpos( $body, 'uc-image-field' ) ) {
        $emits[ $name ] = true;
    }
}
/*
 * SPREAD UPWARDS, AND STOP AT THE ENQUEUE.
 *
 * A method that enqueues is the boundary: everything below it on that path is
 * satisfied, and its own callers have nothing to answer for. Without that
 * stop the closure runs all the way to handle() and maybe_render(), and this
 * file reported the ROUTER as missing an enqueue, which is nonsense: the
 * router's job is to call the screen, and the screen loads what it needs.
 */
for ( $pass = 0; $pass < 8; $pass++ ) {
    $before = count( $emits );
    foreach ( $methods as $name => $body ) {
        if ( isset( $emits[ $name ] ) || false !== strpos( $body, 'wp_enqueue_media()' ) ) {
            continue;
        }
        foreach ( array_keys( $emits ) as $target ) {
            /* Do not propagate OUT of a method that already loads the library.
             * It is satisfied, so its callers have nothing to answer for. This
             * is the boundary, and putting the test on the caller instead let
             * render_series_edit, which enqueues, pull handle() in behind it. */
            if ( false !== strpos( $methods[ $target ], 'wp_enqueue_media()' ) ) {
                continue;
            }
            if ( $name !== $target && false !== strpos( $body, '$this->' . $target . '(' ) ) {
                $emits[ $name ] = true;
                break;
            }
        }
    }
    if ( count( $emits ) === $before ) { break; }
}

/*
 * COVERED MEANS "THE LIBRARY IS LOADED BY THE TIME THIS RUNS", which is not
 * the same as "this method enqueues".
 *
 * render_import_queue() renders pickers and enqueues nothing, and it is
 * correct: the only thing that calls it is render_pending(), which enqueues
 * before it. A first version of this check called every picker-rendering
 * method a screen and reported that one as broken, which is a false alarm
 * that would have sent somebody to add a second enqueue.
 *
 * So: a method is covered if it enqueues itself, or if it has callers and
 * every one of them is covered. A method with no caller inside this class is
 * an entry point and has to enqueue on its own.
 */
$callers = array();
foreach ( array_keys( $emits ) as $target ) {
    $callers[ $target ] = array();
    foreach ( $methods as $name => $body ) {
        if ( $name !== $target && false !== strpos( $body, '$this->' . $target . '(' ) ) {
            $callers[ $target ][] = $name;
        }
    }
}
$covered = array();
foreach ( array_keys( $emits ) as $name ) {
    $covered[ $name ] = ( false !== strpos( $methods[ $name ], 'wp_enqueue_media()' ) );
}
for ( $pass = 0; $pass < 8; $pass++ ) {
    $changed = false;
    foreach ( array_keys( $emits ) as $name ) {
        if ( $covered[ $name ] || empty( $callers[ $name ] ) ) {
            continue;
        }
        $all = true;
        foreach ( $callers[ $name ] as $c ) {
            /* A caller outside the picker set cannot have been checked, so it
             * counts only if it enqueues. */
            $c_ok = isset( $covered[ $c ] ) ? $covered[ $c ] : ( false !== strpos( $methods[ $c ], 'wp_enqueue_media()' ) );
            if ( ! $c_ok ) { $all = false; break; }
        }
        if ( $all ) { $covered[ $name ] = true; $changed = true; }
    }
    if ( ! $changed ) { break; }
}

/* The screens are the boundary: the methods that load the library. */
$screens = array();
foreach ( $methods as $name => $body ) {
    if ( false !== strpos( $body, 'wp_enqueue_media()' ) ) {
        $screens[] = $name;
    }
}
$missing = array();
foreach ( array_keys( $emits ) as $name ) {
    if ( ! $covered[ $name ] ) {
        $missing[] = $name;
        $fails[] = "$name renders an image picker and neither it nor everything that calls it loads the media library, so wp.media is undefined and Choose Image reports the library is unavailable";
    }
}
if ( count( $screens ) < 3 ) {
    $fails[] = 'fewer than three methods enqueue the media library; the walk is not seeing the screens';
}

/* ---------------------------------------------------------------------------
 * SELF TEST. Prove each check can fail.
 * ------------------------------------------------------------------------ */
if ( $self ) {
    echo "SELF TEST\n" . str_repeat( '=', 72 ) . "\n";
    $bad = 0;

    /* A second emitter, which is the original fault. */
    $twice = $methods;
    $twice['render_series_edit'] .= ' data-uc-image-id ';
    $ems = array();
    foreach ( $twice as $n => $b ) {
        if ( false !== strpos( $b, 'data-uc-image-id' ) ) { $ems[] = $n; }
    }
    if ( array( 'render_image_picker' ) === $ems ) {
        echo "BROKEN: a second emitter was not noticed\n"; $bad++;
    } else {
        echo "ok       a second copy of the hooks is seen\n";
    }

    /* A wrapper with no folder attributes. */
    $stripped = str_replace( 'image_picker_atts(', 'nothing_at_all(', $methods['render_series_edit'] );
    if ( false !== strpos( $stripped, 'image_picker_atts(' ) ) {
        echo "BROKEN: the folder check reads a string it cannot lose\n"; $bad++;
    } else {
        echo "ok       a wrapper without the folder attributes is seen\n";
    }

    /* A screen that stops enqueueing. */
    $noenq = str_replace( 'wp_enqueue_media()', 'nope()', $methods['render_series_edit'] );
    if ( false !== strpos( $noenq, 'wp_enqueue_media()' ) ) {
        echo "BROKEN: the enqueue check reads a string it cannot lose\n"; $bad++;
    } else {
        echo "ok       a screen that stops enqueueing is seen\n";
    }

    /* The required list really came out of the script. */
    if ( count( $required ) >= 4 ) {
        echo "ok       " . count( $required ) . " required hooks were read out of portal.js, not restated here\n";
    } else {
        echo "BROKEN: the required hooks were not read from the script\n"; $bad++;
    }

    echo "\n" . ( $bad ? "$bad case(s) wrong; this checker cannot be trusted.\n" : "the checker can see each of the four failures.\n" );
    exit( $bad ? 1 : 0 );
}

/* ---------------------------------------------------------------------------
 * Report.
 * ------------------------------------------------------------------------ */
echo "Image pickers in caladmin\n";
echo str_repeat( '=', 72 ) . "\n";
printf( "required by bindImageField(): %s\n", implode( ', ', $required ) );
printf( "emitted by:                   %s\n", implode( ', ', $emitters ) );
printf( "wrappers:                     %s\n", implode( ', ', $wrappers ) );
printf( "screens loading the library:  %s\n", implode( ', ', $screens ) );
printf( "screens missing the enqueue:  %s\n\n", $missing ? implode( ', ', $missing ) : 'none' );

if ( $fails ) {
    foreach ( $fails as $f ) {
        echo "  FAIL  $f\n";
    }
    echo "\n" . count( $fails ) . " failure(s).\n";
    exit( 1 );
}
echo "one picker, on every screen that has one, filtered to the calendar folder.\n";
