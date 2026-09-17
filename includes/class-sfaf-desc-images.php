<?php
/**
 * PICTURES INSIDE A DESCRIPTION, AND THE THIRD FOLDER THEY LIVE IN.
 *
 * WHAT THIS IS. An "Insert image" button on the caladmin description editor.
 * It opens a chooser that either uploads a file from the person's computer or
 * picks one already uploaded, and both halves draw on one permanent folder of
 * their own.
 *
 * THREE FOLDERS NOW, AND NONE OF THEM OVERLAPS.
 * ---------------------------------------------------------------------------
 *
 *     calendar/                curated, approved, offered by every FEATURED
 *                              picker and by the Images screen
 *     calendar-submissions/    working copies from people with no account,
 *                              offered by no picker at all
 *     calendar-descriptions/   pictures that go INSIDE prose, offered only by
 *                              the Insert image chooser
 *
 * THE NAME IS A SIBLING, NOT A CHILD, AND THAT IS LOAD BEARING. A folder called
 * `calendar/descriptions/` would sit inside SFAF_Media_Folder's anchored
 * `^calendar/` prefix, so every description picture would appear in the
 * featured picker and on the Images screen. That is the exact trap
 * `calendar-submissions/` was named to avoid, recorded in PROJECT.md 1, and it
 * is avoided here the same way: `calendar-descriptions/` does not begin with
 * `calendar/`, so the featured picker's own rule excludes it with no change to
 * that rule at all.
 *
 * THE EXCLUSION IS THEREFORE STRUCTURAL RATHER THAN A LIST. Nothing had to be
 * told about this folder to keep it out of the pickers. What DID have to be
 * written is the opposite direction: this chooser must show only this folder,
 * so library() is anchored on our prefix and nothing else.
 *
 * WHY A SEPARATE FOLDER AT ALL, rather than reusing `calendar/`. A featured
 * picture is 16:9 and is the event's face; a picture inside prose is whatever
 * shape the prose needs, a floor plan, a flyer, a photograph of a poster. Mixed
 * into one folder, every featured picker would offer floor plans and every
 * description chooser would offer event faces, and the person choosing would
 * have to know which was which by looking.
 *
 * THE UPLOAD GOES THROUGH SFAF_Uploads::inspect(), WHICH IS THE ONE GUARD.
 * ---------------------------------------------------------------------------
 * PROJECT.md 1: inspect() was split out of store() in 3.74.0 precisely so that
 * caladmin's own upload gets exactly the same checks and a different
 * destination. This is that caller. Is there a file, did PHP finish it, is it
 * really an upload, is it small enough, do two readers agree it is an image,
 * and is it wide enough: all eight decisions, unchanged, before anything moves.
 *
 * WHAT DIFFERS FROM A SUBMITTED FILE, and only this: there IS a logged-in user,
 * so the attachment is attributed to them and core's media_handle_upload() does
 * the move. A stranger's file is attributed to nobody and lands through
 * SFAF_Uploads::store(); this one belongs to whoever put it in the description.
 *
 * THE MINIMUM WIDTH IS A WARNING HERE TOO, not a refusal. A picture inside
 * prose renders at the width of the description column, which is narrower than
 * a card, so a picture below the featured floor is often perfectly usable. The
 * sentence is returned for the chooser to show.
 *
 * EVERY PICTURE IN A DESCRIPTION CAME THROUGH THE BUTTON.
 * ---------------------------------------------------------------------------
 * keep_only_ours() drops any <img> whose src is not in this folder, on the way
 * in, at save. So a picture pasted out of a Word document, an email or another
 * website is gone before it is stored, and what is left is exactly what the
 * chooser inserted. That is not tidiness: a pasted <img> is usually a hotlink
 * to somebody else's server or a base64 blob measured in megabytes, and both
 * end up served from an event page as though the calendar had chosen them.
 *
 * @package SFAF_Calendar
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SFAF_Desc_Images {

    /**
     * The folder, beside the other two rather than inside either.
     *
     * See the header: a child of `calendar/` would be offered by the featured
     * picker, which is the one thing this split exists to prevent.
     */
    const FOLDER = 'calendar-descriptions';

    /** The field name the chooser uploads under. */
    const FIELD = 'uc_desc_image';

    /** The ajax action the chooser posts to. */
    const ACTION = 'sfaf_desc_image';

    /**
     * The folder as an uploads-relative prefix.
     *
     * @return string
     */
    public static function prefix() {
        return self::FOLDER . '/';
    }

    /**
     * Does an uploads-relative path sit inside the folder?
     *
     * ANCHORED AT THE FRONT, for the reason SFAF_Media_Folder gives: a
     * contains-match would accept `2026/08/calendar-descriptions/x.jpg`, which
     * is somebody else's directory that happens to share a name.
     *
     * @param string $file As _wp_attached_file stores it.
     * @return bool
     */
    public static function path_is_inside( $file ) {
        $file = ltrim( (string) $file, '/' );
        return ( 0 === strpos( $file, self::prefix() ) );
    }

    /**
     * Is this attachment one of ours?
     *
     * @param int $attachment_id
     * @return bool
     */
    public static function holds( $attachment_id ) {
        $file = get_post_meta( (int) $attachment_id, '_wp_attached_file', true );
        return self::path_is_inside( (string) $file );
    }

    /**
     * Send this upload into our folder instead of the year-and-month one.
     *
     * Hooked only for the duration of one upload, like the other two folders
     * do it: `upload_dir` runs for every upload anywhere on the site, so an
     * ungated version would put unrelated media in here.
     *
     * @param array $dirs
     * @return array
     */
    public static function upload_to_folder( $dirs ) {
        $dirs['subdir'] = '/' . self::FOLDER;
        $dirs['path']   = $dirs['basedir'] . $dirs['subdir'];
        $dirs['url']    = $dirs['baseurl'] . $dirs['subdir'];
        return $dirs;
    }

    /**
     * Every picture in the folder, newest first.
     *
     * ANCHORED ON OUR PREFIX AND NOTHING ELSE, which is what makes the chooser
     * unable to offer a calendar picture or a submitted one. The featured
     * picker's exclusion of this folder needs no code because its own prefix
     * does not match ours; this is the direction that does.
     *
     * @param int $limit
     * @return WP_Post[]
     */
    public static function library( $limit = 60 ) {
        $q = new WP_Query( array(
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'post_mime_type' => 'image',
            'posts_per_page' => (int) $limit,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'no_found_rows'  => true,
            'meta_query'     => array(
                array(
                    'key'     => '_wp_attached_file',
                    'value'   => '^' . preg_quote( self::prefix() ),
                    'compare' => 'REGEXP',
                ),
            ),
        ) );
        return $q->posts;
    }

    /**
     * Take an uploaded file into the folder, with the one guard in front of it.
     *
     * @param string $field The $_FILES key.
     * @return array{id:int,error:string,warning:string,url:string}
     */
    public static function store( $field ) {
        $no = function ( $error ) {
            return array( 'id' => 0, 'error' => $error, 'warning' => '', 'url' => '' );
        };

        /*
         * THE EIGHT DECISIONS FIRST, AND THEY ARE NOT OURS. inspect() is the
         * one guard between a file and the disk and it was split out of
         * SFAF_Uploads::store() so that exactly this could reuse it. Nothing
         * about being logged in makes a crafted image safe.
         */
        $seen = SFAF_Uploads::inspect( $field );
        if ( ! $seen['ok'] ) {
            return $no( '' !== $seen['error'] ? $seen['error'] : 'Choose an image to upload.' );
        }

        if ( ! function_exists( 'media_handle_upload' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }

        /*
         * CORE DOES THE MOVE, because there IS a logged-in user here and
         * media_handle_upload() attributes the attachment to them, generates
         * the sizes and inserts the row in one call. SFAF_Uploads::store()
         * hand-rolls those steps because it must attribute a stranger's file to
         * nobody; that reason does not apply here, and repeating the steps
         * would be a second copy of the riskiest routine in the plugin.
         */
        add_filter( 'upload_dir', array( __CLASS__, 'upload_to_folder' ) );
        /*
         * THE ext => mime MAP WRITTEN OUT, not SFAF_Uploads::allowed_types().
         * That one is keyed by IMAGETYPE_* constants, because it is what
         * getimagesize() and finfo are checked against; core wants extensions.
         * The four formats are the same four, and no SVG, for the reason
         * PROJECT.md 1 gives: an SVG is a document and would be served from our
         * own domain.
         */
        $attachment_id = media_handle_upload( $field, 0, array(), array(
            'test_form' => false,
            'mimes'     => array(
                'jpg|jpeg|jpe' => 'image/jpeg',
                'png'          => 'image/png',
                'gif'          => 'image/gif',
                'webp'         => 'image/webp',
            ),
        ) );
        remove_filter( 'upload_dir', array( __CLASS__, 'upload_to_folder' ) );

        if ( is_wp_error( $attachment_id ) || ! $attachment_id ) {
            return $no( 'That image could not be saved. Try it again.' );
        }
        $attachment_id = (int) $attachment_id;

        /*
         * AND IT MUST BE WHERE WE PUT IT. The filter above is the instruction;
         * this is the confirmation, and they are not the same thing. A plugin
         * filtering `upload_dir` at a later priority would put the file
         * somewhere else and nothing would have said so.
         */
        if ( ! self::holds( $attachment_id ) ) {
            wp_delete_attachment( $attachment_id, true );
            return $no( 'That image could not be saved in the right place.' );
        }

        return array(
            'id'      => $attachment_id,
            'error'   => '',
            'warning' => (string) $seen['warning'],
            'url'     => (string) wp_get_attachment_url( $attachment_id ),
        );
    }

    /**
     * Drop every picture in this prose that did not come from our folder.
     *
     * THE PASS EVERY DESCRIPTION GOES THROUGH ON THE WAY IN. A pasted <img> is
     * usually a hotlink to somebody else's server or a base64 blob measured in
     * megabytes, and either one ends up served from an event page as though the
     * calendar had chosen it. There is no way to tell a pasted picture from a
     * chosen one after the fact, so the rule is the source: a picture is kept
     * only if its address is a file in `calendar-descriptions/`.
     *
     * THE WHOLE TAG GOES, NOT THE src. An <img> with no source is a broken
     * image icon in the middle of somebody's prose, which is worse than the
     * paste not having worked.
     *
     * MATCHED ON THE UPLOADS URL, not on the attachment id, because that is
     * what the editor actually writes into the markup and it is what a reader
     * of the stored HTML can check. A resized copy lives beside the original in
     * the same folder, so `-300x200` suffixes are inside the prefix too.
     *
     * @param string $html
     * @return string
     */
    public static function keep_only_ours( $html ) {
        $html = (string) $html;
        if ( '' === $html || false === stripos( $html, '<img' ) ) {
            return $html;
        }

        $uploads = wp_get_upload_dir();
        $base    = isset( $uploads['baseurl'] ) ? (string) $uploads['baseurl'] : '';
        $ours    = ( '' !== $base ) ? ( $base . '/' . self::prefix() ) : '';

        return preg_replace_callback(
            '#<img\b[^>]*>#i',
            function ( $m ) use ( $ours ) {
                if ( '' === $ours ) {
                    // No uploads URL to compare against: keep nothing rather
                    // than keep everything. A pass that fails open is not a pass.
                    return '';
                }
                if ( ! preg_match( '#\ssrc\s*=\s*([\'"])(.*?)\1#i', $m[0], $src ) ) {
                    return '';
                }
                $url = html_entity_decode( $src[2], ENT_QUOTES, 'UTF-8' );
                // Protocol relative and scheme differences are the same file.
                $url   = preg_replace( '#^https?:#i', '', $url );
                $mine  = preg_replace( '#^https?:#i', '', $ours );
                return ( 0 === strpos( $url, $mine ) ) ? $m[0] : '';
            },
            $html
        );
    }

    /**
     * The one pass a description goes through on the way in.
     *
     * wp_kses_post() FIRST, so what keep_only_ours() reads is markup that has
     * already had script, event handlers and everything else taken out of it.
     * Dropping pictures out of raw submitted markup and sanitising afterwards
     * would be matching tags inside a string that may still contain anything.
     *
     * @param string $html
     * @return string
     */
    public static function sanitize_description( $html ) {
        return self::keep_only_ours( wp_kses_post( (string) $html ) );
    }
}
