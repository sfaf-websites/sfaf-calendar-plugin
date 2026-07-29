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
        initEmbedGenerator();
        initGenerateKey();
        initGofundmeConnect();
        initEventbriteConnect();
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
     * GoFundMe Pro: real connection test.
     *
     * Posts whatever is currently in the credential fields, so the button works
     * before the settings have been saved. A blank secret means "use the stored
     * one" — which is what the write-only field submits when it has not been
     * retyped. The secret is sent to our own admin-ajax endpoint only, and
     * neither it nor the returned token is ever displayed.
     */
    function initGofundmeConnect() {
        $(document).on('click', '.uc-gofundme-connect', function(e) {
            e.preventDefault();

            var $btn  = $(this);
            var $pill = $btn.closest('.uc-conn-controls').find('.uc-conn-pill');
            var $msg  = $('.uc-gofundme-conn-msg');
            var label = $btn.text();

            $btn.prop('disabled', true).text('Testing…');
            $pill.removeClass('is-connected').text('Testing…');
            $msg.hide().removeClass('notice notice-success notice-error').text('');

            $.post(sfafAdmin.ajaxUrl, {
                action: 'sfaf_gfmp_connect',
                nonce: sfafAdmin.nonce,
                client_id: $('input[name="uc_settings[gofundme_client_id]"]').val() || '',
                client_secret: $('input[name="uc_settings[gofundme_client_secret]"]').val() || ''
            }).done(function(res) {
                var ok = res && res.success;
                var data = (res && res.data) || {};
                $pill.toggleClass('is-connected', !!ok).text(ok ? 'Connected' : 'Not connected');
                $msg.addClass(ok ? 'notice notice-success' : 'notice notice-error')
                    .text((ok ? '' : 'Connection failed: ') + (data.message || (ok ? 'Connected.' : 'Unknown error.'))
                          + (data.endpoint ? ' [endpoint: ' + data.endpoint + ']' : ''))
                    .slideDown();
            }).fail(function(xhr) {
                $pill.removeClass('is-connected').text('Not connected');
                $msg.addClass('notice notice-error')
                    .text('Connection failed: the request to WordPress itself failed (HTTP ' + xhr.status + ').')
                    .slideDown();
            }).always(function() {
                $btn.prop('disabled', false).text(label);
            });
        });
    }

    /**
     * Eventbrite: real connection test.
     *
     * Posts whatever is currently in the token field, so the button works
     * before the settings have been saved. A blank token means "use the stored
     * one" — which is what the write-only field submits when it has not been
     * retyped. The token is sent to our own admin-ajax endpoint only, and is
     * never displayed or returned.
     *
     * The panel-header badge is updated too, so it cannot keep claiming a
     * connection that has just failed.
     */
    function initEventbriteConnect() {
        $(document).on('click', '.uc-eventbrite-connect', function(e) {
            e.preventDefault();

            var $btn   = $(this);
            var $pill  = $btn.closest('.uc-conn-controls').find('.uc-conn-pill');
            var $badge = $btn.closest('.uc-integration-panel').find('.uc-eventbrite-panel-status');
            var $msg   = $('.uc-eventbrite-conn-msg');
            var label  = $btn.text();

            $btn.prop('disabled', true).text('Testing…');
            $pill.removeClass('is-connected').text('Testing…');
            $msg.hide().removeClass('notice notice-success notice-error').text('');

            $.post(sfafAdmin.ajaxUrl, {
                action: 'sfaf_eventbrite_connect',
                nonce: sfafAdmin.nonce,
                private_token: $('input[name="uc_settings[eventbrite_private_token]"]').val() || ''
            }).done(function(res) {
                var ok = res && res.success;
                var data = (res && res.data) || {};
                $pill.toggleClass('is-connected', !!ok).text(ok ? (data.pill || 'Connected') : 'Not connected');
                $badge.toggleClass('uc-status-connected', !!ok)
                      .toggleClass('uc-status-pending', !ok)
                      .text(ok ? 'Connected' : 'Not verified');
                $msg.addClass(ok ? 'notice notice-success' : 'notice notice-error')
                    .text((ok ? '' : 'Connection failed: ') + (data.message || (ok ? 'Connected.' : 'Unknown error.'))
                          + (data.endpoint ? ' [endpoint: ' + data.endpoint + ']' : ''))
                    .slideDown();
            }).fail(function(xhr) {
                $pill.removeClass('is-connected').text('Not connected');
                $badge.removeClass('uc-status-connected').addClass('uc-status-pending').text('Not verified');
                $msg.addClass('notice notice-error')
                    .text('Connection failed: the request to WordPress itself failed (HTTP ' + xhr.status + ').')
                    .slideDown();
            }).always(function() {
                $btn.prop('disabled', false).text(label);
            });
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

    /* -----------------------------------------------------------------------
     * Embed code generator
     *
     * Writes the block to paste into another site, and drives a live preview of
     * that same block using embed.js — the preview is a real embed hitting the
     * real endpoint, so if it works here it works there.
     * -------------------------------------------------------------------- */

    function initEmbedGenerator() {
        var $screen = $('.uc-embed-gen');
        if (!$screen.length) {
            return;
        }

        var scriptUrl = $screen.attr('data-script-url') || '';
        var $code = $('#uc-embed-code');
        var $preview = $('#uc-embed-preview');
        var previewTimer = null;

        /** Slugs and names of the ticked boxes in one group. */
        function checked(selector) {
            var slugs = [];
            var names = [];
            $(selector + ':checked').each(function () {
                slugs.push($(this).val());
                names.push($(this).attr('data-name') || $(this).val());
            });
            return { slugs: slugs, names: names };
        }

        /** Everything the block needs, read off the form. */
        function currentChoices() {
            var $series = $('#uc-embed-series');
            var perPage = parseInt($('#uc-embed-per-page').val(), 10);

            return {
                categories: checked('.uc-embed-category'),
                organizers: checked('.uc-embed-organizer'),
                seriesId: $series.val() || '',
                seriesName: $series.find('option:selected').attr('data-name') || '',
                perPage: (perPage > 0 ? perPage : 12),
                showFilters: $('#uc-embed-filters').is(':checked')
            };
        }

        /** Join names the way a sentence would: "A, B and C". */
        function readableList(names) {
            if (names.length < 2) {
                return names.join('');
            }
            return names.slice(0, -1).join(', ') + ' and ' + names[names.length - 1];
        }

        /**
         * The plain-English comment above the block. This is the whole point of
         * generating readable markup: someone opening the page in a year should
         * be able to tell what it shows without decoding a series ID.
         */
        function summarize(choices) {
            var cats = choices.categories.names;
            var orgs = choices.organizers.names;
            var what;

            if (choices.seriesName) {
                what = 'the ' + choices.seriesName + ' series';
                if (cats.length) {
                    what += ', ' + readableList(cats) + ' only';
                }
            } else if (cats.length) {
                what = readableList(cats) + ' events';
            } else {
                what = 'all upcoming events';
            }

            if (orgs.length) {
                what += ' from ' + readableList(orgs);
            }

            var summary = 'SFAF Calendar: ' + what +
                ', ' + choices.perPage + ' at a time' +
                (choices.showFilters ? ', with search and category filters' : ', without visitor filters');

            // Nothing here may close the comment early.
            return summary.replace(/--+/g, '-').replace(/[<>]/g, '');
        }

        function buildBlock(choices) {
            var lines = [];
            lines.push('<!-- ' + summarize(choices) + ' -->');
            lines.push('<div class="sfaf-calendar-embed" data-sfaf-calendar');

            if (choices.categories.slugs.length) {
                lines.push('     data-category="' + choices.categories.slugs.join(',') + '"');
            }
            if (choices.organizers.slugs.length) {
                lines.push('     data-organizer="' + choices.organizers.slugs.join(',') + '"');
            }
            if (choices.seriesId) {
                lines.push('     data-series="' + choices.seriesId + '"');
            }
            lines.push('     data-per-page="' + choices.perPage + '"');
            lines.push('     data-show-filters="' + (choices.showFilters ? 'yes' : 'no') + '"></div>');
            lines.push('<script src="' + scriptUrl + '" async><\/script>');

            return lines.join('\n');
        }

        /** Rebuild the preview embed from the current choices. */
        function refreshPreview(choices) {
            if (!$preview.length || !window.sfafCalendarEmbed) {
                return;
            }

            var block = $('<div class="sfaf-calendar-embed"></div>');
            block.attr('data-sfaf-calendar', '');
            block.attr('data-per-page', choices.perPage);
            block.attr('data-show-filters', choices.showFilters ? 'yes' : 'no');
            if (choices.categories.slugs.length) {
                block.attr('data-category', choices.categories.slugs.join(','));
            }
            if (choices.organizers.slugs.length) {
                block.attr('data-organizer', choices.organizers.slugs.join(','));
            }
            if (choices.seriesId) {
                block.attr('data-series', choices.seriesId);
            }

            $preview.empty().append(block);
            window.sfafCalendarEmbed.scan();
        }

        function update() {
            var choices = currentChoices();
            $code.val(buildBlock(choices));

            // The preview costs a request, so let a run of clicks settle first.
            clearTimeout(previewTimer);
            previewTimer = setTimeout(function () {
                refreshPreview(choices);
            }, 350);
        }

        $screen.on('change input', 'input, select', update);

        $('#uc-embed-copy').on('click', function () {
            var $note = $('#uc-embed-copied');

            function report(copied) {
                $note.text(copied ? 'Copied.' : 'Select the block and press Ctrl+C.');
                setTimeout(function () { $note.text(''); }, 3000);
            }

            // Leave the block selected either way, so Ctrl+C works if the
            // clipboard API is blocked (it needs a secure context).
            $code[0].select();

            function legacyCopy() {
                var copied = false;
                try {
                    copied = document.execCommand('copy');
                } catch (e) {
                    copied = false;
                }
                report(copied);
            }

            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText($code.val()).then(function () {
                    report(true);
                }, legacyCopy);
            } else {
                legacyCopy();
            }
        });

        update();
    }

})(jQuery);
