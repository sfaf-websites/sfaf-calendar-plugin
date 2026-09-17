<?php
/**
 * THE EXTRA SUBMITTED PICTURES, AND WHAT THEY ARE NOT.
 *
 *     php .claude/extra-images-test.php
 *     php .claude/extra-images-test.php --self-test
 *
 * WHAT THEY ARE. Up to two more photographs beside the featured picture, on
 * both public forms, through the same upload code and into the same
 * submissions folder.
 *
 * WHAT THEY ARE NOT IS THE WHOLE OF THIS FILE. They are not candidates for the
 * event's picture. No picker lists them, approval copies none of them anywhere,
 * nothing reads them looking for a thumbnail, and nothing about them touches
 * `_uc_image_url`. That last one is not hypothetical: 3.72.0's "Use this image"
 * button destroyed a typed image URL as a side effect and 3.95.0 removed it, so
 * a new path from a submitted file to the event's picture is exactly the shape
 * of the fault this project has already shipped once.
 *
 * SO THE LOAD-BEARING ASSERTIONS HERE ARE ABSENCES, and they are swept from
 * source rather than listed, because the failure is a reader somebody adds
 * later.
 *
 * TWO INPUTS, NOT ONE `multiple`. SFAF_Uploads::inspect() reads $_FILES[$field]
 * as one file and a multiple input hands it arrays in every slot. Two named
 * inputs mean all thirteen of its checks run exactly as they do for the
 * featured picture, with nothing duplicated and nothing relaxed.
 */

$root = dirname( __DIR__ );
define( 'ABSPATH', __DIR__ );

$self_test = in_array( '--self-test', $argv, true );
$fails     = array();

function check( $ok, $why ) {
    global $fails;
    if ( ! $ok ) { $fails[] = $why; }
}

/* ---------------------------------------------------------------------------
 * WordPress and the upload layer, in miniature.
 * ------------------------------------------------------------------------ */
$GLOBALS['pmeta'] = array();

function get_post_meta( $id, $key, $single = false ) {
    return isset( $GLOBALS['pmeta'][ $id ][ $key ] ) ? $GLOBALS['pmeta'][ $id ][ $key ] : '';
}
function update_post_meta( $id, $key, $v ) { $GLOBALS['pmeta'][ $id ][ $key ] = $v; return true; }
function delete_post_meta( $id, $key ) { unset( $GLOBALS['pmeta'][ $id ][ $key ] ); return true; }
function esc_attr( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
function esc_html( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }

/*
 * THE UPLOAD LAYER IS STUBBED AND THE STUB IS THE POINT OF THE RUNTIME HALF:
 * it records which fields were asked for. store_extras() reading a third field
 * would show up here as a third call, which is the "a third extra picture
 * accepted" fault in its only real shape.
 */
class SFAF_Uploads {
    const MAX_BYTES = 10485760;
    const MIN_WIDTH = 1200;
    public static $asked = array();
    /** field => array( id, error, warning ) the stub should answer with. */
    public static $answers = array();

    public static function store( $field, $limiter = null ) {
        self::$asked[] = $field;
        if ( isset( self::$answers[ $field ] ) ) {
            return self::$answers[ $field ];
        }
        return array( 'id' => 0, 'error' => '', 'warning' => '' );
    }
    public static function url( $id, $size = 'medium' ) {
        return $id ? ( 'https://example.test/' . (int) $id . '-' . $size . '.jpg' ) : '';
    }
}

/*
 * SFAF_Submit IS NOT LOADED WHOLE. It is 1800 lines and pulls in the whole
 * submission path; what is under test is five small static methods. They are
 * read out of the real file and evaluated, so the test runs the shipped code
 * rather than a copy, and the class name is checked to have actually appeared.
 */
$submit_src = file_get_contents( $root . '/includes/class-sfaf-submit.php' );

/*
 * THE CONSTANTS ARE LIFTED TOO, NOT RETYPED. Writing `const MAX_EXTRA = 2` here
 * made the cap a fact about this file rather than about the plugin: raising it
 * in the source changed nothing here and the "a third picture is accepted"
 * plant went straight past. A value under test that the test also declares is
 * not under test at all.
 */
eval( 'class SFAF_Submit {
' . sfaf_lift_const( $submit_src, 'META_IMAGE_EXTRA' )
  . sfaf_lift_const( $submit_src, 'META_IMAGE_EXTRA_NOTE' )
  . sfaf_lift_const( $submit_src, 'MAX_EXTRA' )
  . sfaf_lift( $submit_src, 'extra_fields' )
  . sfaf_lift( $submit_src, 'store_extras' )
  . sfaf_lift( $submit_src, 'extras' )
  . sfaf_lift( $submit_src, 'extra_notes' )
  . sfaf_lift( $submit_src, 'save_extras' )
  . '}' );

/**
 * Take one public static method out of the source, brace-balanced.
 *
 * BALANCE IS NOT VALIDITY, so what comes out is handed to PHP rather than
 * trusted: an unbalanced lift is a parse error at eval() and the file stops,
 * which is the loudest possible way to find out.
 */
/**
 * Take one class constant out of the source, so its VALUE is the plugin's.
 */
function sfaf_lift_const( $src, $name ) {
    if ( ! preg_match( '/^\s*const\s+' . preg_quote( $name, '/' ) . '\s*=\s*[^;]+;/m', $src, $m ) ) {
        echo "FAIL: could not find const " . $name . " in the source, so this test is reading nothing.\n";
        exit( 1 );
    }
    return trim( $m[0] ) . "\n";
}

function sfaf_lift( $src, $name ) {
    $at = strpos( $src, 'public static function ' . $name . '(' );
    if ( false === $at ) {
        echo "FAIL: could not find " . $name . "() in the source, so this test is reading nothing.\n";
        exit( 1 );
    }
    $open  = strpos( $src, '{', $at );
    $depth = 0;
    for ( $i = $open; $i < strlen( $src ); $i++ ) {
        if ( '{' === $src[ $i ] ) { $depth++; }
        if ( '}' === $src[ $i ] ) {
            $depth--;
            if ( 0 === $depth ) {
                return substr( $src, $at, $i - $at + 1 ) . "\n";
            }
        }
    }
    echo "FAIL: " . $name . "() never closes.\n";
    exit( 1 );
}

/* =========================================================================
 * 1. TWO, AND THE NAMES ARE THE TWO INPUTS THE FORMS DRAW.
 * ====================================================================== */
check( 2 === SFAF_Submit::MAX_EXTRA, 'the cap is no longer two' );
$fields = SFAF_Submit::extra_fields();
check( is_array( $fields ) && 2 === count( $fields ),
    'extra_fields() offers ' . count( $fields ) . ' inputs rather than two' );
check( array( 'uc_image_extra_1', 'uc_image_extra_2' ) === $fields,
    'the extra field names have changed, so the forms and the handler no longer agree' );

/* =========================================================================
 * 2. A THIRD FILE IS NEVER READ.
 * ====================================================================== */
/* THE ONLY SHAPE THIS FAULT HAS. The cap is not enforced by counting after the
 * fact; it is enforced by there being two field names. Somebody posting
 * uc_image_extra_3 is posting a field nothing asks for. So what is asserted is
 * that the upload layer is asked for exactly the two. */
SFAF_Uploads::$asked   = array();
SFAF_Uploads::$answers = array(
    'uc_image_extra_1' => array( 'id' => 11, 'error' => '', 'warning' => '' ),
    'uc_image_extra_2' => array( 'id' => 12, 'error' => '', 'warning' => '' ),
    'uc_image_extra_3' => array( 'id' => 13, 'error' => '', 'warning' => '' ),
);
$got = SFAF_Submit::store_extras( null );
check( array( 'uc_image_extra_1', 'uc_image_extra_2' ) === SFAF_Uploads::$asked,
    'store_extras() asked the upload layer for ' . implode( ', ', SFAF_Uploads::$asked ) . ' rather than the two fields' );
check( 2 === count( $got['ids'] ),
    'store_extras() returned ' . count( $got['ids'] ) . ' pictures rather than at most two' );
check( ! in_array( 13, $got['ids'], true ),
    'a third posted picture was accepted' );

/* AN EMPTY INPUT IS NOT AN ERROR AND NOT A PICTURE. */
SFAF_Uploads::$asked   = array();
SFAF_Uploads::$answers = array(
    'uc_image_extra_1' => array( 'id' => 0, 'error' => '', 'warning' => '' ),
    'uc_image_extra_2' => array( 'id' => 0, 'error' => '', 'warning' => '' ),
);
$none = SFAF_Submit::store_extras( null );
check( array() === $none['ids'], 'two empty inputs produced pictures' );
check( array() === $none['errors'], 'two empty inputs produced errors' );

/* A REFUSED ONE IS REPORTED AGAINST ITS OWN FIELD, so the form can say which
 * picture it was rather than "one of them". */
SFAF_Uploads::$answers = array(
    'uc_image_extra_1' => array( 'id' => 0, 'error' => 'That file is too big.', 'warning' => '' ),
    'uc_image_extra_2' => array( 'id' => 22, 'error' => '', 'warning' => 'It is narrow.' ),
);
$mixed = SFAF_Submit::store_extras( null );
check( isset( $mixed['errors']['uc_image_extra_1'] ),
    'a refused extra is not reported against the input it came from' );
check( array( 22 ) === $mixed['ids'],
    'a refused extra took the good one down with it' );
check( array( 'It is narrow.' ) === $mixed['warnings'],
    'the size warning does not travel with an extra picture' );

/* =========================================================================
 * 3. WHAT IS STORED, AND WHAT IS READ BACK.
 * ====================================================================== */
$GLOBALS['pmeta'] = array();
SFAF_Submit::save_extras( 500, array( 11, 12 ), array( '', 'It is narrow.' ) );
check( array( 11, 12 ) === SFAF_Submit::extras( 500 ),
    'the extras did not come back in the order they were stored' );
check( array( '', 'It is narrow.' ) === SFAF_Submit::extra_notes( 500 ),
    'the warnings did not come back alongside the pictures' );

/* NOTHING IS WRITTEN WHEN THERE IS NOTHING, so an absent key means a
 * submission that sent none rather than one nobody has looked at. */
$GLOBALS['pmeta'] = array();
SFAF_Submit::save_extras( 501, array(), array() );
check( array() === SFAF_Submit::extras( 501 ), 'an empty save wrote something' );
check( '' === get_post_meta( 501, SFAF_Submit::META_IMAGE_EXTRA, true ),
    'an empty save wrote the extras key' );

/* AND NO WARNING KEY WHEN EVERY PICTURE WAS FINE. */
$GLOBALS['pmeta'] = array();
SFAF_Submit::save_extras( 502, array( 11 ), array( '' ) );
check( '' === get_post_meta( 502, SFAF_Submit::META_IMAGE_EXTRA_NOTE, true ),
    'a warning key was written for a picture with nothing wrong with it' );

/* A KEY THAT IS NOT AN ARRAY READS AS NONE rather than fatally. */
$GLOBALS['pmeta'] = array();
update_post_meta( 503, SFAF_Submit::META_IMAGE_EXTRA, 'nonsense' );
check( array() === SFAF_Submit::extras( 503 ), 'a corrupt extras value is not treated as none' );

/* =========================================================================
 * 4. BOTH FORMS OFFER IT, AND BOTH HANDLERS TIDY UP.
 * ====================================================================== */
$sub  = file_get_contents( $root . '/includes/class-sfaf-submit.php' );
$req  = file_get_contents( $root . '/includes/class-sfaf-request.php' );
$subm = file_get_contents( $root . '/includes/class-sfaf-submissions.php' );

check( false !== strpos( $subm, 'function extra_images_field' ),
    'the shared control that draws the extra inputs is gone' );
foreach ( array( 'the community form' => $sub, 'the staff form' => $req ) as $which => $src ) {
    check( false !== strpos( $src, 'extra_images_field' ),
        $which . ' no longer draws the extra picture inputs' );
    check( false !== strpos( $src, 'store_extras' ),
        $which . ' no longer stores the extra pictures' );
    check( false !== strpos( $src, 'save_extras' ),
        $which . ' no longer saves the extra pictures onto the event' );
    /* A REJECTED SUBMISSION KEEPS NO FILES, and that has to include these. A
     * file input cannot be refilled by the server, so every rejected attempt
     * would otherwise leave up to three orphans on disk.
     *
     * COUNTED, NOT FOUND. There are TWO paths that abandon a submission after
     * the files have landed: the validation failure and the save failure. A
     * check that merely finds one such loop passes while the other has been
     * taken out, which is exactly what it did. */
    $tidied = preg_match_all( '/foreach \(\s*\$extra\[.ids.\]\s+as\s+\$extra_id\s*\)\s*\{\s*wp_delete_attachment/', $src );
    check( 2 === $tidied,
        $which . ' deletes its extra pictures on ' . (int) $tidied . ' of the two paths that abandon a submission' );
}

/* =========================================================================
 * 5. THEY ARE NOT CANDIDATES FOR ANYTHING. The absences.
 * ====================================================================== */
$portal = file_get_contents( $root . '/includes/class-sfaf-portal.php' );
$key    = '_uc_submitted_image_extra';

/* NOTHING SETS ONE AS A PICTURE.
 *
 * READ THE RENDERER'S WHOLE BODY, not a window of characters after the meta
 * key. The first version of this looked within 400 non-semicolon characters of
 * the key, so a write using SFAF_Submit::extras() rather than the raw key, on
 * the line after a statement, sat outside the window and went straight past.
 * The body is the thing that must be clean, so the body is what is read. */
$extras_fn = '';
$fn_at     = strpos( $portal, 'private function render_submitted_extras' );
if ( false !== $fn_at ) {
    $open  = strpos( $portal, '{', $fn_at );
    $depth = 0;
    for ( $i = $open; $i < strlen( $portal ); $i++ ) {
        if ( '{' === $portal[ $i ] ) { $depth++; }
        if ( '}' === $portal[ $i ] ) {
            $depth--;
            if ( 0 === $depth ) { $extras_fn = substr( $portal, $fn_at, $i - $fn_at + 1 ); break; }
        }
    }
}
check( '' !== $extras_fn, 'the extras renderer could not be read, so nothing below was checked' );
check( false === strpos( $extras_fn, '_thumbnail_id' ),
    'something sets an extra submitted picture as the event thumbnail' );
check( false === strpos( $extras_fn, '_uc_image_url' ),
    'something writes the typed image URL from an extra submitted picture' );
check( false === strpos( $extras_fn, 'update_post_meta' ),
    'the extras renderer writes meta; it draws a read-only record of what was sent' );
/* AND NOWHERE ELSE IN THE EDITOR EITHER. The key and the reader are both
 * checked, because a later writer may reach for whichever is to hand. */
foreach ( array( $key, 'SFAF_Submit::extras(' ) as $needle ) {
    check( ! preg_match( '/' . preg_quote( $needle, '/' ) . '.{0,400}?_thumbnail_id/s', $portal ),
        'something near the extra submitted pictures sets a thumbnail' );
}

/* AND NOTHING ABOUT THEM DELETES THE TYPED URL. The count embed-modes-test.php
 * pins is two; this says the same thing from the other side, so adding a third
 * deletion in this feature's name fails here as well as there. */
$deletes = substr_count( $portal, "delete_post_meta( \$event_id, '_uc_image_url' )" );
check( 2 === $deletes,
    sprintf( 'the typed image URL is deleted in %d places rather than the two the editor form owns', $deletes ) );

/* NO PICKER LISTS THEM. The pickers and the folder rule must not name the key
 * at all: they narrow to the calendar folder, and these are in the
 * submissions folder, so a mention could only ever be a widening. */
foreach ( array(
    'includes/class-sfaf-media.php'        => 'the image picker',
    'includes/class-sfaf-media-folder.php' => 'the folder rule',
) as $rel => $what ) {
    $src = file_get_contents( $root . '/' . $rel );
    check( false === strpos( $src, $key ), $what . ' names the extra submitted pictures' );
    check( false === strpos( $src, 'extras(' ), $what . ' reads the extra submitted pictures' );
}

/* NOTHING PUBLIC RENDERS THEM. */
foreach ( array(
    'includes/class-sfaf-shortcodes.php' => 'the cards, list and month grid',
    'includes/class-sfaf-embed.php'      => 'the embed payload',
    'includes/class-sfaf-email.php'      => 'email',
    'templates/single-uc_event.php'      => 'the event page',
) as $rel => $what ) {
    $src = @file_get_contents( $root . '/' . $rel );
    if ( false === $src ) { continue; }
    check( false === strpos( $src, $key ), $what . ' names the extra submitted pictures' );
    check( false === strpos( $src, 'SFAF_Submit::extras' ), $what . ' reads the extra submitted pictures' );
}

/* =========================================================================
 * 6. ONE RENDERER, TWO SURFACES.
 * ====================================================================== */
check( 1 === substr_count( $portal, 'private function render_submitted_extras' ),
    'there is not exactly one renderer for the extra pictures' );
check( 2 === substr_count( $portal, '$this->render_submitted_extras(' ),
    'the extra pictures are drawn on ' . substr_count( $portal, '$this->render_submitted_extras(' )
        . ' surfaces rather than on the pending row and the request panel' );
/* EACH IS AN ANCHOR TO THE FULL FILE IN A NEW TAB, because looking at one
 * properly and then downloading it is the only thing anybody can do with it. */
check( (bool) preg_match( '/render_submitted_extras[\s\S]{0,2000}target="_blank"/', $portal ),
    'the extra pictures are no longer links to the full size file' );
/* AND THE GROUP CARRIES A LABEL, which is what distinguishes the featured one
 * from the rest when they are side by side. */
check( (bool) preg_match( '/render_submitted_extras[\s\S]{0,2000}Also sent/', $portal ),
    'the extra pictures lost the label that tells them apart from the featured one' );

/* =========================================================================
 * SELF-TEST.
 * ====================================================================== */
if ( $self_test ) {
    $bad = array();

    /* The reader must record a failure, or every assertion above is decorative. */
    $before = count( $fails );
    check( 3 === SFAF_Submit::MAX_EXTRA, 'deliberate: a wrong cap reported as right' );
    if ( count( $fails ) === $before ) {
        $bad[] = 'check() does not record a failure, so every assertion above is decorative';
    } else {
        array_pop( $fails );
    }

    /* The lift must actually be reading the shipped source. */
    if ( false === strpos( $submit_src, 'public static function store_extras(' ) ) {
        $bad[] = 'store_extras() is not in the source this file claims to read';
    }

    /* And the third-picture guard must be a real guard: asking the stub for a
     * third field has to be visible. */
    SFAF_Uploads::$asked = array();
    SFAF_Uploads::store( 'uc_image_extra_3' );
    if ( array( 'uc_image_extra_3' ) !== SFAF_Uploads::$asked ) {
        $bad[] = 'the stub does not record which fields were asked for, so the third-picture check proves nothing';
    }

    if ( $bad ) {
        echo "SELF-TEST FAILED\n";
        foreach ( $bad as $b ) { echo '  . ' . $b . "\n"; }
        exit( 1 );
    }
    echo "PASS  extra-images --self-test       self-test passed: the reader reports faults and reads the shipped source.\n";
    exit( 0 );
}

if ( $fails ) {
    echo 'FAIL: ' . count( $fails ) . "\n";
    foreach ( array_unique( $fails ) as $f ) { echo '  . ' . $f . "\n"; }
    exit( 1 );
}

echo "two extra pictures, through the same upload code, and candidates for nothing:\n";
echo "no picker lists them, nothing sets one as a picture, and the typed URL is untouched.\n";
exit( 0 );
