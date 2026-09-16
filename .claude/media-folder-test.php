<?php
/**
 * THE PICKER OFFERS THE CALENDAR FOLDER, AND NOTHING ELSE OFFERS LESS.
 *
 *     php .claude/media-folder-test.php
 *
 * TWO THINGS CAN GO WRONG HERE AND THEY FAIL IN OPPOSITE DIRECTIONS.
 *
 * Too wide, and the picker shows the whole media library again: the feature
 * quietly stops existing and nobody notices, because a picker full of images
 * looks like a working picker. Too wide is also what a broken anchor gives,
 * matching `photos/calendar/x.jpg` and `2026/08/calendar-flyer/x.jpg`.
 *
 * TOO NARROW IS THE WORSE ONE. This filter is on a global WordPress hook, so a
 * missing gate would narrow the WordPress media library itself, on every screen
 * of the site, for every plugin and every editor. That is this plugin hiding
 * four hundred images from somebody writing a page that has nothing to do with
 * events, and they would have no reason to suspect the calendar.
 *
 * So the gate is asserted first and hardest: with no flag on the request,
 * nothing changes at all, and the arguments come back byte for byte.
 */

define( 'ABSPATH', __DIR__ );

$root  = dirname( __DIR__ );
$fails = array();

/* --- WordPress, in miniature. --------------------------------------------- */
$GLOBALS['pmeta'] = array();
function get_post_meta( $id, $k, $single = false ) {
    $key = (int) $id . ':' . $k;
    return array_key_exists( $key, $GLOBALS['pmeta'] ) ? $GLOBALS['pmeta'][ $key ] : '';
}
function add_filter( $h, $cb, $p = 10, $a = 1 ) { $GLOBALS['hooks'][] = array( $h, $cb ); return true; }
/* ADDED IN 3.87.0. register() now also hooks add_attachment, to tag a picture
   uploaded from the event editor with that event's series. Recorded the same
   way as the filters so the assertions below can see it was hooked. */
function add_action( $h, $cb, $p = 10, $a = 1 ) { $GLOBALS['hooks'][] = array( $h, $cb ); return true; }
function get_posts( $args ) { return isset( $GLOBALS['attachments'] ) ? $GLOBALS['attachments'] : array(); }
$GLOBALS['hooks'] = array();

require_once $root . '/includes/class-sfaf-media-folder.php';

function expect( $label, $got, $want ) {
    global $fails;
    if ( $got !== $want ) {
        $fails[] = sprintf( '%s: got %s, expected %s', $label, var_export( $got, true ), var_export( $want, true ) );
    }
}

/* ---------------------------------------------------------------------------
 * 1. THE GATE. No flag on the request, no change to anything.
 * ------------------------------------------------------------------------ */
$_REQUEST = array();
$plain    = array( 'post_type' => 'attachment', 'post_mime_type' => 'image' );
expect( 'an unflagged query is untouched', SFAF_Media_Folder::restrict_query( $plain ), $plain );

$dirs = array(
    'basedir' => '/var/www/uploads', 'baseurl' => 'https://example.org/wp-content/uploads',
    'subdir'  => '/2026/08', 'path' => '/var/www/uploads/2026/08',
    'url'     => 'https://example.org/wp-content/uploads/2026/08',
);
expect( 'an unflagged upload is untouched', SFAF_Media_Folder::upload_to_folder( $dirs ), $dirs );

/* Somebody else's media query, with its own meta_query, must survive whole. */
$_REQUEST = array();
$theirs   = array( 'meta_query' => array( array( 'key' => '_thumbnail_id', 'compare' => 'EXISTS' ) ) );
expect( "another plugin's query is untouched", SFAF_Media_Folder::restrict_query( $theirs ), $theirs );

/* ---------------------------------------------------------------------------
 * 2. FLAGGED, ON BOTH ROUTES. wp.media nests its library arguments under
 *    `query`; an upload posts the flag at the top level.
 * ------------------------------------------------------------------------ */
foreach ( array(
    'top level (an upload)' => array( SFAF_Media_Folder::FLAG => '1' ),
    'nested (a media query)' => array( 'query' => array( SFAF_Media_Folder::FLAG => '1' ) ),
) as $how => $request ) {
    $_REQUEST = $request;
    $out      = SFAF_Media_Folder::restrict_query( array( 'post_type' => 'attachment' ) );

    if ( empty( $out['meta_query'] ) ) {
        $fails[] = "$how: no meta_query was added, so the picker would show everything";
        continue;
    }
    $clause = $out['meta_query'][0];
    expect( "$how: filters on the core path meta", $clause['key'], '_wp_attached_file' );
    expect( "$how: uses REGEXP so it can anchor", $clause['compare'], 'REGEXP' );

    if ( '^' !== substr( $clause['value'], 0, 1 ) ) {
        $fails[] = "$how: the pattern is not anchored at the front, so photos/calendar/x.jpg would match";
    }
    if ( false !== strpos( $clause['value'], '\\/' ) ) {
        $fails[] = "$how: the separator is escaped as \\/ , which MySQL 5.7's regex engine does not define";
    }
}

/* ---------------------------------------------------------------------------
 * 3. THE PATTERN MEANS WHAT THE PHP SIDE MEANS.
 *
 * The REGEXP runs in MySQL and cannot be exercised here, so the same cases are
 * run through the PHP predicate AND through PCRE with the shipped pattern. Two
 * implementations of one rule is exactly the shape that drifts, and the whole
 * point of the anchor is the paths it must NOT match.
 * ------------------------------------------------------------------------ */
$_REQUEST = array( SFAF_Media_Folder::FLAG => '1' );
$pattern  = SFAF_Media_Folder::restrict_query( array() )['meta_query'][0]['value'];

$cases = array(
    'calendar/latino.jpg'             => true,
    'calendar/2026/latino.jpg'        => true,
    'calendar/a-b_c.png'              => true,
    '2026/08/latino.jpg'              => false,
    'photos/calendar/latino.jpg'      => false,
    '2026/08/calendar-flyer/x.jpg'    => false,
    'calendar-flyer/x.jpg'            => false,
    'calendars/x.jpg'                 => false,
    ''                                => false,
);
foreach ( $cases as $path => $want ) {
    /* PHP's own predicate. */
    $php = SFAF_Media_Folder::path_is_inside( (string) $path );
    if ( $php !== $want ) {
        $fails[] = sprintf( 'path_is_inside(%s): got %s, expected %s', var_export( (string) $path, true ), var_export( $php, true ), var_export( $want, true ) );
    }
    /* The shipped pattern, run as a regex. Not MySQL, but it holds the anchor
     * to the same cases, which is the part that decides too-wide from right.
     *
     * DELIMITED WITH #, because the pattern contains a bare forward slash and
     * must: see SFAF_Media_Folder::pattern(). Wrapping it in /.../ here reads
     * the slash as the end of the pattern, which is a fault in this file and
     * not in the code, and it is worth leaving the reason written down. */
    $re = (bool) preg_match( '#' . $pattern . '#', (string) $path );
    if ( $re !== $want ) {
        $fails[] = sprintf( 'the stored pattern against %s: got %s, expected %s', var_export( (string) $path, true ), var_export( $re, true ), var_export( $want, true ) );
    }
}

/* A leading slash is the same folder. _wp_attached_file does not store one, but
 * anything hand-built might. */
expect( 'a leading slash is still the folder', SFAF_Media_Folder::path_is_inside( '/calendar/x.jpg' ), true );

/* ---------------------------------------------------------------------------
 * 4. A FLAGGED UPLOAD LANDS IN THE FOLDER, with path, url and subdir agreeing.
 *
 * All three are set together because WordPress writes _wp_attached_file from
 * subdir and serves the file from url. One left on the old month is a file on
 * disk in one place and recorded in another.
 * ------------------------------------------------------------------------ */
$_REQUEST = array( SFAF_Media_Folder::FLAG => '1' );
$out      = SFAF_Media_Folder::upload_to_folder( $dirs );
expect( 'subdir is the folder', $out['subdir'], '/calendar' );
expect( 'path is under basedir', $out['path'], '/var/www/uploads/calendar' );
expect( 'url is under baseurl',  $out['url'],  'https://example.org/wp-content/uploads/calendar' );
expect( 'basedir is left alone', $out['basedir'], $dirs['basedir'] );

/* A malformed dirs array is handed back rather than turned into '/calendar'
 * hanging off nothing. */
expect( 'no basedir means no change', SFAF_Media_Folder::upload_to_folder( array( 'subdir' => '/x' ) ), array( 'subdir' => '/x' ) );

/* ---------------------------------------------------------------------------
 * 5. holds() READS THE SAME META THE QUERY DOES.
 * ------------------------------------------------------------------------ */
$GLOBALS['pmeta']['41:_wp_attached_file'] = 'calendar/latino.jpg';
$GLOBALS['pmeta']['42:_wp_attached_file'] = '2026/08/latino.jpg';
expect( 'holds() sees a calendar image', SFAF_Media_Folder::holds( 41 ), true );
expect( 'holds() rejects one outside',   SFAF_Media_Folder::holds( 42 ), false );
expect( 'holds() survives no meta',      SFAF_Media_Folder::holds( 999 ), false );

/* ---------------------------------------------------------------------------
 * 6. BOTH HOOKS ARE REGISTERED. Either one missing is half a feature: the
 *    picker filtered but uploads landing elsewhere, or the reverse.
 * ------------------------------------------------------------------------ */
$GLOBALS['hooks'] = array();
SFAF_Media_Folder::register();
$hooked = array();
foreach ( $GLOBALS['hooks'] as $h ) { $hooked[] = $h[0]; }
foreach ( array( 'ajax_query_attachments_args', 'upload_dir' ) as $need ) {
    if ( ! in_array( $need, $hooked, true ) ) {
        $fails[] = "the $need filter is not registered";
    }
}

/* ---------------------------------------------------------------------------
 * 7. DISPLAY FILTERS ON THE FOLDER, IN ONE PLACE (3.83.0).
 *
 * THIS CHECK IS THE REVERSE OF THE ONE THAT WAS HERE, and the reversal is
 * Mark's decision rather than a loosening. What it used to say was: "the rule is
 * about CHOOSING a new image, never about showing one. An event whose picture
 * predates the folder, or was set through the WordPress editor, must go on
 * rendering exactly as it did."
 *
 * WHAT CHANGED. The 2026-09-03 import carried 270 events' pictures across from
 * The Events Calendar, all outside the folder, all the wrong shape for a 16:9
 * card, none of them reachable from the Images screen. Mark's rule now: a
 * picture that is not in the calendar folder is not used. Enforced at
 * resolution, so nothing is cleared, no file is touched, and the next import
 * cannot undo it.
 *
 * SO WHAT IS ASSERTED NOW IS WHERE THE RULE LIVES. One function in the template
 * functions asks the folder question and every surface reads it; the embed and
 * the shortcodes must still not ask it themselves, because a second place
 * deciding is how two surfaces come to disagree. That the surfaces all go
 * through it is .claude/image-folder-rule-test.php's job, and it plants the
 * fault to prove it.
 * ------------------------------------------------------------------------ */
foreach ( array( 'includes/class-sfaf-embed.php', 'includes/class-sfaf-shortcodes.php' ) as $rel ) {
    $src = file_get_contents( $root . '/' . $rel );
    if ( false !== strpos( $src, 'SFAF_Media_Folder' ) ) {
        $fails[] = "$rel asks about the calendar folder itself; the rule lives in sfaf_event_own_image_url() and every surface reads it from there";
    }
}

/* And it IS asked, in the one place that should ask it. A check that only
 * forbids can pass on a file where the rule was deleted. */
$tf = file_get_contents( $root . '/includes/sfaf-template-functions.php' );
if ( false === strpos( $tf, 'function sfaf_event_own_image_url' ) ) {
    $fails[] = 'sfaf_event_own_image_url() is gone, so nothing applies the calendar folder rule at resolution';
} elseif ( false === strpos( $tf, 'SFAF_Media_Folder::holds' ) ) {
    $fails[] = 'the resolution rule no longer asks SFAF_Media_Folder::holds(), so it is deciding the folder question some other way';
}

/* The portal marks the field and passes the flag; the script sends it on both
 * routes. Either half alone does nothing. */
$portal = file_get_contents( $root . '/includes/class-sfaf-portal.php' );
if ( false === strpos( $portal, 'data-uc-media-flag' ) ) {
    $fails[] = 'the image field does not carry the flag attribute, so the script has nothing to send';
}
$js = file_get_contents( $root . '/public/js/portal.js' );
if ( false === strpos( $js, 'library[flag]' ) ) {
    $fails[] = 'portal.js does not put the flag on the media query, so the picker would show the whole library';
}
if ( false === strpos( $js, 'multipart_params' ) ) {
    $fails[] = 'portal.js does not put the flag on the uploader, so an upload would land in the month folder';
}


/* ---------------------------------------------------------------------------
 * THE MEDIA LIBRARY'S OWN CALENDAR FOLDER (3.90.0).
 *
 * Fourteen pictures sit in uploads/calendar/ and the library's Calendar folder
 * shows five, because WP Media Folder files by TAXONOMY and this plugin only
 * ever set the PATH. The taxonomy name could not be verified here: WP Media
 * Folder is commercial, is not on wordpress.org and is not on this machine. So
 * the code DISCOVERS it instead of naming it, and these assertions are about
 * what that discovery may and may not do.
 * ------------------------------------------------------------------------ */
echo "The media library's own folder\n";

$GLOBALS['obj_tax']   = array();   // taxonomies registered for 'attachment'
$GLOBALS['terms_by']  = array();   // tax => array( slug/name => term_id )
$GLOBALS['assigned']  = array();   // "id|tax" => array of term ids
$GLOBALS['appended']  = array();

function get_object_taxonomies( $type, $output = 'names' ) { return $GLOBALS['obj_tax']; }
function get_term_by( $field, $value, $tax = '' ) {
    if ( ! isset( $GLOBALS['terms_by'][ $tax ][ $value ] ) ) { return false; }
    return (object) array( 'term_id' => $GLOBALS['terms_by'][ $tax ][ $value ], 'slug' => $value, 'name' => $value );
}
function wp_set_object_terms( $id, $terms, $tax, $append = false ) {
    $GLOBALS['assigned'][ $id . '|' . $tax ] = array_map( 'intval', (array) $terms );
    $GLOBALS['appended'][ $id . '|' . $tax ] = (bool) $append;
    return true;
}
function apply_filters( $tag, $value ) { return $value; }
function is_wp_error( $t ) { return false; }

/* The shape a site with WP Media Folder is in: its taxonomy carries a term
 * named for the folder, and our own taxonomies are on attachments too. */
/* uc_series CARRIES A MATCHING TERM TOO, deliberately, so the exclusion below is
 * the only thing stopping the write. Without that the assertion passes for the
 * wrong reason: no term, nothing written, guard never exercised. Found by
 * planting the guard's removal and watching the test stay green. */
$GLOBALS['obj_tax']  = array( 'wpmf-category', 'uc_series' );
$GLOBALS['terms_by'] = array(
    'wpmf-category' => array( 'calendar' => 77 ),
    'uc_series'     => array( 'calendar' => 99 ),
);
$_REQUEST = array( SFAF_Media_Folder::FLAG => '1' );

SFAF_Media_Folder::file_into_library_folder( 500 );
expect( 'a caladmin upload is filed into the library folder', $GLOBALS['assigned']['500|wpmf-category'] ?? array(), array( 77 ) );
expect( 'and appended rather than replacing what it was in', $GLOBALS['appended']['500|wpmf-category'] ?? false, true );
expect( 'our own taxonomy is never written here', isset( $GLOBALS['assigned']['500|uc_series'] ), false );

/* NOTHING IS INVENTED. A taxonomy with no matching term is skipped, so a site
 * without that plugin, or with the folder named something else, is untouched. */
$GLOBALS['assigned'] = array();
$GLOBALS['terms_by'] = array( 'wpmf-category' => array( 'photos' => 88 ) );
SFAF_Media_Folder::file_into_library_folder( 501 );
expect( 'no matching term means nothing is written', $GLOBALS['assigned'], array() );

/* AND ONLY OUR OWN UPLOADS. An upload from anywhere else in WordPress must not
 * be filed into the calendar folder because a taxonomy happened to have one. */
$GLOBALS['assigned'] = array();
$GLOBALS['terms_by'] = array( 'wpmf-category' => array( 'calendar' => 77 ) );
$_REQUEST = array();
SFAF_Media_Folder::file_into_library_folder( 502 );
expect( 'an upload without the calendar flag is left alone', $GLOBALS['assigned'], array() );

/* IT IS HOOKED, or none of the above ever runs. */
$hooked_lib = false;
foreach ( $GLOBALS['hooks'] as $h ) {
    if ( 'add_attachment' === $h[0] && is_array( $h[1] ) && 'file_into_library_folder' === $h[1][1] ) { $hooked_lib = true; }
}
expect( 'and it is hooked to add_attachment', $hooked_lib, true );
/* ---------------------------------------------------------------------------
 * Result.
 * ------------------------------------------------------------------------ */
echo "Calendar media folder\n";
echo str_repeat( '=', 72 ) . "\n";
if ( $fails ) {
    foreach ( $fails as $f ) {
        echo "  FAIL  $f\n";
    }
    echo "\n" . count( $fails ) . " failure(s).\n";
    exit( 1 );
}
echo "the picker asks for the calendar folder, and nothing else is narrowed.\n";
