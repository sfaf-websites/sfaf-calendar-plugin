<?php
/**
 * Files sent by people with no account.
 *
 * THIS IS THE RISKIEST THING IN THE PLUGIN, and the 3.43.0 request form said so
 * by refusing to carry it: an upload endpoint reachable with no account is the
 * highest-risk thing such a page can hold. That judgement has not changed. What
 * changed is that Mark weighed it and wants uploads on both forms, so the risk
 * is handled here, in ONE place both forms call, rather than avoided.
 *
 * WHAT A SUBMITTED FILE IS, AND IS NOT.
 * ---------------------------------------------------------------------------
 * It is a WORKING COPY. Mark downloads it, resizes it, uploads the finished
 * version to the calendar folder, gives it alt text, and sets THAT as the
 * event's image when he approves. So a submitted file is never the published
 * image, and nothing points at one permanently: the pending row shows it,
 * approval does not copy it, and the whole submissions folder can be emptied
 * without a published event losing anything.
 *
 * That is why it lands in its own folder. `calendar/` holds approved images and
 * is what the picker offers; `calendar-submissions/` holds raw uploads and is
 * offered nowhere. The two are told apart by the file path, the same way and
 * for the same reasons as SFAF_Media_Folder: core writes `_wp_attached_file`,
 * so the rule survives WP Media Folder being deactivated and can be checked
 * with an FTP client.
 *
 * THE ORDER OF THE CHECKS IS THE DESIGN. Each one runs only on input the
 * previous one has already narrowed, so nothing expensive or credulous happens
 * to a file that was never going to be accepted. In particular NOTHING trusts
 * the browser: not the filename, not the MIME type it claims, not the size it
 * reports. See store(), where the sequence is numbered.
 *
 * @package SFAF_Calendar
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SFAF_Uploads {

    /**
     * The folder, relative to uploads. No leading slash: that is the shape
     * `_wp_attached_file` stores, and the shape path_is_inside() compares.
     *
     * NOT A CHILD OF `calendar/`. A submissions folder inside the approved one
     * would sit inside SFAF_Media_Folder's own anchored prefix, so every raw
     * upload would appear in the caladmin picker, which is exactly what these
     * two folders exist to keep apart.
     */
    const FOLDER = 'calendar-submissions';

    /** 10MB, which is what the Google Form this replaces allows. */
    const MAX_BYTES = 10485760;

    /**
     * A ceiling on pixels, separate from the ceiling on bytes.
     *
     * A HIGHLY COMPRESSED IMAGE IS SMALL ON DISK AND ENORMOUS IN MEMORY. A few
     * hundred kilobytes of PNG can decompress to hundreds of megapixels, and
     * the thing that then runs out of memory is not this check but the
     * thumbnailing afterwards, which is a fatal on a public request. 50
     * megapixels is far above any camera somebody would submit from.
     */
    const MAX_PIXELS = 50000000;

    /**
     * What may be sent: the constant getimagesize() returns, mapped to the MIME
     * type finfo must independently agree on.
     *
     * FOUR FORMATS, AND NO SVG. An SVG is a document. It carries script, it
     * carries external references, and it would be served from our own domain,
     * so accepting one from an anonymous form is accepting stored XSS. It is
     * not in this list and there is no setting that adds it.
     *
     * @return array<int,string>
     */
    public static function allowed_types() {
        return array(
            IMAGETYPE_JPEG => 'image/jpeg',
            IMAGETYPE_PNG  => 'image/png',
            IMAGETYPE_GIF  => 'image/gif',
            IMAGETYPE_WEBP => 'image/webp',
        );
    }

    /** The uploads-relative folder, with its trailing slash. */
    public static function prefix() {
        return self::FOLDER . '/';
    }

    /**
     * Does an uploads-relative path sit inside the submissions folder?
     *
     * Anchored at the front, for the reason SFAF_Media_Folder::path_is_inside()
     * is: an unanchored match would also claim `photos/calendar-submissions/`.
     *
     * @param string $file
     * @return bool
     */
    public static function path_is_inside( $file ) {
        return ( 0 === strpos( ltrim( (string) $file, '/' ), self::prefix() ) );
    }

    /**
     * Is this attachment a submitted file?
     *
     * @param int $attachment_id
     * @return bool
     */
    public static function holds( $attachment_id ) {
        $file = get_post_meta( (int) $attachment_id, '_wp_attached_file', true );
        return self::path_is_inside( (string) $file );
    }

    /**
     * The absolute directory, made if it is not there.
     *
     * TWO GUARD FILES, WRITTEN ONCE. An empty `index.html` so a server with
     * directory listing turned on shows nothing, and an `.htaccess` that turns
     * the PHP engine off for this directory. Neither is load-bearing: the
     * checks in store() are what make the contents safe. These are the second
     * layer, for the case where a later change to this file is wrong.
     *
     * @return string|WP_Error Absolute path, no trailing slash.
     */
    public static function dir() {
        $uploads = wp_upload_dir();
        if ( ! empty( $uploads['error'] ) ) {
            return new WP_Error( 'uc_upload_dir', 'The uploads folder is not writable.' );
        }
        $path = rtrim( $uploads['basedir'], '/\\' ) . '/' . self::FOLDER;

        if ( ! is_dir( $path ) && ! wp_mkdir_p( $path ) ) {
            return new WP_Error( 'uc_upload_dir', 'The submissions folder could not be made.' );
        }

        if ( ! file_exists( $path . '/index.html' ) ) {
            @file_put_contents( $path . '/index.html', '' );
        }
        if ( ! file_exists( $path . '/.htaccess' ) ) {
            $rules  = "# Written by SFAF Calendar. These are raw uploads from public forms.\n";
            $rules .= "php_flag engine off\n";
            $rules .= "AddType text/plain .php .php3 .php4 .php5 .php7 .phtml .phar .cgi .pl .py .sh .html .htm .svg\n";
            @file_put_contents( $path . '/.htaccess', $rules );
        }

        return $path;
    }

    /**
     * Send this request's upload into the submissions folder.
     *
     * Added and removed around the one move, never left attached: it is a
     * global filter, and leaving it on would send every later upload in the
     * same request to the wrong place.
     *
     * @param array $dirs
     * @return array
     */
    public static function upload_to_folder( $dirs ) {
        if ( empty( $dirs['basedir'] ) || empty( $dirs['baseurl'] ) ) {
            return $dirs;
        }
        $dirs['subdir'] = '/' . self::FOLDER;
        $dirs['path']   = $dirs['basedir'] . $dirs['subdir'];
        $dirs['url']    = $dirs['baseurl'] . $dirs['subdir'];
        return $dirs;
    }

    /**
     * Accept one submitted image, or say why not.
     *
     * THE SEQUENCE, AND WHY IT IS THIS SEQUENCE.
     * -----------------------------------------------------------------------
     *  1. IS THERE A FILE AT ALL. No field, or UPLOAD_ERR_NO_FILE, returns 0
     *     with NO error: an image is optional on both forms, and a submission
     *     without one is normal, the way a GFMP import with no picture is.
     *  2. WHAT PHP SAYS WENT WRONG. The error code is read before anything else
     *     is believed, because on INI_SIZE there is no usable file on disk and
     *     every check below would be reading nothing.
     *  3. RATE LIMIT. Before the file is touched. This is what stops somebody
     *     filling the disk, and it has to come before the work rather than
     *     after it, or the work IS the denial of service.
     *  4. IS IT REALLY AN UPLOAD. is_uploaded_file() on the temporary name.
     *     Without it, a crafted request naming a local path as its temporary
     *     file would have the rest of this routine copy that file into the
     *     media library and publish it.
     *  5. THE SIZE CEILING, measured with filesize() on the file that is
     *     actually there. $_FILES['size'] is supplied by the browser and is not
     *     evidence of anything.
     *  6. WHAT IS INSIDE IT. getimagesize() parses the header, and its answer
     *     must be one of the four allowed types. This is the check that makes
     *     the name and the claimed MIME type irrelevant.
     *  7. AND finfo AGREES. A second, independent reading of the same bytes,
     *     compared against the SAME list so the two cannot each pass on a
     *     different answer. Two readers rather than one because a file crafted
     *     to fool one of them is much easier to make than one that fools both.
     *  8. THE DIMENSIONS ARE SANE. Non-zero, and under the pixel ceiling, so a
     *     small file that decompresses enormously is refused HERE rather than
     *     by the memory allocator during thumbnailing.
     *  9. THE SUBMITTED NAME IS DISCARDED. The extension comes from the type
     *     found in step 6 and the base name is generated, so nothing anybody
     *     typed reaches the disk: no traversal, no double extension, no
     *     overwriting something by collision.
     * 10. THE MOVE, with test_form off, because this is not a nonced admin
     *     form, and the mime list narrowed to the same four.
     *     wp_handle_upload() runs wp_check_filetype_and_ext() itself, which is
     *     a third look at the bytes, and it is left in place rather than
     *     bypassed.
     * 11. IT MUST NOT BE EXECUTABLE. chmod with the execute bits cleared
     *     explicitly rather than assumed absent.
     * 12. AND IT MUST BE WHERE WE PUT IT. realpath() containment, checked after
     *     the move rather than inferred from before it, because a filter
     *     belonging to something else can change a destination.
     * 13. ONLY THEN IS IT AN ATTACHMENT, registered with the VERIFIED type
     *     rather than the claimed one.
     *
     * @param string        $field   The $_FILES key.
     * @param callable|null $limiter Returns true when one more upload is
     *                               allowed. Rate limiting lives with the form,
     *                               because the two forms count different
     *                               things.
     * @return array{id:int,error:string} id 0 with error '' means none sent.
     */
    public static function store( $field, $limiter = null ) {
        $none = array( 'id' => 0, 'error' => '' );

        /* 1. Is there a file at all. */
        if ( empty( $_FILES[ $field ] ) || ! is_array( $_FILES[ $field ] ) ) {
            return $none;
        }
        $file = $_FILES[ $field ];
        if ( ! isset( $file['error'] ) || is_array( $file['error'] ) ) {
            return $none;
        }
        $code = (int) $file['error'];
        if ( UPLOAD_ERR_NO_FILE === $code ) {
            return $none;
        }

        /* 2. What PHP says went wrong. */
        if ( UPLOAD_ERR_OK !== $code ) {
            if ( UPLOAD_ERR_INI_SIZE === $code || UPLOAD_ERR_FORM_SIZE === $code ) {
                return array( 'id' => 0, 'error' => self::too_big() );
            }
            return array( 'id' => 0, 'error' => 'That image did not finish uploading. Try it again.' );
        }

        /* 3. Rate limit, before the file is touched. */
        if ( null !== $limiter && is_callable( $limiter ) && ! call_user_func( $limiter ) ) {
            return array( 'id' => 0, 'error' => 'That is several uploads in a short time. Give it a few minutes, then try again.' );
        }

        /* 4. Is it really an upload. */
        $tmp = ( isset( $file['tmp_name'] ) && is_string( $file['tmp_name'] ) ) ? $file['tmp_name'] : '';
        if ( '' === $tmp || ! is_uploaded_file( $tmp ) ) {
            return array( 'id' => 0, 'error' => 'That image did not arrive. Try it again.' );
        }

        /* 5. The size ceiling, from the file rather than from the browser. */
        $bytes = @filesize( $tmp );
        if ( false === $bytes || $bytes <= 0 ) {
            return array( 'id' => 0, 'error' => 'That image arrived empty. Try it again.' );
        }
        if ( $bytes > self::MAX_BYTES ) {
            return array( 'id' => 0, 'error' => self::too_big() );
        }

        /* 6. What is inside it. */
        $info    = @getimagesize( $tmp );
        $allowed = self::allowed_types();
        if ( ! is_array( $info ) || empty( $info[2] ) || ! isset( $allowed[ (int) $info[2] ] ) ) {
            return array( 'id' => 0, 'error' => self::wrong_kind() );
        }
        $type = (int) $info[2];
        $mime = $allowed[ $type ];

        /* 7. And finfo agrees, reading the bytes for itself. */
        $sniffed = self::sniff( $tmp );
        if ( '' === $sniffed || $sniffed !== $mime ) {
            return array( 'id' => 0, 'error' => self::wrong_kind() );
        }

        /* 8. The dimensions are sane. */
        $w = isset( $info[0] ) ? (int) $info[0] : 0;
        $h = isset( $info[1] ) ? (int) $info[1] : 0;
        if ( $w < 1 || $h < 1 ) {
            return array( 'id' => 0, 'error' => self::wrong_kind() );
        }
        if ( ( $w * $h ) > self::MAX_PIXELS ) {
            return array( 'id' => 0, 'error' => 'That image is too many pixels. Save it at a smaller size and send it again.' );
        }

        $dir = self::dir();
        if ( is_wp_error( $dir ) ) {
            return array( 'id' => 0, 'error' => 'Images cannot be saved just now. Send this without one and say so.' );
        }

        /* 9. The submitted name is discarded entirely. */
        $ext = ltrim( (string) image_type_to_extension( $type, true ), '.' );
        if ( '' === $ext ) {
            return array( 'id' => 0, 'error' => self::wrong_kind() );
        }
        $file['name'] = 'submission-' . gmdate( 'Ymd-His' ) . '-' . bin2hex( random_bytes( 6 ) ) . '.' . $ext;

        /* 10. The move. */
        if ( ! function_exists( 'wp_handle_upload' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        add_filter( 'upload_dir', array( __CLASS__, 'upload_to_folder' ) );
        $moved = wp_handle_upload( $file, array(
            'test_form' => false,
            'mimes'     => array(
                'jpg|jpeg|jpe' => 'image/jpeg',
                'png'          => 'image/png',
                'gif'          => 'image/gif',
                'webp'         => 'image/webp',
            ),
        ) );
        remove_filter( 'upload_dir', array( __CLASS__, 'upload_to_folder' ) );

        if ( ! is_array( $moved ) || ! empty( $moved['error'] ) || empty( $moved['file'] ) ) {
            return array( 'id' => 0, 'error' => self::wrong_kind() );
        }

        /* 11. It must not be executable. */
        $mode = defined( 'FS_CHMOD_FILE' ) ? ( FS_CHMOD_FILE & ~0111 ) : 0644;
        @chmod( $moved['file'], $mode );

        /* 12. And it must be where we put it. */
        $real_dir  = realpath( $dir );
        $real_file = realpath( $moved['file'] );
        if ( ! $real_dir || ! $real_file || 0 !== strpos( $real_file, $real_dir . DIRECTORY_SEPARATOR ) ) {
            @unlink( $moved['file'] );
            return array( 'id' => 0, 'error' => self::wrong_kind() );
        }

        /* 13. Only now is it an attachment. */
        $attachment_id = wp_insert_attachment( array(
            'post_mime_type' => $mime,
            'post_title'     => 'Submitted image',
            'post_content'   => '',
            'post_status'    => 'inherit',
            /*
             * NOT ATTRIBUTED TO ANYBODY. Nobody was logged in, and putting a
             * user id here would put a name against a file they did not send.
             * SFAF_Request::create_event() leaves post_author at 0 for the same
             * reason.
             */
            'post_author'    => 0,
        ), $moved['file'], 0, true );

        if ( is_wp_error( $attachment_id ) || ! $attachment_id ) {
            @unlink( $moved['file'] );
            return array( 'id' => 0, 'error' => 'That image could not be saved. Send this without one and say so.' );
        }
        $attachment_id = (int) $attachment_id;

        if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }
        wp_update_attachment_metadata(
            $attachment_id,
            wp_generate_attachment_metadata( $attachment_id, $moved['file'] )
        );

        return array( 'id' => $attachment_id, 'error' => '' );
    }

    /**
     * The MIME type according to a reader that is not getimagesize().
     *
     * finfo IS NOT GUARANTEED. It is a compiled extension, like mbstring, and
     * hosts turn up without it. Where it is missing, this returns the type
     * getimagesize() already found rather than refusing every upload: one
     * reader is what a simpler version of this would have had, and losing the
     * second is not a reason to lose the feature. Where it is present, the two
     * must agree.
     *
     * @param string $path
     * @return string '' when nothing could read it.
     */
    private static function sniff( $path ) {
        if ( ! function_exists( 'finfo_open' ) ) {
            $info    = @getimagesize( $path );
            $allowed = self::allowed_types();
            return ( is_array( $info ) && ! empty( $info[2] ) && isset( $allowed[ (int) $info[2] ] ) )
                ? $allowed[ (int) $info[2] ]
                : '';
        }
        $finfo = @finfo_open( FILEINFO_MIME_TYPE );
        if ( ! $finfo ) {
            return '';
        }
        $mime = @finfo_file( $finfo, $path );
        finfo_close( $finfo );
        return is_string( $mime ) ? strtolower( trim( $mime ) ) : '';
    }

    /** One sentence, used by two refusals, so they cannot drift apart. */
    private static function too_big() {
        return 'That image is bigger than ' . (int) round( self::MAX_BYTES / 1048576 )
            . 'MB. Save it smaller and send it again.';
    }

    private static function wrong_kind() {
        return 'That file is not an image we can use. Send a JPEG, PNG, GIF or WebP.';
    }

    /**
     * The submitted file, at a size worth looking at, for the pending row.
     *
     * RETURNS '' RATHER THAN A BROKEN IMAGE. An emptied submissions folder is
     * the expected end state rather than a fault, so an attachment that has
     * gone leaves the row with no thumbnail and nothing else changes.
     *
     * @param int    $attachment_id
     * @param string $size
     * @return string URL or ''.
     */
    public static function url( $attachment_id, $size = 'medium' ) {
        $attachment_id = (int) $attachment_id;
        if ( ! $attachment_id || 'attachment' !== get_post_type( $attachment_id ) ) {
            return '';
        }
        $src = wp_get_attachment_image_url( $attachment_id, $size );
        return $src ? $src : '';
    }
}
