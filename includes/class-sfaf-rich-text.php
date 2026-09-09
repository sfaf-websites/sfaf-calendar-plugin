<?php
/**
 * The one rich text control, and the one toolbar.
 *
 * THE RULE, RATHER THAN A LIST OF FIELDS.
 * ---------------------------------------------------------------------------
 * **Any field where somebody writes more than a sentence and it is DISPLAYED
 * AS PROSE gets this control.** A field added next year inherits that without
 * anybody deciding again.
 *
 * Two halves, and both are needed. "More than a sentence" rules out labels and
 * one-line notes, where a toolbar is clutter. "Displayed as prose" rules out
 * anything read as a value: a title attribute, an ICS field, a JSON-LD string,
 * a search index. HTML in one of those is not formatting, it is corruption.
 *
 * WHY THIS IS A CLASS AND NOT MARKUP.
 * ---------------------------------------------------------------------------
 * 3.43.1 found the image picker was the same markup written out twice. One copy
 * was updated when the binding changed and the other was not, so a button did
 * nothing on one screen; and by then the two copies had different rules, so the
 * naive fix would have left one filtered to the calendar folder and the other
 * showing the whole library.
 *
 * A rich text control is a worse thing to duplicate, because the copies would
 * differ in what somebody is ALLOWED TO TYPE. One screen with alignment and
 * font colours and another without is a calendar that is branded in some places
 * and not others, and nobody would notice until it was everywhere. So the
 * toolbar is defined once, in settings(), and every editor on every screen is
 * rendered by render(). Nothing else may call wp_editor().
 *
 * THE TOOLBAR, AND THE OMISSIONS ARE THE POINT.
 * ---------------------------------------------------------------------------
 * Bold, italic, a link, two kinds of list, one heading. No font colours, no
 * sizes, no alignment: the brand guide governs colour and type, and a full
 * toolbar is how a calendar ends up with events in purple Comic Sans that
 * nobody can unpick afterwards, because the styling is inline on every
 * paragraph.
 *
 * ONE HEADING, AND IT IS h3. The event page's title is the h1 and its sections
 * are h2, so a heading somebody types has to start below both or it breaks the
 * reading order for anybody navigating by headings. block_formats offers
 * exactly Paragraph and that one level, so a competing level cannot be chosen.
 *
 * IT DEGRADES TO A TEXTAREA. caladmin builds its own document rather than
 * running through wp_head, so TinyMCE is asked to start somewhere it usually is
 * not. If its scripts do not run, wp_editor() leaves a plain textarea holding
 * the same content: somebody sees tags instead of formatting, which is worse
 * than the editor working and much better than losing anything. sanitize() on
 * save means the stored value survives either way, and quicktags is off so the
 * fallback is one control rather than two disagreeing about one field.
 *
 * EXISTING PLAIN TEXT CARRIES OVER AS PARAGRAPHS. Everything stored today is
 * plain text with line breaks. wp_editor() runs the stored value through
 * wpautop() for display, and every display path does the same, so a description
 * written before this reads as the paragraphs it always looked like rather than
 * collapsing into one block. Nothing is migrated and nothing needs to be.
 *
 * @package SFAF_Calendar
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SFAF_Rich_Text {

    /**
     * The toolbar. ONE DEFINITION, and every editor is built from it.
     *
     * THE BUTTONS ARE UNCHANGED since this class was written, and 3.54.0
     * deliberately did not touch them. What it added is the frame's own
     * stylesheet and the grip that resizes it.
     *
     * CONTENT_CSS IS THE ONLY WAY TO REACH INSIDE THE FRAME. TinyMCE draws into
     * an iframe, which inherits nothing from the document around it, so the
     * answer field rendered in the browser default while every surface that
     * later displays that answer rendered it in Merriweather. Neither portal.css
     * nor calendar.css can fix that from outside, and raising specificity on
     * anything out here reaches nothing: the frame has its own document.
     *
     * STATUSBAR IS TRUE FOR ONE REASON, WHICH IS THE GRIP. In TinyMCE 4 the
     * drag handle lives in the status bar and nowhere else, so `resize` does
     * nothing while the bar is hidden, which is why the boxes could not be
     * resized. `elementpath` is off so the bar carries the grip and nothing
     * else; it is not a place to put information.
     *
     * @return array TinyMCE settings for wp_editor().
     */
    public static function settings() {
        return array(
            'toolbar1'      => 'formatselect,bold,italic,bullist,numlist,link,unlink,undo,redo',
            'toolbar2'      => '',
            'toolbar3'      => '',
            'block_formats' => 'Paragraph=p;Heading=h3',
            'menubar'       => false,
            'statusbar'     => true,
            'elementpath'   => false,
            'resize'        => true,
            'min_height'    => 220,
            'content_css'   => SFAF_PLUGIN_URL . 'public/css/editor-content.css?ver=' . SFAF_VERSION,
        );
    }

    /**
     * The same settings, for a control the browser has to build.
     *
     * Repeater rows are cloned from a template after the page has loaded, so
     * their editors cannot be rendered by wp_editor(). They are started by
     * wp.editor.initialize() instead, and it is handed THESE settings rather
     * than a second copy written into the script, because a second copy is the
     * whole thing this class exists to prevent.
     *
     * @return string JSON.
     */
    public static function settings_json() {
        return wp_json_encode(
            array( 'tinymce' => self::settings(), 'quicktags' => false, 'mediaButtons' => false ),
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );
    }

    /**
     * Make the editor available to a screen that builds controls in the browser.
     *
     * wp_editor() enqueues what it needs for the editors it renders itself.
     * A row added afterwards has nothing loaded for it, so any screen with a
     * repeater has to ask for the editor up front.
     */
    public static function enqueue() {
        if ( function_exists( 'wp_enqueue_editor' ) ) {
            wp_enqueue_editor();
        }
    }

    /**
     * Render one editor.
     *
     * @param string $id      Unique DOM id. wp_editor() requires it to be
     *                        lowercase letters, numbers and dashes only.
     * @param string $name    The POST field name.
     * @param string $content Stored value.
     * @param array  $args    'rows', 'locked'.
     */
    public static function render( $id, $name, $content, $args = array() ) {
        $args = array_merge( array( 'rows' => 8, 'locked' => false ), $args );

        /*
         * A LOCKED FIELD IS A TEXTAREA, because a disabled TinyMCE is not a
         * thing: the toolbar stays live and only the fallback carries the
         * attribute. A source-owned description is shown, not edited.
         */
        if ( $args['locked'] ) {
            printf(
                '<textarea rows="%d" disabled>%s</textarea>',
                (int) $args['rows'],
                esc_textarea( self::to_plain( $content ) )
            );
            return;
        }

        wp_editor( (string) $content, $id, array(
            'textarea_name' => $name,
            'textarea_rows' => (int) $args['rows'],
            'media_buttons' => false,
            'teeny'         => true,
            'quicktags'     => false,
            'tinymce'       => self::settings(),
        ) );
    }

    /**
     * A textarea that the browser will turn into an editor.
     *
     * FOR REPEATER ROWS. Both the ones cloned from a template after load and
     * the ones already in the page when it is built, and that second half is a
     * correction to what this docblock used to say (3.72.0).
     *
     * WHAT IT USED TO SAY, AND WHY IT WAS WRONG. "A row that exists when the
     * page is built gets a real wp_editor()." No row ever has. sfaf_faq_row()
     * is the ONE renderer for a stored row and for the <template>, it has been
     * since 3.44.0, and it calls this for both. So the rule written here
     * described an arrangement the code has never had, which is worse than no
     * rule: 3.68.0 spent an investigation reading it as a statement of fact.
     *
     * AND THE RULE IS NOT BEING RESTORED, deliberately. Forking sfaf_faq_row()
     * so a stored row rendered wp_editor() and a cloned row rendered this would
     * put the FAQ repeater back to two copies of one control, which is the
     * exact shape of the fault that renderer exists to prevent and which this
     * project has paid for more than once. It would also fix nothing on its own:
     * the description beside these answers IS a real wp_editor() and starts
     * correctly, so a difference in how they start is what the browser-side
     * fault is about, not a difference in which one is right.
     *
     * WHAT ACTUALLY HAS TO HOLD is that the browser-started path works, and
     * that when it does not, it says so. See initRichText() in portal.js.
     *
     * It is a working textarea if the script never runs, which is the same
     * degradation the rendered editors have.
     *
     * @param string $name
     * @param string $content
     * @param array  $args 'rows', 'placeholder', 'id'.
     */
    public static function deferred( $name, $content, $args = array() ) {
        $args = array_merge( array( 'rows' => 4, 'placeholder' => '', 'id' => '' ), $args );
        printf(
            '<textarea name="%s"%s rows="%d"%s data-uc-rich>%s</textarea>',
            esc_attr( $name ),
            '' !== $args['id'] ? ' id="' . esc_attr( $args['id'] ) . '"' : '',
            (int) $args['rows'],
            '' !== $args['placeholder'] ? ' placeholder="' . esc_attr( $args['placeholder'] ) . '"' : '',
            esc_textarea( (string) $content )
        );
    }

    /**
     * What may be stored. ONE ANSWER, for every field this control writes.
     *
     * wp_kses_post() rather than a narrower list on purpose: it is the rule
     * WordPress already applies to post content, an editor cannot produce
     * anything it rejects, and inventing a second allow-list here would mean
     * two answers to "what is allowed in prose" that could drift.
     *
     * IT DOES NOT UNSLASH, and the caller must have. That is WordPress's own
     * order, unslash then sanitize, and it matters here: two of the three
     * callers unslash a whole array of rows before looping, so unslashing again
     * inside this would eat a backslash somebody typed.
     *
     * @param mixed $value Already unslashed.
     * @return string
     */
    public static function sanitize( $value ) {
        if ( ! is_string( $value ) ) {
            return '';
        }
        return wp_kses_post( $value );
    }

    /**
     * The same prose, as plain text, for everywhere it is a VALUE.
     *
     * NEVER strip_tags() AND NEVER wp_strip_all_tags(). Both join the text
     * either side of a tag with nothing between, so two paragraphs become
     * "OneTwo". That is the fault 3.38.0 found in wp_trim_words(), which would
     * have broken every card on the public calendar, and it applies to the .ics
     * description, the JSON-LD, the Google Calendar link and every other place
     * this text is read rather than displayed.
     *
     * sfaf_flatten_html() puts a space where a block tag was, then strips.
     *
     * @param string $html
     * @return string
     */
    public static function to_plain( $html ) {
        return function_exists( 'sfaf_flatten_html' )
            ? sfaf_flatten_html( (string) $html )
            : trim( wp_strip_all_tags( (string) $html ) );
    }

    /**
     * Prose for display, from a value that may predate this control.
     *
     * wpautop() so plain text with line breaks reads as the paragraphs it
     * always looked like, and wp_kses_post() so anything the editor stored
     * renders as formatting rather than as visible tags.
     *
     * @param string $value
     * @return string HTML.
     */
    public static function display( $value ) {
        return wpautop( wp_kses_post( (string) $value ) );
    }
}
