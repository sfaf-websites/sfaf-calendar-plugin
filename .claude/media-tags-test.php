<?php
/**
 * IMAGE TAGS ARE SERIES, AND DELETING A SERIES MUST NOT TOUCH AN IMAGE.
 *
 *     php .claude/media-tags-test.php --self-test
 *     php .claude/media-tags-test.php
 *
 * WHY THIS EXISTS. The instruction that created this feature said "deleting a
 * series must not touch the images. They stay, untagged, and the untagged view
 * surfaces them. Say how you guaranteed that." This file is that guarantee,
 * written as something that fails if it stops being true.
 *
 * THE GUARANTEE HAS TWO HALVES AND ONLY ONE OF THEM IS OURS.
 *
 *   WordPress's half. wp_delete_term() deletes the term and its rows in
 *   term_relationships. It does not read, write or delete a post. That is core
 *   behaviour this plugin does not get to change, and an image is a post.
 *
 *   OUR half. Nothing in this plugin may hook a term deletion and go looking
 *   for attachments. That is the half that could be broken by a future build,
 *   by somebody adding a tidy-up that deletes "orphaned" images, so that is
 *   what is checked below: no delete_term or pre_delete_term hook anywhere in
 *   the plugin does anything to an attachment.
 *
 * AND THE STATE THAT LEAVES. An image whose only series has been deleted has no
 * row in the taxonomy at all, which is exactly what the Untagged filter asks
 * for. So it surfaces rather than disappearing, which is the second half of
 * what was asked for. That is asserted against the query SFAF_Media builds.
 *
 * WHAT THIS CANNOT DO: run WordPress. There is no database here. Everything
 * below is either a property of the code as written, decided by reading it
 * with a tokenizer where reading is what the question needs, or the arguments
 * of a query, decided by building one.
 *
 * @package SFAF_Calendar
 */

define( 'ABSPATH', __DIR__ );

$root  = dirname( __DIR__ );
$fails = array();
$self  = in_array( '--self-test', array_slice( $argv, 1 ), true );

function fail( $m ) { global $fails; $fails[] = $m; }
function expect( $label, $got, $want ) {
    if ( $got !== $want ) {
        fail( sprintf( '%s: got %s, expected %s', $label, var_export( $got, true ), var_export( $want, true ) ) );
    }
}

/* ---------------------------------------------------------------------------
 * WordPress, in miniature. Only what SFAF_Media touches.
 * ------------------------------------------------------------------------ */
function esc_html( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $t ) { return esc_html( $t ); }
function esc_url( $t ) { return (string) $t; }
function add_action( $h, $cb, $p = 10, $a = 1 ) { $GLOBALS['hooks'][ $h ][] = $cb; return true; }
function add_filter( $h, $cb, $p = 10, $a = 1 ) { $GLOBALS['hooks'][ $h ][] = $cb; return true; }
function is_wp_error( $t ) { return false; }
function taxonomy_exists( $t ) { return 'uc_series' === $t; }
function register_taxonomy_for_object_type( $tax, $type ) {
    $GLOBALS['attached'][] = $tax . '|' . $type;
    return true;
}
function __checked_selected_helper( $a, $b, $echo, $type ) {
    $out = ( (string) $a === (string) $b ) ? " $type='$type'" : '';
    if ( $echo ) { echo $out; }
    return $out;
}
function checked( $a, $b = true, $e = true ) { return __checked_selected_helper( $a, $b, $e, 'checked' ); }
function sfaf_icon( $n, $a = array() ) { return ''; }
function get_post_type( $id ) { return isset( $GLOBALS['library'][ (int) $id ] ) ? 'attachment' : ''; }
function get_post_meta( $id, $k, $single = false ) {
    return isset( $GLOBALS['library'][ (int) $id ] ) ? $GLOBALS['library'][ (int) $id ]['file'] : '';
}
function get_the_title( $id = 0 ) { return ''; }
function wp_get_attachment_image_url( $id, $size = 'thumbnail' ) { return 'https://example.org/x.jpg'; }
function get_the_terms( $id, $tax ) { return isset( $GLOBALS['terms'][ (int) $id ] ) ? $GLOBALS['terms'][ (int) $id ] : array(); }
function get_term( $id, $tax = '' ) { return isset( $GLOBALS['series'][ (int) $id ] ) ? $GLOBALS['series'][ (int) $id ] : null; }
function wp_list_pluck( $list, $field ) {
    $out = array();
    foreach ( (array) $list as $item ) { $out[] = is_object( $item ) ? $item->$field : $item[ $field ]; }
    return $out;
}
function wp_set_object_terms( $id, $terms, $tax, $append = false ) {
    $GLOBALS['writes'][] = array( 'id' => (int) $id, 'terms' => $terms, 'tax' => $tax, 'append' => $append );
    return array( 1 );
}
function wp_remove_object_terms( $id, $terms, $tax ) {
    $GLOBALS['removes'][] = array( 'id' => (int) $id, 'terms' => $terms );
    return true;
}

$GLOBALS['hooks']    = array();
$GLOBALS['attached'] = array();
$GLOBALS['writes']   = array();
$GLOBALS['removes']  = array();
$GLOBALS['queries']  = array();
$GLOBALS['library']  = array(
    71 => array( 'file' => 'calendar/strut-clinic.jpg' ),
    72 => array( 'file' => 'calendar/prep-day.jpg' ),
    73 => array( 'file' => '2026/09/elsewhere.jpg' ), // NOT in the folder
);
$GLOBALS['terms']  = array();
$GLOBALS['series'] = array( 9 => (object) array( 'term_id' => 9, 'name' => 'Strut' ) );

class WP_Query {
    public $posts = array();
    public $found_posts = 0;
    public $max_num_pages = 1;
    public function __construct( $args ) {
        $GLOBALS['queries'][] = $args;
    }
}
class SFAF_Media_Folder {
    const FOLDER = 'calendar';
    const FLAG   = 'uc_calendar_media';
    public static function prefix() { return 'calendar/'; }
    public static function holds( $id ) {
        $file = isset( $GLOBALS['library'][ (int) $id ] ) ? $GLOBALS['library'][ (int) $id ]['file'] : '';
        return ( 0 === strpos( $file, 'calendar/' ) );
    }
    public static function has_any() { return true; }
}
class SFAF_Portal {
    public static $role = 'admin';
    public static function get_role( $id ) { return self::$role; }
}

require_once $root . '/includes/class-sfaf-media.php';

/* =========================================================================
 * 1. THE TAGS ARE THE SERIES TAXONOMY, NOT A SECOND VOCABULARY.
 * ====================================================================== */
expect( 'the tag vocabulary is uc_series', SFAF_Media::TAXONOMY, 'uc_series' );

SFAF_Media::attach_to_attachments();
expect( 'attachments are attached to it', $GLOBALS['attached'], array( 'uc_series|attachment' ) );

/* =========================================================================
 * 2. DELETING A SERIES DOES NOT TOUCH AN IMAGE.
 *
 * Decided by reading every PHP file in the plugin with a tokenizer: no
 * delete_term or pre_delete_term hook exists at all, so none of them can reach
 * an attachment. Grep would answer this with the word inside a comment, and
 * this file's own docblock is full of them.
 * ====================================================================== */
function sfaf_plugin_php( $root ) {
    $out = array();
    $it  = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root ) );
    foreach ( $it as $file ) {
        if ( ! $file->isFile() || 'php' !== strtolower( $file->getExtension() ) ) { continue; }
        $path = str_replace( '\\', '/', $file->getPathname() );
        foreach ( array( '/.claude/', '/vendor/', '/.build-stage/', '/Old Calendar Files/', '/.git/', '/node_modules/' ) as $skip ) {
            if ( false !== strpos( $path, $skip ) ) { continue 2; }
        }
        $out[] = $path;
    }
    return $out;
}

/* The hook names that fire when a term goes. Something IS registered on one of
 * these already, and it is fine: SFAF_Embed flushes the embed cache, which
 * touches no post. So the question is not "is anything hooked" but "does
 * anything hooked there write to a post", and that is what is read. */
$deletion_hooks = array( 'delete_term', 'pre_delete_term', 'delete_uc_series', 'deleted_term_taxonomy' );

/* What "touches an image" looks like in PHP. A term deletion callback doing
 * any of these is the fault this guards against. */
$post_writes = array(
    'wp_delete_post', 'wp_delete_attachment', 'wp_trash_post', 'wp_update_post',
    'wp_insert_post', 'wp_insert_attachment', 'delete_post_meta', 'update_post_meta',
    'wp_delete_file',
);

/** Every callback registered on one of those hooks, as file => callback name. */
function sfaf_deletion_callbacks( $paths, $hooks ) {
    $found = array();
    foreach ( $paths as $path ) {
        $tokens = token_get_all( file_get_contents( $path ) );
        foreach ( $tokens as $i => $t ) {
            if ( ! is_array( $t ) || T_CONSTANT_ENCAPSED_STRING !== $t[0] ) {
                continue;
            }
            if ( ! in_array( trim( $t[1], "'\"" ), $hooks, true ) ) {
                continue;
            }
            /* Only where it is the FIRST argument of add_action or add_filter.
             * A string that happens to equal a hook name is not a hook. */
            $is_registration = false;
            for ( $b = $i - 1; $b >= 0 && $b > $i - 4; $b-- ) {
                if ( is_array( $tokens[ $b ] ) && T_STRING === $tokens[ $b ][0]
                    && in_array( $tokens[ $b ][1], array( 'add_action', 'add_filter' ), true ) ) {
                    $is_registration = true;
                    break;
                }
            }
            if ( ! $is_registration ) {
                continue;
            }
            /* The callback is the next string literal after the hook name,
             * whether it arrived bare or as the second half of an array( $this,
             * 'name' ) pair. */
            for ( $a = $i + 1; $a < count( $tokens ) && $a < $i + 14; $a++ ) {
                if ( is_array( $tokens[ $a ] ) && T_CONSTANT_ENCAPSED_STRING === $tokens[ $a ][0] ) {
                    $found[] = array( 'file' => $path, 'name' => trim( $tokens[ $a ][1], "'\"" ), 'line' => $t[2] );
                    break;
                }
            }
        }
    }
    return $found;
}

/** The source of one function or method, by name, from one file. */
function sfaf_body_of( $path, $name ) {
    $tokens = token_get_all( file_get_contents( $path ) );
    $at     = -1;
    foreach ( $tokens as $i => $t ) {
        if ( is_array( $t ) && T_FUNCTION === $t[0] ) {
            /* The name is the next T_STRING. `function &name()` puts an
             * ampersand in between, which is the 3.20.0 trap. */
            for ( $j = $i + 1; $j < count( $tokens ) && $j < $i + 5; $j++ ) {
                if ( is_array( $tokens[ $j ] ) && T_STRING === $tokens[ $j ][0] ) {
                    if ( $tokens[ $j ][1] === $name ) { $at = $i; }
                    break;
                }
            }
        }
        if ( $at >= 0 ) { break; }
    }
    if ( $at < 0 ) {
        return null;
    }
    /* Everything from the opening brace to its match, counting braces on the
     * token stream so a brace in a string or a comment cannot end it early. */
    $depth = 0;
    $body  = '';
    $open  = false;
    for ( $i = $at; $i < count( $tokens ); $i++ ) {
        $text = is_array( $tokens[ $i ] ) ? $tokens[ $i ][1] : $tokens[ $i ];
        if ( '{' === $tokens[ $i ] || ( is_array( $tokens[ $i ] ) && T_CURLY_OPEN === $tokens[ $i ][0] ) ) {
            $depth++;
            $open = true;
        } elseif ( '}' === $tokens[ $i ] ) {
            $depth--;
        }
        /* Comments are dropped: a note saying wp_delete_post is not a call. */
        if ( is_array( $tokens[ $i ] ) && in_array( $tokens[ $i ][0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
            continue;
        }
        $body .= $text;
        if ( $open && 0 === $depth ) {
            break;
        }
    }
    return $body;
}

$callbacks = sfaf_deletion_callbacks( sfaf_plugin_php( $root ), $deletion_hooks );
if ( empty( $callbacks ) ) {
    /* Not a failure, but worth knowing: if the one hook that exists is ever
     * removed, this whole section stops checking anything and would pass in
     * silence. See the audit-scripts-fail-silently lesson in PROJECT.md 7. */
    fail( 'no term-deletion callback was found at all, so this section proved nothing. '
        . 'Either the reader broke or SFAF_Embed stopped flushing on delete_term.' );
}

foreach ( $callbacks as $cb ) {
    $body = sfaf_body_of( $cb['file'], $cb['name'] );
    if ( null === $body ) {
        fail( sprintf( '%s hooks %s() on a term deletion and that method cannot be found, so what it does is unknown',
            basename( $cb['file'] ), $cb['name'] ) );
        continue;
    }
    foreach ( $post_writes as $write ) {
        if ( false !== strpos( $body, $write . '(' ) ) {
            fail( sprintf(
                '%s::%s() runs when a series is deleted and calls %s(). An image tagged with that series '
                . 'must keep its file, its id and every event pointing at it.',
                basename( $cb['file'] ), $cb['name'], $write
            ) );
        }
    }
}

/* =========================================================================
 * 3. AND THE STATE IT LEAVES IS ONE THE SCREEN CAN SHOW.
 *
 * An image whose only series was deleted has no row in the taxonomy, which is
 * what NOT EXISTS asks for. If this ever became a "NOT IN every term" query it
 * would still look right and would silently stop finding those images.
 * ====================================================================== */
$GLOBALS['queries'] = array();
SFAF_Media::pictures( array( 'untagged' => true ) );
$q = $GLOBALS['queries'][0];
expect( 'untagged asks the taxonomy for objects with no row at all',
    isset( $q['tax_query'][0]['operator'] ) ? $q['tax_query'][0]['operator'] : '', 'NOT EXISTS' );
expect( 'and it is still the calendar folder',
    $q['meta_query'][0]['value'], '^calendar/' );

$GLOBALS['queries'] = array();
SFAF_Media::pictures( array( 'series' => 9 ) );
$q = $GLOBALS['queries'][0];
expect( 'a series filter asks for that term', $q['tax_query'][0]['terms'], 9 );

$GLOBALS['queries'] = array();
SFAF_Media::pictures();
expect( 'no filter asks the taxonomy nothing', isset( $GLOBALS['queries'][0]['tax_query'] ), false );

/* =========================================================================
 * 4. TAGGING ADDS AND NEVER REPLACES, AND CHECKS THE FOLDER PER ID.
 * ====================================================================== */
$GLOBALS['writes'] = array();
$done = SFAF_Media::add_tag( array( 71, 72, 73, 0 ), 9 );
expect( 'the two folder images are tagged', $done['did'], 2 );
expect( 'and the one outside the folder is refused', $done['refused'], 2 );
foreach ( $GLOBALS['writes'] as $w ) {
    expect( 'every write appends rather than replaces', $w['append'], true );
}

$done = SFAF_Media::add_tag( array( 71 ), 404 );
expect( 'a series that does not exist tags nothing', $done['did'], 0 );

/* =========================================================================
 * 5. THE THREE PERMISSIONS ARE THREE DIFFERENT ANSWERS.
 * ====================================================================== */
SFAF_Portal::$role = 'admin';
expect( 'an admin may upload', SFAF_Media::can_upload( 1 ), true );
expect( 'an admin may tag', SFAF_Media::can_tag( 1 ), true );

SFAF_Portal::$role = 'editor';
expect( 'an editor may NOT upload', SFAF_Media::can_upload( 1 ), false );
expect( 'an editor may tag', SFAF_Media::can_tag( 1 ), true );

SFAF_Portal::$role = 'contributor';
expect( 'a contributor may not upload', SFAF_Media::can_upload( 1 ), false );
expect( 'a contributor may not tag', SFAF_Media::can_tag( 1 ), false );
SFAF_Portal::$role = 'admin';

/* =========================================================================
 * SELF-TEST. A checker that cannot fail is not evidence.
 * ====================================================================== */
if ( $self ) {
    $caught = array();

    /* The planted files go in a temporary directory, so the readers above are
     * run against real files the way they are run against the real tree, and
     * nothing is written into the plugin. */
    $tmp = sys_get_temp_dir() . '/sfaf-media-tags-' . getmypid();
    @mkdir( $tmp, 0777, true );

    /* Plant 1: a term-deletion callback that deletes a post. This is the fault
     * the whole section exists to catch. */
    file_put_contents( $tmp . '/planted-a.php', "<?php\nclass P {\n"
        . "    public function register() { add_action( 'delete_term', array( \$this, 'tidy' ), 10, 3 ); }\n"
        . "    public function tidy( \$t ) { wp_delete_post( \$t, true ); }\n}\n" );
    $found = sfaf_deletion_callbacks( array( $tmp . '/planted-a.php' ), $deletion_hooks );
    $hit   = false;
    foreach ( $found as $cb ) {
        $body = sfaf_body_of( $cb['file'], $cb['name'] );
        if ( null !== $body && false !== strpos( $body, 'wp_delete_post(' ) ) { $hit = true; }
    }
    $caught['a deletion callback that deletes a post'] = $hit;

    /* Plant 2: the same words in a comment and in a plain string, which must
     * NOT be read as a registration or as a call. This is the grep trap. */
    file_put_contents( $tmp . '/planted-b.php', "<?php\nclass Q {\n"
        . "    /* add_action delete_term wp_delete_post */\n"
        . "    public function register() { \$x = 'delete_term'; }\n"
        . "    public function tidy() { /* wp_delete_post( 1 ); */ return 1; }\n}\n" );
    $noise = sfaf_deletion_callbacks( array( $tmp . '/planted-b.php' ), $deletion_hooks );
    $body  = sfaf_body_of( $tmp . '/planted-b.php', 'tidy' );
    $caught['the same names in comments and strings are not'] =
        ( empty( $noise ) && null !== $body && false === strpos( $body, 'wp_delete_post(' ) );

    @unlink( $tmp . '/planted-a.php' );
    @unlink( $tmp . '/planted-b.php' );
    @rmdir( $tmp );

    /* Plant 3: tagging that replaces instead of appending. */
    $GLOBALS['writes'] = array();
    wp_set_object_terms( 71, array( 9 ), 'uc_series', false );
    $replaced = false;
    foreach ( $GLOBALS['writes'] as $w ) {
        if ( true !== $w['append'] ) { $replaced = true; }
    }
    $caught['a replacing write is visible to the check'] = $replaced;

    foreach ( $caught as $what => $ok ) {
        printf( "  %-56s%s\n", $what, $ok ? 'caught' : 'MISSED' );
    }
    printf( "  %-56s%s\n", 'the real tree, unmodified', empty( $fails ) ? 'passes' : 'FAILS' );
    echo "\n";
    if ( in_array( false, $caught, true ) || ! empty( $fails ) ) {
        foreach ( $fails as $f ) { echo '  - ' . $f . "\n"; }
        echo "self-test FAILED\n";
        exit( 1 );
    }
    echo "self-test passed: every planted hole caught, no false positive.\n";
    exit( 0 );
}

if ( $fails ) {
    echo 'IMAGE TAGS: ' . count( $fails ) . " FAILURE(S)\n";
    foreach ( $fails as $f ) { echo '  - ' . $f . "\n"; }
    exit( 1 );
}

echo "Image tags are series\n";
echo "  vocabulary   uc_series, with attachments attached to it, so there is no\n";
echo "               second list to keep in step and renaming a series renames the tag\n";
echo "  deletion     what does run when a term is deleted writes no post, so an image\n";
echo "               whose series has gone keeps its file, its id and every event\n";
echo "               pointing at it, and loses one relationship row\n";
echo "  and then     it has no row in the taxonomy at all, which is what the Untagged\n";
echo "               filter asks for, so it surfaces rather than disappearing\n";
echo "  tagging      appends, never replaces, and checks the folder per id at the write\n";
echo "  permission   upload admins, tag admins and editors, look everybody\n";
echo "\n";
echo "wp_delete_term() itself touches no post. That is core's half and cannot be\n";
echo "tested here; this proves the half that is ours, which is that nothing has been\n";
echo "added that would.\n";
