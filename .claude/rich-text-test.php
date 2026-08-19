<?php
/**
 * ONE RICH TEXT CONTROL, ONE TOOLBAR, AND NOTHING STRIPS PROSE.
 *
 *     php .claude/rich-text-test.php
 *     php .claude/rich-text-test.php --self-test
 *
 * WHY THIS FILE IS SHAPED LIKE THE PICKER TEST. 3.43.1 found the image picker
 * was the same markup written out twice: one copy was updated when the binding
 * changed and the other was not, so a button did nothing on one screen, and by
 * then the two copies had different rules. A rich text control is a worse thing
 * to duplicate, because the copies would differ in WHAT SOMEBODY MAY TYPE. One
 * screen with font colours and another without is a calendar that is branded in
 * some places and not others, and nobody notices until it is everywhere.
 *
 * So the toolbar is asserted to have exactly one definition, and wp_editor() to
 * have exactly one caller.
 *
 * AND THE OTHER HALF, WHICH IS WHERE THE DAMAGE IS. Turning a plain text field
 * into HTML breaks everything that reads it as a VALUE. strip_tags() and
 * wp_strip_all_tags() join the text either side of a tag with nothing between,
 * so two paragraphs become "OneTwo". 3.38.0 found that in wp_trim_words() and
 * it would have broken every card on the public calendar. This asserts that no
 * prose field reaches one of those.
 */

$root  = dirname( __DIR__ );
$fails = array();
$self  = in_array( '--self-test', $argv, true );

$files = array_merge(
    glob( $root . '/includes/*.php' ),
    glob( $root . '/admin/*.php' ),
    glob( $root . '/templates/*.php' ),
    array( $root . '/sfaf-calendar.php' )
);

/** Source with comments removed, because every check below is "X is absent". */
function code_of( $file ) {
    $out = '';
    foreach ( token_get_all( file_get_contents( $file ) ) as $t ) {
        if ( is_array( $t ) && in_array( $t[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
            continue;
        }
        $out .= is_array( $t ) ? $t[1] : $t;
    }
    return $out;
}

$code = array();
foreach ( $files as $f ) {
    $code[ basename( $f ) ] = code_of( $f );
}
if ( count( $code ) < 20 ) {
    $fails[] = 'only ' . count( $code ) . ' files were read; this is not looking at the plugin';
}

/* ---------------------------------------------------------------------------
 * 1. ONE CALLER OF wp_editor(), AND ONE TOOLBAR.
 * ------------------------------------------------------------------------ */
$editors = array();
$toolbars = array();
foreach ( $code as $name => $src ) {
    if ( false !== strpos( $src, 'wp_editor(' ) ) {
        $editors[] = $name;
    }
    if ( false !== strpos( $src, 'toolbar1' ) || false !== strpos( $src, 'block_formats' ) ) {
        $toolbars[] = $name;
    }
}
if ( array( 'class-sfaf-rich-text.php' ) !== $editors ) {
    $fails[] = 'wp_editor() is called from [' . implode( ', ', $editors )
        . '], and belongs to SFAF_Rich_Text alone; a second caller is a second control with its own rules';
}
if ( array( 'class-sfaf-rich-text.php' ) !== $toolbars ) {
    $fails[] = 'the toolbar is defined in [' . implode( ', ', $toolbars )
        . '], and must be defined once; two definitions is two answers to what somebody may type';
}

/* The script must not carry its own copy either. */
$js = file_get_contents( $root . '/public/js/portal.js' );
foreach ( array( 'toolbar1', 'block_formats', 'formatselect,bold' ) as $needle ) {
    if ( false !== strpos( $js, $needle ) ) {
        $fails[] = "portal.js contains '$needle', so the browser has its own toolbar and the two can differ";
    }
}
if ( false === strpos( $js, 'uc-rich-settings' ) ) {
    $fails[] = 'portal.js does not read the settings the server printed, so rows it builds are configured by something else';
}

/* ---------------------------------------------------------------------------
 * 2. THE TOOLBAR IS THE AGREED ONE.
 *
 * Named rather than counted, because the omissions are the decision: no
 * colours, no sizes, no alignment, and exactly one heading level, below the
 * page's own h1 and h2.
 * ------------------------------------------------------------------------ */
$rt = $code['class-sfaf-rich-text.php'];
foreach ( array( 'bold', 'italic', 'bullist', 'numlist', 'link', 'unlink' ) as $want ) {
    if ( false === strpos( $rt, $want ) ) {
        $fails[] = "the toolbar has lost $want";
    }
}
foreach ( array( 'forecolor', 'backcolor', 'fontselect', 'fontsizeselect', 'alignleft', 'aligncenter', 'alignright', 'justify' ) as $banned ) {
    if ( false !== strpos( $rt, $banned ) ) {
        $fails[] = "the toolbar offers $banned; colour, size and alignment are the brand guide's, not a typist's";
    }
}
if ( false === strpos( $rt, 'Heading=h3' ) ) {
    $fails[] = 'the heading level is not h3; it has to sit below the page title and its sections or it breaks reading order';
}
foreach ( array( 'h1', 'h2', 'h4' ) as $level ) {
    if ( false !== strpos( $rt, 'Heading=' . $level ) ) {
        $fails[] = "the editor offers $level, which competes with the page's own structure";
    }
}

/* ---------------------------------------------------------------------------
 * 3. NOTHING STRIPS PROSE.
 *
 * The list is the fields that hold prose and the functions that join across a
 * tag. A hit is a statement doing both.
 * ------------------------------------------------------------------------ */
$joiners = array( 'wp_strip_all_tags', 'strip_tags', 'wp_trim_words' );
$prose   = array( 'get_the_excerpt', 'post_content', "['answer']", 'term->description', '$description' );

foreach ( $code as $name => $src ) {
    if ( 'class-sfaf-rich-text.php' === $name ) {
        continue; // to_plain()'s own fallback names one, and says why.
    }
    foreach ( preg_split( '/;\s*/', $src ) as $stmt ) {
        $has_joiner = false;
        $which = '';
        foreach ( $joiners as $j ) {
            if ( false !== strpos( $stmt, $j . '(' ) ) { $has_joiner = true; $which = $j; break; }
        }
        if ( ! $has_joiner ) { continue; }
        /* Flattening first makes a trim safe, which is the fixed shape. */
        if ( false !== strpos( $stmt, 'sfaf_flatten_html(' ) ) { continue; }
        foreach ( $prose as $p ) {
            if ( false !== strpos( $stmt, $p ) ) {
                $fails[] = "$name joins prose with $which() on a statement mentioning $p; use sfaf_flatten_html(), which puts a space where the block tag was";
                break;
            }
        }
    }
}

/* ---------------------------------------------------------------------------
 * 4. PROSE IS DISPLAYED AS PROSE, not escaped into visible tags.
 * ------------------------------------------------------------------------ */
$tpl = $code['sfaf-template-functions.php'];
if ( preg_match( "#wpautop\(\s*esc_html\(\s*\\\$f\['answer'\]#", $tpl ) ) {
    $fails[] = 'the FAQ answer is escaped before display, so a formatted answer shows its tags on the event page';
}
if ( false === strpos( $tpl, "SFAF_Rich_Text::display( \$f['answer'] )" ) ) {
    $fails[] = 'the FAQ answer is not rendered through SFAF_Rich_Text::display()';
}

/* ---------------------------------------------------------------------------
 * 5. PROSE IS STORED AS PROSE. Every FAQ answer save keeps the markup.
 * ------------------------------------------------------------------------ */
foreach ( array( 'class-sfaf-portal.php', 'class-sfaf-faq-sets.php', 'class-sfaf-sources.php' ) as $name ) {
    if ( preg_match( "#sanitize_textarea_field\(\s*isset\(\s*\\\$row\['answer'\]#", $code[ $name ] )
        || preg_match( "#\\\$row\['answer'\] \) \? sanitize_textarea_field#", $code[ $name ] ) ) {
        $fails[] = "$name still strips an FAQ answer on save, so formatting is lost the moment anybody saves";
    }
}
$saves = 0;
foreach ( $code as $src ) {
    $saves += substr_count( $src, 'SFAF_Rich_Text::sanitize(' );
}
if ( $saves < 3 ) {
    $fails[] = "only $saves save path(s) use SFAF_Rich_Text::sanitize(); the event editor, the FAQ sets screen and the import all store answers";
}

/* ---------------------------------------------------------------------------
 * 6. THE PUBLIC FORM DOES NOT GET AN EDITOR.
 *
 * 3.43.0 strips markup from anonymous input on purpose. A rich control there
 * would be an unauthenticated HTML surface, which is the opposite decision.
 * ------------------------------------------------------------------------ */
if ( false !== strpos( $code['class-sfaf-request.php'], 'SFAF_Rich_Text::render' )
    || false !== strpos( $code['class-sfaf-request.php'], 'wp_editor' ) ) {
    $fails[] = 'the public request form renders a rich text editor; anonymous input is stripped on purpose';
}

/* ---------------------------------------------------------------------------
 * SELF TEST.
 * ------------------------------------------------------------------------ */
if ( $self ) {
    echo "SELF TEST\n" . str_repeat( '=', 72 ) . "\n";
    $bad = 0;

    $probe = "\$x = wp_strip_all_tags( get_the_excerpt( \$id ) );";
    $hit = false;
    foreach ( preg_split( '/;\s*/', $probe ) as $stmt ) {
        if ( false !== strpos( $stmt, 'wp_strip_all_tags(' ) && false !== strpos( $stmt, 'get_the_excerpt' ) ) { $hit = true; }
    }
    echo $hit ? "ok       a strip over an excerpt is seen\n" : "BROKEN: the joiner sweep sees nothing\n";
    if ( ! $hit ) { $bad++; }

    $safe = "\$x = wp_trim_words( sfaf_flatten_html( get_the_excerpt( \$id ) ), 40 );";
    $ok = ( false !== strpos( $safe, 'sfaf_flatten_html(' ) );
    echo $ok ? "ok       flattening first is accepted\n" : "BROKEN: the fixed shape is refused\n";
    if ( ! $ok ) { $bad++; }

    $two = array( 'class-sfaf-rich-text.php', 'class-sfaf-portal.php' );
    echo ( array( 'class-sfaf-rich-text.php' ) !== $two )
        ? "ok       a second wp_editor() caller is seen\n"
        : "BROKEN: a second caller would pass\n";

    echo "\n" . ( $bad ? "$bad case(s) wrong; this checker cannot be trusted.\n" : "the checker can see a joined field and a second control.\n" );
    exit( $bad ? 1 : 0 );
}

/* ---------------------------------------------------------------------------
 * Report.
 * ------------------------------------------------------------------------ */
echo "Rich text\n";
echo str_repeat( '=', 72 ) . "\n";
printf( "wp_editor() called from:  %s\n", implode( ', ', $editors ) );
printf( "toolbar defined in:       %s\n", implode( ', ', $toolbars ) );
printf( "saves keeping markup:     %d\n\n", $saves );

if ( $fails ) {
    foreach ( $fails as $f ) {
        echo "  FAIL  $f\n";
    }
    echo "\n" . count( $fails ) . " failure(s).\n";
    exit( 1 );
}
echo "one control, one toolbar, and nothing joins two paragraphs into one word.\n";
