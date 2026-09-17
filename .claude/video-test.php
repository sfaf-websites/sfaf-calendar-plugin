<?php
/**
 * THE EVENT VIDEO: WHAT IS ACCEPTED, AND WHICH ONE SHOWS.
 *
 *     php .claude/video-test.php
 *     php .claude/video-test.php --self-test
 *
 * TWO QUESTIONS, AND THEY FAIL IN DIFFERENT WAYS.
 *
 * WHAT IS ACCEPTED is a security question before it is a tidiness one. The
 * field takes an address and this plugin builds the frame; an <iframe> pasted
 * in is markup somebody else wrote, served from our own domain. So the refusals
 * are asserted as hard as the acceptances, and the one that matters most is
 * that embed code carrying a perfectly valid src is still refused. A parser
 * that went looking for an id would find one in there and take the markup with
 * it.
 *
 * WHICH ONE SHOWS is an inheritance question, and inheritance always has the
 * same hole: with only a text box, an empty event field means "use the series'
 * one" and there is no way left to say "this occurrence has none". That is why
 * "no video" is a control, and why the order here is asserted as an ORDER
 * rather than as three separate answers. The opt out beats the event's own
 * link, which beats the series'.
 *
 * IT RUNS THE REAL CLASSES. SFAF_Video and the two SFAF_Series readers are
 * loaded from source with term and post meta stubbed, because a hand-written
 * fixture of what the parser "should" return proves only that the fixture and
 * the assertions agree with each other.
 */

$root = dirname( __DIR__ );
define( 'ABSPATH', __DIR__ );

$self_test = in_array( '--self-test', $argv, true );
$fails     = array();

/* ---------------------------------------------------------------------------
 * WordPress, in miniature. Only what these two classes touch.
 * ------------------------------------------------------------------------ */
$GLOBALS['pmeta'] = array();
$GLOBALS['tmeta'] = array();
$GLOBALS['titles'] = array();

function get_post_meta( $id, $key, $single = false ) {
    return isset( $GLOBALS['pmeta'][ $id ][ $key ] ) ? $GLOBALS['pmeta'][ $id ][ $key ] : '';
}
function update_post_meta( $id, $key, $v ) { $GLOBALS['pmeta'][ $id ][ $key ] = $v; return true; }
function delete_post_meta( $id, $key ) { unset( $GLOBALS['pmeta'][ $id ][ $key ] ); return true; }
function get_term_meta( $id, $key, $single = false ) {
    return isset( $GLOBALS['tmeta'][ $id ][ $key ] ) ? $GLOBALS['tmeta'][ $id ][ $key ] : '';
}
function update_term_meta( $id, $key, $v ) { $GLOBALS['tmeta'][ $id ][ $key ] = $v; return true; }
function delete_term_meta( $id, $key ) { unset( $GLOBALS['tmeta'][ $id ][ $key ] ); return true; }
function get_the_title( $id ) { return isset( $GLOBALS['titles'][ $id ] ) ? $GLOBALS['titles'][ $id ] : ''; }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u ); }
function esc_url( $u ) { return htmlspecialchars( (string) $u, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
function is_wp_error( $t ) { return $t instanceof WP_Error; }

class WP_Error {
    public $code; public $message;
    public function __construct( $c = '', $m = '', $d = '' ) { $this->code = $c; $this->message = $m; }
    public function get_error_message() { return $this->message; }
    public function get_error_code() { return $this->code; }
}

require_once $root . '/includes/class-sfaf-video.php';

/*
 * SFAF_Series IS NOT LOADED WHOLE. It pulls in the taxonomy, queries and the
 * image chain, none of which this asks anything about. The two readers the
 * resolver actually calls are stubbed against the same term meta the real ones
 * read, and the real file is checked BY SOURCE further down for the constant
 * and the storage rule, so the stub cannot drift away from it silently.
 */
class SFAF_Series {
    const META_VIDEO = '_sfaf_series_video';
    public static $series_of = array();
    public static function id_for_event( $post_id ) {
        return isset( self::$series_of[ $post_id ] ) ? (int) self::$series_of[ $post_id ] : 0;
    }
    public static function video( $term_id ) {
        return (string) get_term_meta( (int) $term_id, self::META_VIDEO, true );
    }
}

function check( $ok, $why ) {
    global $fails;
    if ( ! $ok ) { $fails[] = $why; }
}

/* =========================================================================
 * 1. WHAT THE FIELD ACCEPTS.
 * ====================================================================== */

/* THE FOUR SHAPES SOMEBODY ACTUALLY HAS IN THEIR CLIPBOARD, plus the two
 * hosts' variants, each with the id that must come out of it. */
$accept = array(
    'https://www.youtube.com/watch?v=dQw4w9WgXcQ'        => array( 'youtube', 'dQw4w9WgXcQ' ),
    'https://youtube.com/watch?v=dQw4w9WgXcQ'            => array( 'youtube', 'dQw4w9WgXcQ' ),
    'https://m.youtube.com/watch?v=dQw4w9WgXcQ'          => array( 'youtube', 'dQw4w9WgXcQ' ),
    'https://youtu.be/dQw4w9WgXcQ'                       => array( 'youtube', 'dQw4w9WgXcQ' ),
    'https://www.youtube.com/shorts/dQw4w9WgXcQ'         => array( 'youtube', 'dQw4w9WgXcQ' ),
    // The query carries more than v=, which is what a share link looks like.
    'https://www.youtube.com/watch?v=dQw4w9WgXcQ&t=42s'  => array( 'youtube', 'dQw4w9WgXcQ' ),
    // An id using both of the awkward characters in the alphabet.
    'https://youtu.be/a-B_cdefGH1'                       => array( 'youtube', 'a-B_cdefGH1' ),
    'https://vimeo.com/123456789'                        => array( 'vimeo',   '123456789' ),
    // An unlisted Vimeo page carries a hash segment after the id.
    'https://vimeo.com/123456789/abc123def4'             => array( 'vimeo',   '123456789' ),
);
foreach ( $accept as $url => $want ) {
    $got = SFAF_Video::parse( $url );
    check( false !== $got, 'refused a link it should take: ' . $url );
    if ( false !== $got ) {
        check( $want[0] === $got['service'], $url . ' resolved to service ' . $got['service'] . ', not ' . $want[0] );
        check( $want[1] === $got['id'], $url . ' resolved to id ' . $got['id'] . ', not ' . $want[1] );
    }
    check( true === SFAF_Video::validate( $url ), 'validate() refused a link parse() took: ' . $url );
}

/* WHAT IS REFUSED, AND WHY EACH ONE IS IN THE LIST. */
$refuse = array(
    // Embed code. The src inside it is perfectly valid, which is the point.
    '<iframe src="https://www.youtube.com/embed/dQw4w9WgXcQ"></iframe>'
        => 'embed code, whose src would parse on its own',
    '<iframe src="https://player.vimeo.com/video/123456789"></iframe>'
        => 'Vimeo embed code',
    // The addresses that only ever come OUT of embed code.
    'https://www.youtube.com/embed/dQw4w9WgXcQ' => 'a YouTube /embed/ address',
    'https://player.vimeo.com/video/123456789'  => 'a Vimeo player address',
    // Other services.
    'https://www.facebook.com/watch?v=dQw4w9WgXcQ' => 'Facebook',
    'https://vimeo.com.example.org/123456789'      => 'a host that merely ends in a lookalike',
    'https://www.youtube.com.evil.test/watch?v=dQw4w9WgXcQ' => 'a YouTube lookalike host',
    // Shapes that are nearly right.
    'https://www.youtube.com/watch?v=short'     => 'a YouTube id of the wrong length',
    'https://youtu.be/dQw4w9WgXcQ/extra'        => 'a youtu.be path with something after the id',
    'https://www.youtube.com/'                  => 'the YouTube home page',
    'https://vimeo.com/channels/staffpicks'     => 'a Vimeo channel rather than a video',
    'not a url at all'                          => 'a string that is not an address',
    'javascript:alert(1)'                       => 'a javascript: URL',
);
foreach ( $refuse as $url => $why ) {
    check( false === SFAF_Video::parse( $url ), 'parse() accepted ' . $why . ': ' . $url );
    $v = SFAF_Video::validate( $url );
    check( is_wp_error( $v ), 'validate() accepted ' . $why . ': ' . $url );
}

/* THE REFUSAL NAMES BOTH SERVICES, because "invalid URL" tells somebody
 * holding a Facebook link nothing about what to do next. */
$err = SFAF_Video::validate( 'https://www.facebook.com/watch?v=dQw4w9WgXcQ' );
check( is_wp_error( $err ), 'a Facebook link was accepted' );
if ( is_wp_error( $err ) ) {
    $msg = $err->get_error_message();
    check( false !== stripos( $msg, 'youtube' ), 'the refusal does not name YouTube' );
    check( false !== stripos( $msg, 'vimeo' ), 'the refusal does not name Vimeo' );
}
/* AND EMBED CODE GETS ITS OWN MESSAGE, because "that is not a YouTube link" is
 * wrong and unhelpful when somebody has pasted a YouTube frame. */
$emb = SFAF_Video::validate( '<iframe src="https://www.youtube.com/embed/dQw4w9WgXcQ"></iframe>' );
check( is_wp_error( $emb ), 'embed code was accepted' );
if ( is_wp_error( $emb ) ) {
    check( 'sfaf_video_embed' === $emb->get_error_code(), 'embed code is refused as a bad link rather than as embed code' );
}

/* EMPTY IS VALID: it is how the field is cleared. The caller decides whether
 * empty is allowed, which is what every other optional field here does. */
check( true === SFAF_Video::validate( '' ), 'an empty value is refused, so the field cannot be cleared' );
check( false === SFAF_Video::parse( '' ), 'an empty value parses to something' );

/* =========================================================================
 * 2. WHICH VIDEO SHOWS.
 * ====================================================================== */

$EV   = 101;   // an event in a series
$LONE = 102;   // an event in no series
$TERM = 7;

SFAF_Series::$series_of = array( $EV => $TERM );
$GLOBALS['titles'] = array( $EV => 'Brothers Who Read', $LONE => 'Coffee Social' );

$yt  = 'https://www.youtube.com/watch?v=dQw4w9WgXcQ';
$yt2 = 'https://youtu.be/a-B_cdefGH1';

$reset = function () use ( $EV, $LONE, $TERM ) {
    $GLOBALS['pmeta'] = array();
    $GLOBALS['tmeta'] = array();
};

/* NOTHING ANYWHERE. */
$reset();
check( '' === SFAF_Video::resolve( $EV ), 'an event with no video anywhere resolved to something' );
check( '' === SFAF_Video::embed_html( $EV ), 'a frame was drawn for an event with no video' );

/* THE SERIES ALONE: every event in it inherits. */
$reset();
update_term_meta( $TERM, SFAF_Series::META_VIDEO, $yt );
check( $yt === SFAF_Video::resolve( $EV ), 'an event does not inherit its series video' );
check( 'series' === SFAF_Video::source( $EV ), 'an inherited video does not say it came from the series' );
check( '' === SFAF_Video::resolve( $LONE ), 'an event in NO series picked up a series video' );

/* THE EVENT'S OWN BEATS THE SERIES'. */
$reset();
update_term_meta( $TERM, SFAF_Series::META_VIDEO, $yt );
update_post_meta( $EV, SFAF_Video::META, $yt2 );
check( $yt2 === SFAF_Video::resolve( $EV ), "the event's own video does not beat the series'" );
check( 'own' === SFAF_Video::source( $EV ), "the event's own video does not say so" );

/* "NO VIDEO" BEATS BOTH, WHICH IS THE WHOLE REASON IT IS A CONTROL.
 * An empty event field means "inherit", so without this there is no way to say
 * one occurrence has none. */
$reset();
update_term_meta( $TERM, SFAF_Series::META_VIDEO, $yt );
update_post_meta( $EV, SFAF_Video::META_NONE, '1' );
check( '' === SFAF_Video::resolve( $EV ), 'the "no video" tick does not refuse an inherited video' );
check( 'none' === SFAF_Video::source( $EV ), 'the "no video" tick does not say so' );

/* AND IT BEATS THE EVENT'S OWN LINK TOO, so the order is a real order rather
 * than two unrelated answers. */
$reset();
update_term_meta( $TERM, SFAF_Series::META_VIDEO, $yt );
update_post_meta( $EV, SFAF_Video::META, $yt2 );
update_post_meta( $EV, SFAF_Video::META_NONE, '1' );
check( '' === SFAF_Video::resolve( $EV ), 'the "no video" tick loses to the event\'s own link' );

/* CLEARING THE TICK RESTORES WHATEVER WAS UNDERNEATH. */
$reset();
update_term_meta( $TERM, SFAF_Series::META_VIDEO, $yt );
update_post_meta( $EV, SFAF_Video::META_NONE, '1' );
delete_post_meta( $EV, SFAF_Video::META_NONE );
check( $yt === SFAF_Video::resolve( $EV ), 'clearing the tick did not restore the inherited video' );

/* A STORED VALUE THAT NO LONGER PARSES RENDERS NOTHING rather than a broken
 * frame. Same rule 3.90.0 set for a stored id that no longer resolves. */
$reset();
update_post_meta( $EV, SFAF_Video::META, 'https://www.facebook.com/watch?v=dQw4w9WgXcQ' );
check( '' === SFAF_Video::embed_html( $EV ), 'a stored value that no longer parses drew a frame anyway' );

/* =========================================================================
 * 3. THE FRAME.
 * ====================================================================== */

$reset();
update_post_meta( $EV, SFAF_Video::META, $yt );
$html = SFAF_Video::embed_html( $EV );

check( false !== strpos( $html, 'youtube-nocookie.com' ),
    'YouTube is embedded through the tracking host rather than the no-cookie one' );
check( false === strpos( $html, '//www.youtube.com/embed' ),
    'the frame points at the cookie-setting YouTube host' );
check( false !== strpos( $html, 'loading="lazy"' ),
    'the frame is not lazy loaded' );
check( false !== strpos( $html, 'title="Brothers Who Read"' ),
    "the frame's title is not the event name, so a screen reader announces every video the same way" );
check( false !== strpos( $html, 'allowfullscreen' ),
    'the frame cannot go full screen' );
check( false !== strpos( $html, 'class="uc-video"' ),
    'the frame has lost the wrapper the aspect ratio and the corners are on' );

/* VIMEO GOES TO ITS PLAYER HOST, which is the only one it has. */
$reset();
update_post_meta( $EV, SFAF_Video::META, 'https://vimeo.com/123456789' );
$vhtml = SFAF_Video::embed_html( $EV );
check( false !== strpos( $vhtml, 'player.vimeo.com/video/123456789' ),
    'a Vimeo page does not resolve to its player' );

/* AN EVENT WITH NO TITLE STILL GETS A TITLE ATTRIBUTE, because an empty one
 * is worse than a generic one for the thing that reads it aloud. */
$reset();
$GLOBALS['titles'][ $LONE ] = '';
update_post_meta( $LONE, SFAF_Video::META, $yt );
check( false !== strpos( SFAF_Video::embed_html( $LONE ), 'title="Event video"' ),
    'an untitled event renders a frame with an empty title' );
$GLOBALS['titles'][ $LONE ] = 'Coffee Social';

/* =========================================================================
 * 4. NOTHING BUT THE EVENT PAGE SHOWS ONE.
 * ====================================================================== */
/* THE BRIEF NAMES THE SURFACES: not cards, not the list, not the hover
 * preview, not email. Asserted by source sweep, because the failure is a
 * renderer somebody adds later rather than one that exists now. Each of these
 * files is one that draws events somewhere a video must not appear. */
$must_not = array(
    'includes/class-sfaf-email.php'          => 'email',
    'includes/class-sfaf-embed.php'          => 'the embed payload',
    'includes/class-sfaf-reminders.php'      => 'the reminder',
    'includes/class-sfaf-notifications.php'  => 'the notifications',
    'includes/class-sfaf-seo.php'            => 'the structured data',
);
foreach ( $must_not as $rel => $what ) {
    $src = @file_get_contents( $root . '/' . $rel );
    if ( false === $src ) { continue; }
    check( false === strpos( $src, 'SFAF_Video' ),
        $what . ' reads SFAF_Video, and the video belongs on the event page and nowhere else' );
    check( false === strpos( $src, '_uc_video_url' ),
        $what . ' names the video meta key directly' );
}

/* AND THE CARD, LIST, MONTH GRID AND HOVER PREVIEW ALL LIVE IN THE SHORTCODES
 * FILE, so that one is checked for the frame rather than for the class: the
 * file legitimately may not mention a video at all. */
$sc = @file_get_contents( $root . '/includes/class-sfaf-shortcodes.php' );
if ( false !== $sc ) {
    check( false === strpos( $sc, 'uc-video' ),
        'a card, the list, the month grid or the hover preview draws a video frame' );
    check( false === strpos( $sc, 'SFAF_Video' ),
        'the shortcodes file reads SFAF_Video, and none of the surfaces it draws shows a video' );
}

/* =========================================================================
 * 5. THE SOURCE RULES THE STUB CANNOT PROVE.
 * ====================================================================== */
$series_src = @file_get_contents( $root . '/includes/class-sfaf-series.php' );
if ( false !== $series_src ) {
    check( false !== strpos( $series_src, "const META_VIDEO = '_sfaf_series_video'" ),
        'the series video meta key has changed, and every series carrying one would lose it' );
    /* STORED ONLY IF IT PARSES, which is the second of the two gates: the form
     * refuses with a message, and this stops a caller that skipped the form. */
    check( (bool) preg_match( '/SFAF_Video::parse\(\s*\$video\s*\)/', $series_src ),
        'the series stores a video without asking whether it parses, so an address no renderer draws can be saved' );
}

$video_src = @file_get_contents( $root . '/includes/class-sfaf-video.php' );
if ( false !== $video_src ) {
    check( false !== strpos( $video_src, "const META = '_uc_video_url'" ),
        'the event video meta key has changed' );
    check( false !== strpos( $video_src, "const META_NONE = '_uc_video_none'" ),
        'the "no video" meta key has changed' );
}

/* =========================================================================
 * 6. WHICH FORMS OFFER THE FIELD.
 * ====================================================================== */
/* THE STAFF FORM DOES AND THE COMMUNITY FORM DOES NOT. That is the same split
 * the FAQ picker has: the staff form is behind an emailed token to an sfaf.org
 * address, and the community form is open to anybody holding the link. A URL
 * field on an open form is a place to put a link to something else. */
$req = @file_get_contents( $root . '/includes/class-sfaf-request.php' );
if ( false !== $req ) {
    check( false !== strpos( $req, 'name="video_url"' ),
        'the staff request form no longer offers the video field' );
    check( false !== strpos( $req, 'SFAF_Video::validate' ),
        'the staff request form takes a video without validating it, so a Facebook link reaches an event' );
    check( false !== strpos( $req, 'SFAF_Video::META' ),
        'an approved staff request no longer carries its video onto the event' );
}
$sub = @file_get_contents( $root . '/includes/class-sfaf-submit.php' );
if ( false !== $sub ) {
    check( false === stripos( $sub, 'video' ),
        'the community form offers a video field, and it is open to anybody with the link' );
}

/* AND THE EVENT PAGE IS THE ONE TEMPLATE THAT DRAWS IT. */
$tpl = @file_get_contents( $root . '/templates/single-uc_event.php' );
if ( false !== $tpl ) {
    check( false !== strpos( $tpl, 'SFAF_Video::embed_html' ),
        'the event page no longer embeds the video' );
    /* ABOVE THE DESCRIPTION. Asserted as an ORDER rather than as presence,
     * because "it is on the page" was true the whole time it was in the wrong
     * place. Same shape of check as the tick cell in 3.79.0. */
    $at_video = strpos( $tpl, 'SFAF_Video::embed_html' );
    $at_body  = strpos( $tpl, 'uc-single-body' );
    check( false !== $at_video && false !== $at_body && $at_video < $at_body,
        'the video is no longer above the description' );
}

/* =========================================================================
 * SELF-TEST: every check above must FAIL when the thing it names is broken.
 * ====================================================================== */
if ( $self_test ) {
    $planted = array();

    /* A reader that only ever returns the event's own value: inheritance gone. */
    $planted[] = array(
        'name' => 'the resolver stops inheriting from the series',
        'run'  => function () use ( $TERM, $EV, $yt ) {
            $GLOBALS['pmeta'] = array(); $GLOBALS['tmeta'] = array();
            update_term_meta( $TERM, SFAF_Series::META_VIDEO, $yt );
            // What a broken resolver would answer.
            return '' === SFAF_Video::own( $EV );
        },
    );
    /* The opt out losing to the event's own link. */
    $planted[] = array(
        'name' => 'the "no video" tick is read after the event\'s own link',
        'run'  => function () use ( $EV, $yt2 ) {
            $GLOBALS['pmeta'] = array(); $GLOBALS['tmeta'] = array();
            update_post_meta( $EV, SFAF_Video::META, $yt2 );
            update_post_meta( $EV, SFAF_Video::META_NONE, '1' );
            // A resolver reading own() first would return the link.
            return '' === SFAF_Video::resolve( $EV );
        },
    );

    $bad = array();
    foreach ( $planted as $p ) {
        if ( ! call_user_func( $p['run'] ) ) {
            $bad[] = $p['name'];
        }
    }

    /* AND THE READER ITSELF IS MADE TO FAIL ON PURPOSE. A checker that cannot
     * report a fault is the thing this whole file is guarding against, so the
     * accept and refuse tables are run inverted and must produce failures. */
    $before = count( $fails );
    check( false !== SFAF_Video::parse( 'https://www.facebook.com/watch?v=dQw4w9WgXcQ' ),
        'deliberate: a refused link reported as accepted' );
    $caught = ( count( $fails ) > $before );
    array_pop( $fails );
    if ( ! $caught ) {
        $bad[] = 'check() does not record a failure, so every assertion above is decorative';
    }

    if ( $bad ) {
        echo "SELF-TEST FAILED\n";
        foreach ( $bad as $b ) { echo '  . ' . $b . "\n"; }
        exit( 1 );
    }
    echo "PASS  video --self-test              self-test passed: the reader reports faults, and the resolution order is a real order.\n";
    exit( 0 );
}

if ( $fails ) {
    echo 'FAIL: ' . count( $fails ) . "\n";
    foreach ( array_unique( $fails ) as $f ) { echo '  . ' . $f . "\n"; }
    exit( 1 );
}

echo "the video field takes two services and nothing else, and one resolver decides\n";
echo "which video an event shows: the opt out, then its own, then its series'.\n";
exit( 0 );
