<?php
/**
 * PICTURES INSIDE A DESCRIPTION: THREE FOLDERS THAT DO NOT OVERLAP.
 *
 *     php .claude/desc-images-test.php
 *     php .claude/desc-images-test.php --self-test
 *
 * THE WHOLE FEATURE RESTS ON ONE PROPERTY: a picture is in exactly one of three
 * folders, and each folder is offered by exactly one kind of control.
 *
 *     calendar/                the featured pickers and the Images screen
 *     calendar-submissions/    nothing at all
 *     calendar-descriptions/   the Insert image chooser
 *
 * THE EXCLUSIONS ARE STRUCTURAL, NOT A LIST, and that is what this file is
 * mostly checking. `calendar-descriptions/` does not begin with `calendar/`, so
 * SFAF_Media_Folder's own anchored prefix excludes it with no change to that
 * rule. A folder named `calendar/descriptions/` would have been inside it, and
 * every description picture would have appeared in the featured picker. That is
 * the same trap `calendar-submissions/` was named to avoid, and the assertion
 * that catches it is a NAME assertion, because by the time it is a query it is
 * too late.
 *
 * AND THE PASTE RULE IS A SOURCE RULE. There is no way to tell a pasted picture
 * from a chosen one after the fact, so the question asked at save is not "did
 * somebody paste this" but "is this a file in our folder". Everything else goes,
 * whole tag and all: an <img> with its src removed is a broken image icon in
 * the middle of somebody's prose, which is worse than the paste not working.
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
 * WordPress, in miniature. Only what the two folder rules touch.
 * ------------------------------------------------------------------------ */
$GLOBALS['pmeta'] = array();
function get_post_meta( $id, $key, $single = false ) {
    return isset( $GLOBALS['pmeta'][ $id ][ $key ] ) ? $GLOBALS['pmeta'][ $id ][ $key ] : '';
}
/*
 * SWITCHABLE, BECAUSE THE "NO UPLOADS URL" BRANCH IS A REAL BRANCH AND A FIXED
 * STUB NEVER REACHES IT. A pass that fails OPEN when it cannot work out what
 * our own folder is would keep every pasted picture on a site where this call
 * returns nothing, and no assertion built on a stub that always answers could
 * ever see that.
 */
$GLOBALS['uploads_baseurl'] = 'https://example.test/wp-content/uploads';
function wp_get_upload_dir() {
    return array(
        'baseurl' => $GLOBALS['uploads_baseurl'],
        'basedir' => '/srv/uploads',
    );
}
function wp_kses_post( $v ) {
    // Enough of it for this file: script and event handlers out, the rest kept.
    $v = preg_replace( '#<script\b[\s\S]*?</script>#i', '', (string) $v );
    return preg_replace( '#\son\w+\s*=\s*([\'"]).*?\1#i', '', $v );
}

require_once $root . '/includes/class-sfaf-desc-images.php';

/* SFAF_Media_Folder's rule, lifted rather than loaded: the real file registers
 * hooks and queries. The prefix and the anchoring are what matter here, and the
 * source is checked below for the constant so this cannot drift from it. */
class Ref_Calendar_Folder {
    const FOLDER = 'calendar';
    public static function prefix() { return self::FOLDER . '/'; }
    public static function path_is_inside( $file ) {
        return ( 0 === strpos( ltrim( (string) $file, '/' ), self::prefix() ) );
    }
}

/* =========================================================================
 * 1. THE THREE FOLDERS DO NOT OVERLAP.
 * ====================================================================== */

/* THE NAME IS A SIBLING. This is the assertion that would have caught
 * `calendar/descriptions/`, and it has to be a name assertion: once the folder
 * exists on disk the pickers are already wrong. */
check( 'calendar-descriptions' === SFAF_Desc_Images::FOLDER,
    'the description folder has been renamed; if it now begins with "calendar/" every description picture is in the featured picker' );
check( 0 !== strpos( SFAF_Desc_Images::prefix(), Ref_Calendar_Folder::prefix() ),
    'the description folder is now INSIDE the calendar folder, so the featured picker offers floor plans and flyers' );

/* AND THE FEATURED PICKER'S OWN RULE EXCLUDES IT, with no change to that rule. */
$paths = array(
    'calendar-descriptions/floorplan.jpg' => array( 'desc' => true,  'cal' => false ),
    'calendar/latino.jpg'                 => array( 'desc' => false, 'cal' => true ),
    'calendar-submissions/submission-1.jpg' => array( 'desc' => false, 'cal' => false ),
    // Anchored at the front: somebody else's directory that shares a name.
    '2026/08/calendar-descriptions/x.jpg' => array( 'desc' => false, 'cal' => false ),
    'photos/calendar/x.jpg'               => array( 'desc' => false, 'cal' => false ),
);
foreach ( $paths as $file => $want ) {
    check( $want['desc'] === SFAF_Desc_Images::path_is_inside( $file ),
        'the Insert image chooser ' . ( $want['desc'] ? 'no longer offers' : 'now offers' ) . ': ' . $file );
    check( $want['cal'] === Ref_Calendar_Folder::path_is_inside( $file ),
        'the featured picker ' . ( $want['cal'] ? 'no longer offers' : 'now offers' ) . ': ' . $file );
}

/* THE SOURCE STILL SAYS WHAT THE REFERENCE ABOVE ASSUMES. */
$folder_src = file_get_contents( $root . '/includes/class-sfaf-media-folder.php' );
check( false !== strpos( $folder_src, "const FOLDER = 'calendar';" ),
    'the calendar folder was renamed, so the exclusion this file reasons about is no longer the one that ships' );
$up_src = file_get_contents( $root . '/includes/class-sfaf-uploads.php' );
check( false !== strpos( $up_src, "const FOLDER = 'calendar-submissions';" ),
    'the submissions folder was renamed' );

/* =========================================================================
 * 2. THE CHOOSER OFFERS ONE FOLDER, AND IT IS OURS.
 * ====================================================================== */
$desc_src = file_get_contents( $root . '/includes/class-sfaf-desc-images.php' );

check( (bool) preg_match( "/'value'\s*=>\s*'\^' \. preg_quote\( self::prefix\(\) \)/", $desc_src ),
    'the chooser no longer narrows to its own folder, so it offers the whole media library' );
check( (bool) preg_match( "/'compare'\s*=>\s*'REGEXP'/", $desc_src ),
    'the chooser matches its folder with something other than an anchored pattern' );
/* NEITHER OF THE OTHER TWO IS NAMED ANYWHERE IN IT. A mention could only ever
 * be a widening. */
/* COMMENTS STRIPPED FIRST. This file's own header names all three folders,
 * because explaining why they are separate requires saying what they are, and a
 * check that reads prose would fail on the explanation rather than on the code.
 * What must not name them is anything that RUNS. */
$desc_code = preg_replace( '#/\*[\s\S]*?\*/#', '', $desc_src );
$desc_code = preg_replace( '#//[^\n]*#', '', $desc_code );
check( false === strpos( $desc_code, "'calendar/'" ) && false === strpos( $desc_code, 'calendar-submissions' ),
    'the description chooser names one of the other two folders in code, which could only be a widening' );

/* =========================================================================
 * 3. NOTHING BUT THE CALADMIN EDITOR OFFERS THE BUTTON.
 * ====================================================================== */
$portal  = file_get_contents( $root . '/includes/class-sfaf-portal.php' );
$request = file_get_contents( $root . '/includes/class-sfaf-request.php' );
$submit  = file_get_contents( $root . '/includes/class-sfaf-submit.php' );
$rich    = file_get_contents( $root . '/includes/class-sfaf-rich-text.php' );

check( false !== strpos( $portal, 'description_image_chooser' ),
    'the caladmin editor no longer offers Insert image' );
/* NOT IN SFAF_Rich_Text, WHICH IS SHARED. Both public forms draw their
 * descriptions through it, so a button added there would appear on a page
 * reached by a link on somebody's phone with no account. */
check( false === strpos( $rich, 'Insert image' ) && false === strpos( $rich, 'SFAF_Desc_Images' ),
    'the shared rich text control draws Insert image, so it is on both public forms too' );
/* THE CONTROL, NOT THE PHRASE. "Insert image" appears on the community form as
 * a HINT, telling somebody sending extra pictures how one of them gets used,
 * and that sentence is true and is meant to be there. What must be absent is
 * the chooser itself. */
foreach ( array( 'the staff request form' => $request, 'the community form' => $submit ) as $which => $src ) {
    check( false === strpos( $src, 'SFAF_Desc_Images' ),
        $which . ' reads the description image folder' );
    check( false === strpos( $src, 'description_image_chooser' ),
        $which . ' renders the Insert image chooser' );
    check( false === strpos( $src, 'data-uc-desc-images' ),
        $which . ' carries the Insert image panel' );
}

/* AND NOT ON A LOCKED FIELD: a description a platform owns is rewritten within
 * the hour, so a picture put into it would vanish with no explanation. */
check( (bool) preg_match( "/if \( 'locked' !== \\\$state \) \{\s*\n\s*\\\$this->description_image_chooser\(\);/", $portal ),
    'the chooser is offered on a source-owned description, whose pictures the next fetch deletes' );

/* =========================================================================
 * 4. THE UPLOAD ENDPOINT.
 * ====================================================================== */
/* wp_ajax_ ONLY, NEVER wp_ajax_nopriv_. An upload endpoint reachable with no
 * account is the highest-risk thing this plugin could carry, and this one
 * writes into a PERMANENT folder an event page renders from. */
check( false === strpos( $portal, 'wp_ajax_nopriv_' . SFAF_Desc_Images::ACTION ),
    'the description image upload is reachable with no account' );
check( false !== strpos( $portal, "add_action( 'wp_ajax_' . SFAF_Desc_Images::ACTION" ),
    'the description image upload is no longer registered' );
/* AND THE HANDLER ASKS AGAIN, because wp_ajax_ only means "signed in" and a
 * WordPress site has subscribers. */
check( (bool) preg_match( '/function ajax_description_image\(\)[\s\S]{0,900}?\$this->can_create\( \$user \)/', $portal ),
    'the upload handler does not check the calendar role, and being signed in is not the same as being allowed' );
check( (bool) preg_match( '/function ajax_description_image\(\)[\s\S]{0,700}?check_ajax_referer\(/', $portal ),
    'the upload handler no longer checks its nonce' );

/* THE ONE GUARD IS STILL IN FRONT OF IT. Being signed in does not make a
 * crafted image safe, so all eight of inspect()'s decisions run exactly as
 * they do for a stranger's file. */
check( (bool) preg_match( '/SFAF_Uploads::inspect\( \$field \)/', $desc_src ),
    'the description upload skips the one guard between a file and the disk' );
/* AND IT CONFIRMS WHERE THE FILE LANDED, rather than trusting the filter it
 * set: a plugin filtering upload_dir later would put it somewhere else. */
check( (bool) preg_match( '/if \( ! self::holds\( \$attachment_id \) \) \{\s*\n\s*wp_delete_attachment/', $desc_src ),
    'the upload trusts its own upload_dir filter instead of confirming where the file went' );

/* =========================================================================
 * 5. PASTED PICTURES ARE DROPPED ON THE WAY IN.
 * ====================================================================== */
$ours  = 'https://example.test/wp-content/uploads/calendar-descriptions/floorplan.jpg';
$mine2 = 'https://example.test/wp-content/uploads/calendar-descriptions/poster-300x200.png';
$cal   = 'https://example.test/wp-content/uploads/calendar/latino.jpg';
$subs  = 'https://example.test/wp-content/uploads/calendar-submissions/submission-1.jpg';

$kept = SFAF_Desc_Images::keep_only_ours( '<p>Before</p><img src="' . $ours . '" alt="Plan" /><p>After</p>' );
check( false !== strpos( $kept, $ours ), 'a picture from our own folder was dropped' );
check( false !== strpos( $kept, 'Before' ) && false !== strpos( $kept, 'After' ),
    'the prose around a kept picture was damaged' );

/* A RESIZED COPY LIVES BESIDE THE ORIGINAL, so the -300x200 suffix is inside
 * the prefix and must survive. */
check( false !== strpos( SFAF_Desc_Images::keep_only_ours( '<img src="' . $mine2 . '" />' ), $mine2 ),
    'a resized copy of one of our own pictures was dropped' );

/* EVERYTHING ELSE GOES, WHOLE TAG AND ALL. */
$drops = array(
    'a hotlink to somebody else\'s server' => '<img src="https://elsewhere.test/x.jpg" />',
    'a base64 blob'                        => '<img src="data:image/png;base64,iVBORw0KGgo=" />',
    'a calendar folder picture'            => '<img src="' . $cal . '" />',
    'a submitted picture'                  => '<img src="' . $subs . '" />',
    'an img with no src at all'            => '<img alt="nothing" />',
    'a protocol relative hotlink'          => '<img src="//elsewhere.test/x.jpg" />',
);
foreach ( $drops as $what => $html ) {
    $out = SFAF_Desc_Images::keep_only_ours( '<p>Text</p>' . $html );
    check( false === stripos( $out, '<img' ), 'a pasted picture survived: ' . $what );
    check( false !== strpos( $out, 'Text' ), 'dropping ' . $what . ' took the prose with it' );
}

/* THE SCHEME IS NOT WHAT DECIDES IT: the same file over http and https is the
 * same file, and a site moving to https must not lose every picture. */
$http = str_replace( 'https://', 'http://', $ours );
check( false !== strpos( SFAF_Desc_Images::keep_only_ours( '<img src="' . $http . '" />' ), $http ),
    'the same picture over http was dropped, so a scheme change empties every description' );

/* PROSE WITH NO PICTURES IS UNTOUCHED, and cheaply. */
$plain = '<p>Nothing to do here.</p>';
check( $plain === SFAF_Desc_Images::keep_only_ours( $plain ), 'prose with no pictures was rewritten' );

/* AND IT FAILS CLOSED. With no uploads URL there is no way to tell our own
 * picture from anybody else's, and the safe answer is to keep neither. Keeping
 * everything would mean a site where wp_get_upload_dir() answers nothing stores
 * every hotlink and every base64 blob that was ever pasted into a description. */
$GLOBALS['uploads_baseurl'] = '';
$blind = SFAF_Desc_Images::keep_only_ours( '<p>Text</p><img src="' . $ours . '" />' );
check( false === stripos( $blind, '<img' ),
    'with no uploads URL the dropper keeps pictures, so it fails OPEN and a paste survives on a site it cannot read' );
check( false !== strpos( $blind, 'Text' ), 'failing closed took the prose with it' );
$GLOBALS['uploads_baseurl'] = 'https://example.test/wp-content/uploads';

/* THE PASS SANITISES FIRST. Dropping pictures out of raw submitted markup and
 * sanitising afterwards would be matching tags in a string that may still
 * contain anything. */
$nasty = '<script>alert(1)</script><img src="' . $ours . '" />';
$safe  = SFAF_Desc_Images::sanitize_description( $nasty );
check( false === stripos( $safe, '<script' ), 'the description pass does not sanitise' );
check( false !== strpos( $safe, $ours ), 'the description pass dropped a legitimate picture' );

/* THE ORDER IS ASSERTED BY SOURCE, AND THAT IS THE HONEST WAY TO ASSERT IT.
 *
 * Running the two the other way round produces the same bytes for every input
 * this file can build, because the kses stub above is a simplification: the
 * real one normalises malformed markup, and the difference only shows on input
 * that is malformed enough for the two passes to disagree about where a tag
 * ends. An <img> written inside an attribute value is the shape that does it,
 * and a stub cannot reproduce it faithfully enough to be evidence.
 *
 * So what is checked is the thing that actually has to hold: pictures are
 * dropped out of markup that has ALREADY been sanitised, never out of whatever
 * arrived. A regex hunting for tags in raw submitted markup is the fault, and
 * the order is the whole of the fix. */
check( (bool) preg_match( '/return self::keep_only_ours\( wp_kses_post\( \(string\) \$html \) \);/', $desc_src ),
    'the description pass drops pictures out of raw markup and sanitises afterwards, so the dropper is matching tags in a string that may still contain anything' );

/* AND THE EVENT SAVE USES IT. */
check( (bool) preg_match( "/\\\$postarr\\['post_content'\\] = SFAF_Desc_Images::sanitize_description\(/", $portal ),
    'the event description is saved without the pass, so a pasted picture is stored' );

/* THE PUBLIC FORMS NEVER ALLOWED ONE. Their whitelist has no img, so nothing
 * was needed there; this asserts that remains true rather than assuming it. */
$subm_src = file_get_contents( $root . '/includes/class-sfaf-submissions.php' );
check( (bool) preg_match( "/function prose\([\s\S]{0,1400}?'a'\s*=>/", $subm_src )
    && ! preg_match( "/function prose\([\s\S]{0,1400}?'img'\s*=>/", $subm_src ),
    'the public forms now allow an img in a description, so a pasted picture reaches an event from a form with no account' );

/* =========================================================================
 * 6. ONE SHAPE, IN BOTH STYLESHEETS.
 * ====================================================================== */
/* The editor frame and the event page are two documents, so the rule is written
 * twice. What matters is that they agree: that is what makes the editor an
 * honest preview rather than a different opinion. */
$editor_css = file_get_contents( $root . '/public/css/editor-content.css' );
$cal_css    = file_get_contents( $root . '/public/css/calendar.css' );

foreach ( array(
    'the editor frame'  => array( $editor_css, 'body#tinymce img' ),
    'the event page'    => array( $cal_css, '.uc-single .uc-single-body img' ),
) as $which => $pair ) {
    list( $css, $sel ) = $pair;
    $at = strpos( $css, $sel . ' {' );
    check( false !== $at, $which . ' has no rule for a picture in a description' );
    if ( false === $at ) { continue; }
    $block = substr( $css, $at, strpos( $css, '}', $at ) - $at );
    check( false !== strpos( $block, 'border-radius: 14px' ), $which . ' does not give the picture 14px corners' );
    check( false !== strpos( $block, 'width: 100%' ), $which . ' does not give the picture the full column width' );
    check( false !== strpos( $block, 'height: auto' ),
        $which . ' does not free the height, so a picture with width and height attributes is squashed' );
    check( false !== strpos( $block, 'float: none' ), $which . ' allows the picture to float' );
}

/* =========================================================================
 * SELF-TEST.
 * ====================================================================== */
if ( $self_test ) {
    $bad = array();

    $before = count( $fails );
    check( SFAF_Desc_Images::path_is_inside( 'calendar/latino.jpg' ),
        'deliberate: a calendar picture reported as ours' );
    if ( count( $fails ) === $before ) {
        $bad[] = 'check() does not record a failure, so every assertion above is decorative';
    } else {
        array_pop( $fails );
    }

    /* The dropper must really drop: proving section 5 by construction rather
     * than by trusting the assertions in it. */
    if ( false !== stripos( SFAF_Desc_Images::keep_only_ours( '<img src="https://elsewhere.test/x.jpg" />' ), '<img' ) ) {
        $bad[] = 'keep_only_ours() keeps a hotlink, so section 5 proves nothing';
    }
    if ( false === stripos( SFAF_Desc_Images::keep_only_ours( '<img src="' . $ours . '" />' ), '<img' ) ) {
        $bad[] = 'keep_only_ours() drops our own pictures, so it is not a filter, it is a delete';
    }

    if ( $bad ) {
        echo "SELF-TEST FAILED\n";
        foreach ( $bad as $b ) { echo '  . ' . $b . "\n"; }
        exit( 1 );
    }
    echo "PASS  desc-images --self-test        self-test passed: the reader reports faults and the dropper really drops.\n";
    exit( 0 );
}

if ( $fails ) {
    echo 'FAIL: ' . count( $fails ) . "\n";
    foreach ( array_unique( $fails ) as $f ) { echo '  . ' . $f . "\n"; }
    exit( 1 );
}

echo "three folders that do not overlap, one chooser that offers one of them,\n";
echo "and every picture in a description came through the button.\n";
exit( 0 );
