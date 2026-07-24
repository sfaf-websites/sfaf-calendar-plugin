/**
 * SFAF Calendar Admin JS
 */
(function($) {
    'use strict';

    $(document).ready(function() {
        initColorPickers();
        initLogoUploader();
        initSeriesImage();
        initRepeaters();
        initGofundmeAutofill();
        initShortcodeGenerator();
        initGenerateKey();
        initGofundmeConnect();
        initGalaxySync();
    });

    /**
     * WP color pickers (Branding)
     */
    function initColorPickers() {
        if ($.fn.wpColorPicker) {
            $('.uc-color-field').wpColorPicker();
        }
    }

    /**
     * WP media uploader for the logo (Branding)
     */
    function initLogoUploader() {
        var frame;

        $(document).on('click', '.uc-upload-logo', function(e) {
            e.preventDefault();
            if (typeof wp === 'undefined' || !wp.media) {
                return;
            }
            if (frame) {
                frame.open();
                return;
            }
            frame = wp.media({
                title: 'Select or Upload Logo',
                button: { text: 'Use this logo' },
                multiple: false
            });
            frame.on('select', function() {
                var attachment = frame.state().get('selection').first().toJSON();
                $('#uc_brand_logo').val(attachment.url);
                $('.uc-logo-preview').html('<img src="' + attachment.url + '" alt="" />');
            });
            frame.open();
        });

        $(document).on('click', '.uc-remove-logo', function(e) {
            e.preventDefault();
            $('#uc_brand_logo').val('');
            $('.uc-logo-preview').empty();
        });
    }

    /**
     * Series image media picker (Series Manager edit screen)
     */
    function initSeriesImage() {
        var frame;
        $(document).on('click', '.uc-series-upload-image', function(e) {
            e.preventDefault();
            if (typeof wp === 'undefined' || !wp.media) { return; }
            if (frame) { frame.open(); return; }
            frame = wp.media({ title: 'Series Image', button: { text: 'Use this image' }, multiple: false });
            frame.on('select', function() {
                var a = frame.state().get('selection').first().toJSON();
                $('#uc_series_image_id').val(a.id);
                var src = (a.sizes && a.sizes.medium) ? a.sizes.medium.url : a.url;
                $('#uc_series_image_preview').html('<img src="' + src + '" alt="" />');
            });
            frame.open();
        });
        $(document).on('click', '.uc-series-remove-image', function(e) {
            e.preventDefault();
            $('#uc_series_image_id').val('');
            $('#uc_series_image_url').val('');
            $('#uc_series_image_preview').empty();
        });
    }

    /**
     * Repeater rows (GoFundMe + Pardot campaign managers)
     */
    function initRepeaters() {
        $('.uc-repeater').each(function() {
            $(this).data('index', $(this).find('.uc-repeater-row').length);
        });

        $(document).on('click', '.uc-repeater-add', function(e) {
            e.preventDefault();
            var $rep = $(this).closest('.uc-repeater');
            var idx  = $rep.data('index') || 0;
            var tpl  = $rep.find('.uc-repeater-template').html();
            // Unique key so new rows never collide with saved numeric indexes.
            var html = tpl.replace(/__INDEX__/g, 'new-' + idx);
            $rep.find('.uc-repeater-rows').append(html);
            $rep.data('index', idx + 1);
        });

        $(document).on('click', '.uc-repeater-remove', function(e) {
            e.preventDefault();
            $(this).closest('.uc-repeater-row').remove();
        });
    }

    /**
     * Per-event GoFundMe campaign dropdown autofills the URL + goal fields.
     */
    function initGofundmeAutofill() {
        $(document).on('change', '.uc-gofundme-select', function() {
            var url  = $(this).val();
            var goal = $(this).find(':selected').data('goal');
            if (url) {
                $('#uc_gofundme_url').val(url);
                $('#uc_gofundme_goal').val(goal || '');
            }
        });
    }

    /**
     * Shortcode generator: live-build the shortcode and copy it.
     */
    function initShortcodeGenerator() {
        var $out = $('#uc-gen-output');
        if (!$out.length) {
            return;
        }

        function build() {
            var type      = $('#uc-gen-type').val();
            var category  = $('#uc-gen-category').val();
            var organizer = $('#uc-gen-organizer').val();
            var count     = $('#uc-gen-count').val();
            var layout    = $('#uc-gen-layout').val();
            var filters   = $('#uc-gen-filters').is(':checked');

            var attrs = [];
            if (category)  { attrs.push('category="' + category + '"'); }
            if (organizer) { attrs.push('organizer="' + organizer + '"'); }

            if (type === 'upcoming_events') {
                $('.uc-gen-calendar-only').hide();
                if (count) { attrs.push('count="' + count + '"'); }
            } else {
                $('.uc-gen-calendar-only').show();
                if (count) { attrs.push('per_page="' + count + '"'); }
                if (layout && layout !== 'cards') { attrs.push('layout="' + layout + '"'); }
                if (!filters) { attrs.push('show_filters="no"'); }
            }

            $out.val('[' + type + (attrs.length ? ' ' + attrs.join(' ') : '') + ']');
        }

        $('.uc-gen-input, #uc-gen-filters').on('input change', build);
        build();

        $('#uc-gen-copy').on('click', function() {
            var text = $out.val();
            var done = function() { $('#uc-gen-copied').stop(true, true).fadeIn().delay(1500).fadeOut(); };

            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(done, function() { fallbackCopy(text); done(); });
            } else {
                fallbackCopy(text);
                done();
            }
        });

        function fallbackCopy(text) {
            var $tmp = $('<textarea>').val(text).appendTo('body').select();
            try { document.execCommand('copy'); } catch (e) {}
            $tmp.remove();
        }
    }

    /**
     * Multi-site: generate a 32-char API key via AJAX (no page reload).
     */
    function initGenerateKey() {
        $(document).on('click', '.uc-generate-key', function() {
            var $btn = $(this);
            $btn.prop('disabled', true).text('Generating…');
            $.post(sfafAdmin.ajaxUrl, {
                action: 'uc_generate_key',
                nonce: sfafAdmin.nonce
            }).done(function(resp) {
                if (resp && resp.success && resp.data && resp.data.key) {
                    $('#uc_multisite_api_key').val(resp.data.key);
                }
            }).always(function() {
                $btn.prop('disabled', false).text('Generate Key');
            });
        });
    }

    /**
     * GoFundMe Pro: Connect (demo toggle) + Fetch Campaigns (demo note).
     */
    function initGofundmeConnect() {
        $(document).on('click', '.uc-gofundme-connect', function() {
            var $box = $('#uc_gofundme_connected');
            var connected = !$box.prop('checked');
            $box.prop('checked', connected);
            $('.uc-conn-pill').first()
                .toggleClass('is-connected', connected)
                .text(connected ? 'Connected' : 'Not connected');
        });
        $(document).on('click', '.uc-gofundme-fetch', function(e) {
            e.preventDefault();
            $('.uc-gofundme-fetch-msg').slideDown();
        });
    }

    /**
     * Galaxy Digital: Sync Now (demo — third-party call not wired).
     */
    function initGalaxySync() {
        $(document).on('click', '.uc-galaxy-sync', function() {
            var $btn = $(this);
            var orig = $btn.text();
            $btn.prop('disabled', true).text('Syncing…');
            setTimeout(function() {
                $btn.prop('disabled', false).text(orig);
                window.alert('Demo: in production this calls the Galaxy Digital API to import volunteer needs.');
            }, 600);
        });
    }

})(jQuery);
