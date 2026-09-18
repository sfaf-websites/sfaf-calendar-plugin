<?php
/**
 * THE TWO VIDEO PARSERS MUST AGREE, ADDRESS FOR ADDRESS.
 *
 *     php .claude/video-parse-crosscheck.php
 *     php .claude/video-parse-crosscheck.php --self-test
 *
 * THERE ARE TWO, AND THAT IS A DEBT RATHER THAN A DESIGN.
 * `SFAF_Video::parse()` decides what is stored and what plays on the event
 * page; `ucVideoEmbed()` in portal.js decides what the editor's preview shows
 * while somebody is typing, which no server can answer. The second exists only
 * because the preview has to react to a keystroke.
 *
 * SO THEY ARE RUN AGAINST THE SAME LIST AND COMPARED. Same arrangement as
 * .claude/recurrence-crosscheck.php, for the same reason: a second copy of a
 * rule is safe exactly as long as something fails when the two drift. Change
 * one and this tells you about the other.
 *
 * WHAT IT DOES NOT CHECK. The refusal MESSAGES, which only the PHP has, and
 * `validate()`, which is the PHP's own job. This is about one question: given
 * this address, what is the player src, or nothing.
 *
 * Node runs the JS half. There is no node on the machine, this says so and
 * stops rather than passing quietly.
 */

$root = dirname( __DIR__ );
define( 'ABSPATH', __DIR__ );

$self_test = in_array( '--self-test', $argv, true );

/* ---------------------------------------------------------------------------
 * The addresses. Every shape either parser has a branch for, plus the ones
 * they must both REFUSE, which is the half a happy-path list misses.
 * ------------------------------------------------------------------------ */
$cases = array(
    /* ---- YouTube, accepted. ---- */
    'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
    'https://youtube.com/watch?v=dQw4w9WgXcQ',
    'https://m.youtube.com/watch?v=dQw4w9WgXcQ',
    'https://music.youtube.com/watch?v=dQw4w9WgXcQ',
    'http://www.youtube.com/watch?v=dQw4w9WgXcQ',
    'https://www.youtube.com/watch?v=dQw4w9WgXcQ&t=42s',
    'https://www.youtube.com/watch?list=PLabc&v=dQw4w9WgXcQ',
    'https://www.youtube.com/shorts/dQw4w9WgXcQ',
    'https://youtu.be/dQw4w9WgXcQ',
    'https://youtu.be/dQw4w9WgXcQ?t=10',
    'https://www.youtube.com/watch?v=_-aBcDeFgHi',

    /* ---- YouTube, refused. ---- */
    'https://www.youtube.com/embed/dQw4w9WgXcQ',
    'https://www.youtube.com/watch?v=tooshort',
    'https://www.youtube.com/watch?v=dQw4w9WgXcQextra',
    'https://www.youtube.com/watch',
    'https://www.youtube.com/',
    'https://www.youtube.com/shorts/dQw4w9WgXcQ/more',
    'https://youtu.be/dQw4w9WgXcQ/more',
    'https://youtu.be/',

    /* ---- Vimeo, accepted. ---- */
    'https://vimeo.com/123456',
    'https://vimeo.com/123456789',
    'https://www.vimeo.com/123456789',
    'https://vimeo.com/123456789/a1b2c3d4e5',

    /* ---- Vimeo, refused. ---- */
    'https://vimeo.com/12345',
    'https://player.vimeo.com/video/123456789',
    'https://vimeo.com/channels/staffpicks',
    'https://vimeo.com/123456789/a1b2c3d4e5/more',

    /* ---- Not a video service at all. ---- */
    'https://example.org/watch?v=dQw4w9WgXcQ',
    'https://dailymotion.com/video/x7abcde',
    'not a url at all',
    '',
    '   ',

    /* ---- Embed code, refused by both before anything parses it. ---- */
    '<iframe src="https://www.youtube.com/embed/dQw4w9WgXcQ"></iframe>',
    'https://vimeo.com/123456789<script>',
);

/* ---------------------------------------------------------------------------
 * The PHP half. Only the class, with the two WordPress functions it uses.
 * ------------------------------------------------------------------------ */
function wp_parse_url( $url, $component = -1 ) { return parse_url( (string) $url, $component ); }
function esc_url( $u ) { return (string) $u; }
function esc_attr( $t ) { return (string) $t; }
function esc_html( $t ) { return (string) $t; }
function get_post_meta( $id, $key, $single = false ) { return ''; }
function get_term_meta( $id, $key, $single = false ) { return ''; }
function __( $t, $d = '' ) { return $t; }
class WP_Error {
    public $code; public $message;
    public function __construct( $c = '', $m = '' ) { $this->code = $c; $this->message = $m; }
}

require_once $root . '/includes/class-sfaf-video.php';

$php = array();
foreach ( $cases as $url ) {
    $php[] = SFAF_Video::embed_url( $url );
}

/* ---------------------------------------------------------------------------
 * The JS half. ucVideoEmbed() is SLICED OUT OF portal.js between the marker
 * comment and the end of the function, so this runs the shipped code rather
 * than a copy of it. A copy pasted in here would agree with itself forever.
 * ------------------------------------------------------------------------ */
$js_src = file_get_contents( $root . '/public/js/portal.js' );

$from = strpos( $js_src, 'var UC_YT_ID = ' );
if ( false === $from ) {
    echo "FAIL: UC_YT_ID could not be found in portal.js, so the JS half was never run.\n";
    exit( 1 );
}
$end = strpos( $js_src, 'function initVideoPreview()', $from );
if ( false === $end ) {
    echo "FAIL: initVideoPreview() could not be found, so the slice has no end.\n";
    exit( 1 );
}
$js_fn = substr( $js_src, $from, $end - $from );

$runner = $js_fn . "\n"
    . 'var cases = ' . json_encode( $cases ) . ";\n"
    . "var out = cases.map(function (u) { return ucVideoEmbed(u); });\n"
    . "console.log(JSON.stringify(out));\n";

$tmp = sys_get_temp_dir() . '/sfaf-video-crosscheck-' . getmypid() . '.js';
file_put_contents( $tmp, $runner );

$out  = array();
$code = 0;
exec( 'node ' . escapeshellarg( $tmp ) . ' 2>&1', $out, $code );
@unlink( $tmp );

if ( 0 !== $code ) {
    echo "FAIL: node could not run the JS half.\n";
    echo implode( "\n", $out ) . "\n";
    exit( 1 );
}
$js = json_decode( end( $out ), true );
if ( ! is_array( $js ) || count( $js ) !== count( $cases ) ) {
    echo "FAIL: the JS half did not answer for every address.\n";
    exit( 1 );
}

/* ---------------------------------------------------------------------------
 * Compare.
 * ------------------------------------------------------------------------ */
$bad = array();
foreach ( $cases as $i => $url ) {
    if ( $php[ $i ] !== $js[ $i ] ) {
        $bad[] = sprintf(
            "  %s\n      PHP: %s\n      JS:  %s",
            '' === trim( $url ) ? '(empty)' : $url,
            '' === $php[ $i ] ? '(refused)' : $php[ $i ],
            '' === $js[ $i ] ? '(refused)' : $js[ $i ]
        );
    }
}

/* ---------------------------------------------------------------------------
 * THE SELF-TEST. A comparison that cannot see a difference reports none, and a
 * list with no refusals in it would pass with either parser accepting anything.
 * ------------------------------------------------------------------------ */
if ( $self_test ) {
    $probe = array();

    $accepted = count( array_filter( $php ) );
    $refused  = count( $php ) - $accepted;
    if ( $accepted < 10 ) { $probe[] = 'fewer than ten addresses are accepted, so the list barely exercises either parser'; }
    if ( $refused < 10 )  { $probe[] = 'fewer than ten addresses are refused, so a parser that accepted anything would pass'; }

    /* And the comparison really does fail on a difference. */
    $fake_php = $php;
    $fake_js  = $js;
    $fake_js[0] = 'https://example.invalid/changed';
    $seen = false;
    foreach ( $cases as $i => $u ) {
        if ( $fake_php[ $i ] !== $fake_js[ $i ] ) { $seen = true; break; }
    }
    if ( ! $seen ) { $probe[] = 'the comparison does not notice a changed answer'; }

    if ( $probe ) {
        echo "SELF-TEST FAILED\n";
        foreach ( $probe as $p ) { echo '  . ' . $p . "\n"; }
        exit( 1 );
    }
    echo "self-test passed: $accepted accepted, $refused refused, and a difference is noticed.\n";
}

if ( $bad ) {
    echo 'THE TWO PARSERS DISAGREE ON ' . count( $bad ) . " of " . count( $cases ) . " addresses:\n";
    echo implode( "\n", $bad ) . "\n\n";
    echo "SFAF_Video::parse() decides what plays on the event page and ucVideoEmbed()\n";
    echo "decides what the editor previews. A difference is a preview that lies.\n";
    exit( 1 );
}

printf(
    "%d addresses, %d accepted and %d refused: SFAF_Video::parse() and ucVideoEmbed()\nagree on every one.\n",
    count( $cases ),
    count( array_filter( $php ) ),
    count( $cases ) - count( array_filter( $php ) )
);
exit( 0 );
