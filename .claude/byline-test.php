<?php
/**
 * NO AUTHOR ON A PUBLIC EVENT SURFACE, RUN (3.106.1).
 *
 *     php .claude/byline-test.php
 *
 * The real SFAF_Bylines with WordPress stubbed to the handful of answers it
 * asks for. byline-live.php measures the captured pages in Chrome; this holds
 * the cases a captured page does not contain:
 *
 *   a post that is not an event keeps its byline (a date archive or a search
 *   lists both); the rule above an event's byline stays; a theme that renames
 *   its byline classes still loses the author link, name and login; a link in
 *   an event's own words to another site's /author/ page is kept; a nested
 *   <div> never leaves a stray closing tag; a calendar user's author archive,
 *   by name or by ?author=, redirects to the calendar home and anybody else's
 *   does not; an event's oEmbed answer has no author; an event's author in a
 *   feed or template is SFAF; the public REST users list and a fetch by id
 *   leave out calendar users to a visitor, and not to somebody signed in.
 */

define( 'ABSPATH', __DIR__ . '/' );
class WP_User { public $ID; public function __construct( $id ) { $this->ID = $id; } }
class WP_Error { public $code; public function __construct( $c = '' ) { $this->code = $c; } }
class BL_Redirect extends Exception {}

$GLOBALS['bl'] = array();
function bl_reset( $s = array() ) {
    $GLOBALS['bl'] = array_merge( array( 'is_author' => false, 'queried' => null, 'author_var' => 0, 'home' => '', 'logged_in' => false,
        'post' => null, 'admin' => false ), $s );
}
function home_url( $p = '' ) { return 'https://resources.sfaf.org' . $p; }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
function is_author() { return $GLOBALS['bl']['is_author']; }
function get_queried_object() { return $GLOBALS['bl']['queried']; }
function get_query_var( $k ) { return 'author' === $k ? $GLOBALS['bl']['author_var'] : ''; }
function sfaf_calendar_home_url() { return $GLOBALS['bl']['home']; }
function get_post_type_archive_link( $t ) { return 'https://resources.sfaf.org/collections/events/'; }
function wp_redirect( $to, $code = 302 ) { throw new BL_Redirect( $code . ' ' . $to ); }
function is_admin() { return $GLOBALS['bl']['admin']; }
function is_user_logged_in() { return $GLOBALS['bl']['logged_in']; }
function get_post( $p = null ) { return $GLOBALS['bl']['post']; }
function get_post_type( $p ) { return is_object( $p ) ? $p->post_type : ''; }
function get_bloginfo( $k ) { return 'San Francisco AIDS Foundation'; }
function get_users( $a ) { return isset( $a['meta_key'] ) ? array( 55, 54 ) : array( 36 ); }
/* 55 and 36 hold a calendar role (36 as a site administrator); 54 has a stale meta value; 6 has none. */
class SFAF_Portal { public static function get_role( $id ) { return in_array( (int) $id, array( 55, 36 ), true ) ? ( 36 === (int) $id ? 'admin' : 'contributor' ) : ''; } }
class BL_Request {
    private $m; private $r;
    public function __construct( $m, $r ) { $this->m = $m; $this->r = $r; }
    public function get_method() { return $this->m; }
    public function get_route() { return $this->r; }
}

require dirname( __DIR__ ) . '/includes/class-sfaf-bylines.php';

$fails = array();
function bt( $label, $got, $want ) {
    global $fails;
    if ( $got !== $want ) { $fails[] = $label . ': got ' . var_export( $got, true ) . ', wanted ' . var_export( $want, true ); }
}

/* ---- The byline. -------------------------------------------------------- */
$byline = '<div class="sfaf-separator"><hr /></div><div class="sfaf-entry-athors sfaf-article-footer"><ul class="sfaf-authors-list"><li class="sfaf-author"><span class="sfaf-author__meta"><span class="sfaf-author__name"><a href="/collections/author/pexample@sfaf.org">Pat Example</a></span></span></li></ul>        </div>';
$event  = '<article id="post-1" class="post-1 uc_event type-uc_event status-publish hentry"><div class="entry-content"><h2>Support group</h2>' . $byline . '</div></article>';
$post   = '<article id="post-2" class="post-2 post type-post status-publish hentry"><div class="entry-content"><h2>A news story</h2>' . $byline . '</div></article>';
$page   = '<html><body>' . $event . $post . '</body></html>';
$out    = SFAF_Bylines::strip( $page );

bt( 'PLANT C.4: the event loses its byline', false !== strpos( $out, 'post-1' ) && 1 === substr_count( $out, 'Pat Example' ), true );
preg_match( '#<article id="post-1".*?</article>#s', $out, $m1 );
bt( 'the event\'s article holds no name, login or author list', isset( $m1[0] ) && false === stripos( $m1[0], 'pexample' ) && false === strpos( $m1[0], 'Pat Example' ) && false === strpos( $m1[0], 'sfaf-authors-list' ), true );
bt( 'the rule above it stays, as the line between events', isset( $m1[0] ) && false !== strpos( $m1[0], '<div class="sfaf-separator"><hr /></div>' ), true );
bt( 'a news story in the same listing keeps its byline', false !== strpos( $out, '<article id="post-2"' ) && false !== strpos( substr( $out, strpos( $out, 'post-2' ) ), 'Pat Example' ), true );
bt( 'tags still balance in the event', isset( $m1[0] ) && substr_count( $m1[0], '<div' ) === substr_count( $m1[0], '</div>' ), true );
bt( 'a page with no event is returned untouched', SFAF_Bylines::strip( '<html>' . $post . '</html>' ), '<html>' . $post . '</html>' );

/* The theme renames its classes: the author link is still taken. */
$renamed = '<article class="uc_event type-uc_event"><div class="entry-content"><p>Words.</p><footer class="sfaf-byline"><p class="by">By <a href="https://resources.sfaf.org/collections/author/pexample@sfaf.org/">Pat Example</a></p></footer></div></article>';
$r = SFAF_Bylines::strip( $renamed );
bt( 'PLANT C.4: renamed classes, the author link and name still go', false === stripos( $r, 'pexample' ) && false === strpos( $r, 'Pat Example' ), true );
bt( 'and the event\'s own words stay', false !== strpos( $r, '<p>Words.</p>' ), true );

/* A link in the description to somebody else's author page is the event's own words. */
$own = '<article class="type-uc_event"><p>Read <a href="https://www.example.com/author/jdoe/">J. Doe</a>.</p></article>';
bt( 'a link to another site\'s author page is kept', SFAF_Bylines::strip( $own ), $own );

/* A <div> inside the footer: the footer is left for the narrower patterns, never cut in half. */
$nested = '<article class="type-uc_event"><div class="sfaf-entry-athors"><div class="x"><ul class="sfaf-authors-list"><li><a href="/collections/author/pexample@sfaf.org">Pat Example</a></li></ul></div></div></article>';
$n = SFAF_Bylines::strip( $nested );
bt( 'a nested footer loses the name and keeps its tags balanced', false === strpos( $n, 'Pat Example' ) && substr_count( $n, '<div' ) === substr_count( $n, '</div>' ), true );

/* ---- Author archives. --------------------------------------------------- */
function bl_redirect_to() {
    try { SFAF_Bylines::redirect_author_archive(); } catch ( BL_Redirect $e ) { return $e->getMessage(); }
    return '';
}
bl_reset( array( 'is_author' => true, 'queried' => new WP_User( 55 ), 'home' => 'https://www.sfaf.org/calendar/' ) );
bt( 'PLANT C.4: a calendar user\'s archive goes to the calendar home', bl_redirect_to(), '302 https://www.sfaf.org/calendar/' );
bl_reset( array( 'is_author' => true, 'queried' => null, 'author_var' => 55 ) );
bt( '?author=55, with no calendar home set, goes to the events archive', bl_redirect_to(), '302 https://resources.sfaf.org/collections/events/' );
bl_reset( array( 'is_author' => true, 'queried' => new WP_User( 36 ) ) );
bt( 'an administrator holds a calendar role too', 0 === strpos( bl_redirect_to(), '302 ' ), true );
bl_reset( array( 'is_author' => true, 'queried' => new WP_User( 6 ) ) );
bt( 'somebody with no calendar role keeps their archive', bl_redirect_to(), '' );
bl_reset( array( 'is_author' => false, 'queried' => new WP_User( 55 ) ) );
bt( 'nothing happens away from an author archive', bl_redirect_to(), '' );

/* ---- oEmbed, the author in templates and feeds. ------------------------ */
$ev   = (object) array( 'post_type' => 'uc_event' );
$news = (object) array( 'post_type' => 'post' );
$data = array( 'title' => 'Support group', 'author_name' => 'Pat Example', 'author_url' => 'https://resources.sfaf.org/collections/author/pexamplesfaf-org/' );
bt( 'PLANT C.4: an event\'s oEmbed answer has no author', array_keys( SFAF_Bylines::oembed( $data, $ev ) ), array( 'title' ) );
bt( 'a news story\'s keeps it', SFAF_Bylines::oembed( $data, $news ), $data );
bl_reset( array( 'post' => $ev ) );
bt( 'an event\'s author, in a feed or template, is SFAF', SFAF_Bylines::event_author( 'Pat Example' ), 'San Francisco AIDS Foundation' );
bl_reset( array( 'post' => $news ) );
bt( 'a news story\'s author is its author', SFAF_Bylines::event_author( 'Pat Example' ), 'Pat Example' );
bl_reset( array( 'post' => $ev, 'admin' => true ) );
bt( 'in wp-admin an event\'s author is its author', SFAF_Bylines::event_author( 'Pat Example' ), 'Pat Example' );

/* ---- The public REST API. ----------------------------------------------- */
bl_reset();
$q = SFAF_Bylines::rest_user_query( array( 'exclude' => array( 3 ) ), null );
sort( $q['exclude'] );
bt( 'PLANT C.4: the users list leaves out calendar users to a visitor', $q['exclude'], array( 3, 36, 55 ) );
bl_reset( array( 'logged_in' => true ) );
bt( 'and lists them to somebody signed in', SFAF_Bylines::rest_user_query( array(), null ), array() );
bl_reset();
bt( 'a visitor fetching a calendar user by id is told there is none', get_class( (object) SFAF_Bylines::rest_single_user( null, null, new BL_Request( 'GET', '/wp/v2/users/55' ) ) ), 'WP_Error' );
bt( 'anybody else is fetched as before', SFAF_Bylines::rest_single_user( null, null, new BL_Request( 'GET', '/wp/v2/users/6' ) ), null );
bt( 'another route is untouched', SFAF_Bylines::rest_single_user( null, null, new BL_Request( 'GET', '/wp/v2/uc_event/55' ) ), null );
bl_reset( array( 'logged_in' => true ) );
bt( 'somebody signed in fetches a calendar user as before', SFAF_Bylines::rest_single_user( null, null, new BL_Request( 'GET', '/wp/v2/users/55' ) ), null );

if ( $fails ) {
    echo 'BYLINES: ' . count( $fails ) . " FAILURE(S)\n  - " . implode( "\n  - ", $fails ) . "\n";
    exit( 1 );
}
echo "bylines: an event loses its byline and keeps its rule, other posts keep theirs; renamed classes still lose the author link; calendar users' archives redirect; oEmbed, feeds and the public REST users list name nobody.\n";
