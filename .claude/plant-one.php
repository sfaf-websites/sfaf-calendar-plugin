<?php
/* Apply one planted fault by name. Exits 1 and says so if the text it expects
 * is not there, so a plant can never silently fail to apply. */
$which = isset( $argv[1] ) ? $argv[1] : '';
$P = 'includes/class-sfaf-portal.php';
$C = 'public/css/portal.css';

$edits = array(
    /* The 3.36.0-3.40.0 shape: the cancel card rendered inside the event form. */
    'nested-form' => array( $P,
        '<?php $this->render_access_card( $user, $event_id ); ?>',
        '<?php $this->render_cancel_card( $user, $event_id ); ?>' . "\n" .
        '                <?php $this->render_access_card( $user, $event_id ); ?>' ),

    /* The fallback that unpublished a published event on a save naming no mode. */
    'draft-fallback' => array( $P,
        "sanitize_key( \$_POST['save_mode'] ) : 'keep'",
        "sanitize_key( \$_POST['save_mode'] ) : 'draft'" ),

    /* The save stops carrying the answered scope, so the editor asks again. */
    'drop-scope' => array( $P,
        "'edit_scope' => \$result['scope'],",
        '' ),

    /* A size and weight pair that is not a step on the ladder. */
    'off-ladder' => array( $C,
        '',   /* appended, see below */
        '' ),

    /* The inline organizer creation field returns to the editor. */
    'organizer-field' => array( $P,
        '<?php
                break;',
        '<input type="text" name="organizer_new" value="" />
                <?php
                break;' ),

    /* The gate accepts anything truthy again, which is what a ticked checkbox
     * amounted to: mail on any post that carries the key at all. */
    'loose-gate' => array( 'includes/sfaf-notify-consent.php',
        "return 'send' === sfaf_notify_choice( \$post );",
        "return ! empty( \$post['notify_choice'] );" ),

    /* The hidden field ships pre-answered, which mails everybody on every save
     * exactly as the ticked box did. */
    'preanswered' => array( $P,
        '<input type="hidden" name="notify_choice" value="" data-uc-notify-choice />',
        '<input type="hidden" name="notify_choice" value="send" data-uc-notify-choice />' ),

    /* The ticked checkbox itself comes back. */
    'checkbox-back' => array( $P,
        '<input type="hidden" name="notify_choice" value="" data-uc-notify-choice />',
        '<input type="checkbox" name="notify_registrants" value="1" checked />' ),

    /* The dialog is never armed, so the question is never asked. */
    'unarmed' => array( 'public/js/portal.js',
        "run('notifyConsent', initNotifyConsent);",
        '' ),

    /* The browser's date formatter drifts from the plugin's by one month, so
     * the dialog stays quiet about a date that really moved. */
    'formatter-drift' => array( 'public/js/portal.js',
        'return AP_DAYS[dow] + \', \' + AP_MONTHS[mo - 1] + \' \' + d + \', \' + y;',
        'return AP_DAYS[dow] + \', \' + AP_MONTHS[mo % 12] + \' \' + d + \', \' + y;' ),

    /* The media filter loses its gate, so it narrows the WordPress media
     * library on every screen of the site as well. */
    'ungated-media' => array( 'includes/class-sfaf-media-folder.php',
        "        if ( ! self::asked_for() ) {\n            return \$args;\n        }\n",
        '' ),

    /* The anchor goes, so photos/calendar/x.jpg counts as a calendar image. */
    'unanchored' => array( 'includes/class-sfaf-media-folder.php',
        "return '^' . preg_quote( self::prefix() );",
        "return preg_quote( self::prefix() );" ),

    /* The upload half goes, so uploads land in the month directory and are
     * invisible to the picker that made them. */
    'upload-elsewhere' => array( 'includes/class-sfaf-media-folder.php',
        "add_filter( 'upload_dir', array( __CLASS__, 'upload_to_folder' ) );",
        '' ),

    /* Display starts asking about the folder, which is how an event with an
     * older picture loses it. */
    'display-filters' => array( 'includes/sfaf-template-functions.php',
        'function sfaf_event_location( $post_id ) {',
        "function sfaf_event_location( \$post_id ) {\n    \$unused = SFAF_Media_Folder::FOLDER;" ),

    /* The domain check is loosened to "ends with", so notsfaf.org gets a link
     * and the form is open to anybody who can register that name. */
    'loose-domain' => array( 'includes/class-sfaf-request.php',
        "return ( \$domain === self::DOMAIN || self::ends_with( \$domain, '.' . self::DOMAIN ) );",
        "return self::ends_with( \$domain, self::DOMAIN );" ),

    /* The image check drops the folder half, so any attachment id in the
     * library can be attached to a public event. */
    'any-attachment' => array( 'includes/class-sfaf-request.php',
        "                && SFAF_Media_Folder::holds( \$id ) ) {",
        '                ) {' ),

    /* The date is taken as posted, so 2026-02-30 rolls into March. */
    'loose-date' => array( 'includes/class-sfaf-request.php',
        "        if ( ! checkdate( (int) \$m[2], (int) \$m[3], (int) \$m[1] ) ) {\n            return '';\n        }\n",
        '' ),

    /* The rate limiter always says yes. */
    'no-rate-limit' => array( 'includes/class-sfaf-request.php',
        "        \$count = (int) get_transient( \$key );\n        if ( \$count >= \$limit ) {\n            return false;\n        }\n",
        "        \$count = (int) get_transient( \$key );\n" ),

    /* The token is stored under itself, so the options table holds a working
     * credential. */
    'token-as-key' => array( 'includes/class-sfaf-request.php',
        "    private static function key_for( \$token ) {\n        return hash( 'sha256', (string) \$token );\n    }",
        "    private static function key_for( \$token ) {\n        return (string) \$token;\n    }" ),

    /* The status comes off the form. */
    'status-from-post' => array( 'includes/class-sfaf-request.php',
        "'post_status'  => 'pending',",
        "'post_status'  => isset( \$_POST['post_status'] ) ? \$_POST['post_status'] : 'pending'," ),

    /* The queue stops marking a request, so it reads as a contributor draft. */
    'unmarked-request' => array( $P,
        '<span class="uc-source-badge uc-badge-request">Staff request</span>',
        '' ),

    /* The series screen goes back to its own copy of the markup, which is the
     * 3.43.1 bug exactly: hooks the binder never finds. */
    'picker-copy' => array( $P,
        "                    <?php \$this->render_image_picker( array(\n                        'uid'         => 'series',",
        "                    <input type=\"hidden\" name=\"series_image_id\" id=\"uc-featured-image-id\" value=\"0\" />\n                    <div class=\"uc-image-preview\" id=\"uc-image-preview\"><img id=\"uc-image-preview-img\" /></div>\n                    <button type=\"button\" class=\"uc-btn uc-choose-image\">Choose Image</button>\n                    <?php \$this->render_image_picker( array(\n                        'uid'         => 'series'," ),

    /* The series picker loses the folder attributes, so that screen would
     * offer the whole media library while the editor offers the folder. */
    'picker-unfiltered' => array( $P,
        '<div class="uc-field uc-image-field"<?php echo $this->image_picker_atts( SFAF_Media_Folder::has_any() ); ?>>',
        '<div class="uc-field uc-image-field">' ),

    /* A screen stops loading the media library. */
    'no-media-enqueue' => array( $P,
        "        \$this->load_media  = true;\n        \$this->load_editor = true;\n        wp_enqueue_media();\n\n        \$this->chrome_open( \$user, 'series' );",
        "        \$this->chrome_open( \$user, 'series' );" ),

    /* The renderer drops a hook the binder needs, so every picker dies. */
    'picker-hook-gone' => array( $P,
        '               data-uc-image-id value="<?php echo (int) $a[\'id_value\']; ?>" />',
        '               value="<?php echo (int) $a[\'id_value\']; ?>" />' ),

    /* A second caller of wp_editor(), which is a second control with its own
     * rules. This is the 3.43.1 duplication in a worse place. */
    'second-editor' => array( 'includes/class-sfaf-portal.php',
        "    private function description_editor( \$ctx, \$state ) {",
        "    private function description_editor( \$ctx, \$state ) {\n        if ( false ) { wp_editor( '', 'x', array( 'tinymce' => array( 'toolbar1' => 'bold,forecolor' ) ) ); }" ),

    /* The value contexts go back to joining paragraphs. */
    'joins-paragraphs' => array( 'includes/class-sfaf-seo.php',
        "return wp_trim_words( sfaf_flatten_html( \$source ), 40 );",
        "return wp_trim_words( \$source, 40 );" ),

    /* An FAQ answer is stripped on save again, so formatting is lost. */
    'faq-stripped' => array( 'includes/class-sfaf-faq-sets.php',
        "\$a = SFAF_Rich_Text::sanitize( isset( \$row['answer'] ) ? \$row['answer'] : '' );",
        "\$a = sanitize_textarea_field( isset( \$row['answer'] ) ? \$row['answer'] : '' );" ),

    /* An answer is escaped on display, so a formatted one shows its tags. */
    'faq-escaped' => array( 'includes/sfaf-template-functions.php',
        "<div class=\"uc-faq-a\"><?php echo SFAF_Rich_Text::display( \$f['answer'] ); ?></div>",
        "<div class=\"uc-faq-a\"><?php echo wpautop( esc_html( \$f['answer'] ) ); ?></div>" ),

    /* The toolbar grows a colour picker. */
    'toolbar-colour' => array( 'includes/class-sfaf-rich-text.php',
        "'toolbar1'      => 'formatselect,bold,italic,bullist,numlist,link,unlink,undo,redo',",
        "'toolbar1'      => 'formatselect,bold,italic,forecolor,bullist,numlist,link,unlink,undo,redo'," ),

    /* The heading competes with the page's own structure. */
    'heading-h2' => array( 'includes/class-sfaf-rich-text.php',
        "'block_formats' => 'Paragraph=p;Heading=h3',",
        "'block_formats' => 'Paragraph=p;Heading=h2'," ),
    /* THE 3.44.0 FIVE HUNDRED, exactly as it shipped: a screen declaring the
     * media flag to get its scripts printed, having never enqueued media, so
     * foot() reaches wp_print_media_templates() with no media stack. */
    'faqsets-500' => array( 'includes/class-sfaf-portal.php',
        "        \$this->load_editor = true;\n        SFAF_Rich_Text::enqueue();\n\n        \$this->chrome_open( \$user, 'faq-sets' );",
        "        \$this->load_media = true;\n        SFAF_Rich_Text::enqueue();\n\n        \$this->chrome_open( \$user, 'faq-sets' );" ),

    /* The media templates go back to riding whichever flag is set. */
    'media-ungated' => array( 'includes/class-sfaf-portal.php',
        "        if ( \$this->load_media ) {\n            wp_print_media_templates();\n        }",
        "        if ( \$this->load_media || \$this->load_editor ) {\n            wp_print_media_templates();\n        }" ),
    /* The floor comes off, so the route serves a past month again. */
    'no-month-floor' => array( 'includes/class-sfaf-shortcodes.php',
        "                return ( \$raw < \$floor ) ? \$floor : \$raw;",
        "                return \$raw;" ),

    /* The month binding loses its lower bound, which is the fault the outcome
     * test caught before this release shipped. */
    'month-upper-only' => array( 'includes/class-sfaf-shortcodes.php',
        "            \$args['meta_query'][] = array(\n                'key'     => '_uc_event_date',\n                'value'   => \$filters['month'] . '-01',\n                'compare' => '>=',\n                'type'    => 'DATE',\n            );\n",
        '' ),

    /* The sidebar stops being told the month, so the two halves disagree. */
    'sidebar-unbound' => array( 'includes/class-sfaf-shortcodes.php',
        "                        isset( \$args['heading'] ) ? \$args['heading'] : null,\n                        \$month\n                    )",
        "                        isset( \$args['heading'] ) ? \$args['heading'] : null\n                    )" ),

    /* The floor month offers a way back again. */
    'floor-has-prev' => array( 'includes/class-sfaf-shortcodes.php',
        "            \$at_floor = \$this->is_floor_month( \$prefix );",
        "            \$at_floor = false;" ),

    /* The grid draws its own head in the combined mode, so there are two. */
    'two-heads' => array( 'includes/class-sfaf-shortcodes.php',
        "echo \$this->render_month_grid( \$month, \$filters, ! \$combined );",
        "echo \$this->render_month_grid( \$month, \$filters );" ),
    /* The save half that invents an organizer term. */
    'organizer-save' => array( $P,
        "wp_set_object_terms( \$event_id, \$orgs, 'uc_organizer' );",
        "if ( ! empty( \$_POST['organizer_new'] ) ) { \$orgs[] = (int) SFAF_Organizers::save( 0, \$_POST['organizer_new'] ); }\n            wp_set_object_terms( \$event_id, \$orgs, 'uc_organizer' );" ),
);

if ( 'off-ladder' === $which ) {
    file_put_contents( $C, "\n.uc-planted-fault { font-size: 13.5px; font-weight: 550; }\n", FILE_APPEND );
    echo "planted: off-ladder\n";
    exit( 0 );
}

if ( ! isset( $edits[ $which ] ) ) {
    fwrite( STDERR, "unknown plant: $which\n" );
    exit( 2 );
}

list( $file, $find, $replace ) = $edits[ $which ];
$src = file_get_contents( $file );
if ( false === strpos( $src, $find ) ) {
    fwrite( STDERR, "PLANT DID NOT APPLY: text not found for '$which' in $file\n" );
    exit( 1 );
}
/* Replace the FIRST occurrence only, which is what a real edit would be. */
$pos = strpos( $src, $find );
$src = substr( $src, 0, $pos ) . $replace . substr( $src, $pos + strlen( $find ) );
file_put_contents( $file, $src );
echo "planted: $which\n";
