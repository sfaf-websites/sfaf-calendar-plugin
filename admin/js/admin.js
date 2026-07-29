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
        initEventbritePreview();
        initGfmpProbe();
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
     * Eventbrite: fetch and preview events.
     *
     * Read-only — the server creates nothing. Everything rendered here goes in
     * through .text(), so an event name or address from Eventbrite is shown as
     * the characters it contains and can never become markup.
     */
    function initEventbritePreview() {
        // Small builder: a <div>/<span>/etc with text content, never HTML.
        function el(tag, cls, text) {
            var $n = $('<' + tag + '>');
            if (cls) { $n.addClass(cls); }
            if (text !== undefined && text !== null && text !== '') { $n.text(String(text)); }
            return $n;
        }

        // One "Label: value" line, skipped entirely when there is no value.
        function field($into, label, value) {
            if (value === undefined || value === null || value === '' || value === false) { return; }
            var $p = el('div', 'uc-eb-field');
            $p.append(el('span', 'uc-eb-key', label + ': '));
            $p.append(el('span', 'uc-eb-val', value));
            $into.append($p);
        }

        function renderEvent(ev) {
            var $card = el('div', 'uc-eb-event');

            $card.append(el('h4', null, ev.name || '(no name)'));
            field($card, 'ID', ev.id);
            field($card, 'Status', ev.status);

            var start = ev.start_local || '(none)';
            if (ev.start_timezone) { start += ' (' + ev.start_timezone + ')'; }
            if (ev.start_utc) { start += '  ·  UTC ' + ev.start_utc; }
            field($card, 'Start', start);

            var end = ev.end_local || '';
            if (end && ev.end_timezone) { end += ' (' + ev.end_timezone + ')'; }
            if (end && ev.end_utc) { end += '  ·  UTC ' + ev.end_utc; }
            field($card, 'End', end);

            if (ev.online_event) { field($card, 'Online event', 'yes'); }
            field($card, 'Listed', ev.listed ? 'yes' : 'no');
            field($card, 'Capacity', (ev.capacity === null || ev.capacity === undefined) ? '' : ev.capacity);
            field($card, 'Currency', ev.currency);

            // Venue — say plainly when the expansion came back empty, so an
            // absent address is never mistaken for a failed request.
            if (ev.venue_expanded) {
                field($card, 'Venue', ev.venue_name || '(unnamed venue)');
                field($card, 'Address', ev.venue_address || '(no address on the venue)');
            } else {
                field($card, 'Venue', ev.venue_id
                    ? '(not expanded — venue_id ' + ev.venue_id + ')'
                    : (ev.online_event ? '(none — online event)' : '(none)'));
            }

            if (ev.logo_expanded) {
                field($card, 'Logo', ev.logo_url || '(logo object present, no url)');
                if (ev.logo_original && ev.logo_original !== ev.logo_url) {
                    field($card, 'Logo (original)', ev.logo_original);
                }
            } else {
                field($card, 'Logo', '(none)');
            }

            field($card, 'URL', ev.url);

            var d = ev.description || {};
            var desc = [];
            desc.push(d.has_text ? 'text yes (' + d.text_length + ' chars)' : 'text no');
            desc.push(d.has_html ? 'html yes (' + d.html_length + ' chars)' : 'html no');
            desc.push(d.has_summary ? 'summary yes' : 'summary no');
            field($card, 'Description', desc.join(' · '));
            field($card, 'Excerpt', d.excerpt);

            field($card, 'Organization', ev.organization_name
                ? ev.organization_name + ' (' + ev.organization_id + ')'
                : ev.organization_id);

            return $card;
        }

        function render($out, data) {
            $out.empty();

            var $sum = el('div', 'uc-eb-summary');
            $sum.append(el('h3', null, 'Fetched ' + data.total + ' event' + (data.total === 1 ? '' : 's')
                + ' from ' + data.organization_count + ' organization' + (data.organization_count === 1 ? '' : 's')));
            field($sum, 'Status requested', data.status);
            field($sum, 'Expansions requested', data.expand);
            if (data.endpoints) {
                field($sum, 'Organizations endpoint', data.endpoints.organizations);
                field($sum, 'Events endpoint', data.endpoints.events);
            }
            $out.append($sum);

            // Anything the fetch could not finish is stated, not swallowed.
            if (data.notes && data.notes.length) {
                var $notes = el('div', 'uc-eb-notes notice notice-warning');
                $notes.append(el('p', null, 'Incomplete results:'));
                var $ul = el('ul');
                $.each(data.notes, function(_, n) { $ul.append(el('li', null, n)); });
                $notes.append($ul);
                $out.append($notes);
            }

            var $orgs = el('div', 'uc-eb-orgs');
            $orgs.append(el('h3', null, 'Organizations'));
            $.each(data.organizations || [], function(_, org) {
                var $row = el('div', 'uc-eb-org');
                $row.append(el('strong', null, org.name || '(unnamed)'));
                $row.append(el('span', null, '  id ' + org.id));
                $row.append(el('span', null, '  —  ' + org.count + ' event' + (org.count === 1 ? '' : 's')
                    + ' over ' + org.pages + ' page' + (org.pages === 1 ? '' : 's')));
                if (org.endpoint) { field($row, 'Endpoint', org.endpoint); }
                if (org.error) {
                    $row.append(el('div', 'uc-eb-org-error notice notice-error', 'Failed: ' + org.error));
                }
                $orgs.append($row);
            });
            $out.append($orgs);

            var $events = el('div', 'uc-eb-events');
            $events.append(el('h3', null, 'Events'));
            if (!data.events || !data.events.length) {
                $events.append(el('p', null, 'No events came back for that status.'));
            } else {
                $.each(data.events, function(_, ev) { $events.append(renderEvent(ev)); });
            }
            $out.append($events);

            if (data.sample_raw) {
                var $raw = el('div', 'uc-eb-raw');
                $raw.append(el('h3', null, 'Raw payload of the first event, exactly as received'));
                $raw.append(el('pre', 'uc-eb-pre', data.sample_raw));
                $out.append($raw);
            }

            $out.show();
        }

        $(document).on('click', '.uc-eventbrite-preview', function(e) {
            e.preventDefault();

            var $btn   = $(this);
            var $panel = $btn.closest('.uc-integration-panel');
            var $msg   = $panel.find('.uc-eventbrite-fetch-msg');
            var $out   = $panel.find('.uc-eventbrite-preview-out');
            var label  = $btn.text();

            $btn.prop('disabled', true).text('Fetching…');
            $msg.hide().removeClass('notice notice-success notice-error').text('');
            $out.hide().empty();

            $.post(sfafAdmin.ajaxUrl, {
                action: 'sfaf_eventbrite_preview',
                nonce: sfafAdmin.nonce,
                status: $panel.find('.uc-eventbrite-status').val() || 'live',
                private_token: $('input[name="uc_settings[eventbrite_private_token]"]').val() || ''
            }).done(function(res) {
                var data = (res && res.data) || {};
                if (res && res.success) {
                    $msg.addClass('notice notice-success')
                        .text('Fetched ' + data.total + ' event(s) from ' + data.organization_count
                              + ' organization(s). Nothing was imported.')
                        .slideDown();
                    render($out, data);
                } else {
                    $msg.addClass('notice notice-error')
                        .text('Fetch failed: ' + (data.message || 'Unknown error.')
                              + (data.endpoint ? ' [endpoint: ' + data.endpoint + ']' : ''))
                        .slideDown();
                }
            }).fail(function(xhr) {
                $msg.addClass('notice notice-error')
                    .text('Fetch failed: the request to WordPress itself failed (HTTP ' + xhr.status + ').')
                    .slideDown();
            }).always(function() {
                $btn.prop('disabled', false).text(label);
            });
        });
    }

    /**
     * [PROBE] GoFundMe Pro campaign probe — temporary diagnostic.
     *
     * Remove this function, its call in ready(), the panel markup in
     * class-sfaf-admin.php and the [PROBE] block in class-sfaf-gfmp.php
     * together once the campaign payload has been observed.
     *
     * Prints each endpoint's status and body untouched. Bodies go into
     * readonly textareas rather than markup: it keeps them byte-for-byte,
     * makes them selectable for pasting back, and means nothing the API
     * returns can become HTML on this page.
     */
    function initGfmpProbe() {
        var lastText = '';

        function block(r, maxBody) {
            var $b = $('<div class="uc-probe-result">');
            var ok = r.status >= 200 && r.status < 300;

            $b.append($('<h4>').text(r.path));
            var $meta = $('<div class="uc-probe-meta">');
            $meta.append($('<div>').text('URL: ' + r.url));
            if (r.error) {
                $meta.append($('<div class="uc-probe-err">').text('Error: ' + r.error));
            } else {
                $meta.append($('<div>').text(
                    'HTTP ' + r.status + (r.message ? ' ' + r.message : '') +
                    '   ·   ' + (r.content_type || 'no content-type') +
                    '   ·   ' + r.length + ' bytes'
                ));
            }
            if (r.truncated) {
                $meta.append($('<div class="uc-probe-err">').text(
                    'TRUNCATED for display: body was ' + r.length + ' bytes, cut at ' + maxBody +
                    '. Everything after byte ' + maxBody + ' is not shown.'
                ));
            }
            $b.append($meta);
            $b.addClass(r.error ? 'is-err' : (ok ? 'is-ok' : 'is-bad'));

            if (r.body !== '') {
                // Pretty-print only when it really is JSON. Key order is
                // preserved and nothing is dropped; if it does not parse, the
                // bytes are shown exactly as received.
                var shown = r.body, note = 'Raw body, exactly as received.';
                try {
                    var parsed = JSON.parse(r.body);
                    shown = JSON.stringify(parsed, null, 2);
                    note = 'Pretty-printed from valid JSON — key order preserved, nothing filtered. Raw bytes are in the box below.';
                } catch (e) { /* not JSON: show it raw */ }

                $b.append($('<p class="uc-probe-note">').text(note));
                $b.append($('<pre class="uc-probe-pre">').text(shown));
                $b.append($('<label class="uc-probe-rawlabel">').text('Raw body (select all to copy):'));
                $b.append($('<textarea class="uc-probe-raw" readonly rows="4">').val(r.body));
            } else if (!r.error) {
                $b.append($('<p class="uc-probe-note">').text('Empty body.'));
            }

            return $b;
        }

        function asText(data) {
            var lines = ['GoFundMe Pro campaign probe',
                         'campaign id: ' + data.campaign_id,
                         'data base:   ' + data.base, ''];
            $.each(data.results || [], function (_, r) {
                lines.push('=========================================================');
                lines.push('PATH:   ' + r.path);
                lines.push('URL:    ' + r.url);
                if (r.error) {
                    lines.push('ERROR:  ' + r.error);
                } else {
                    lines.push('STATUS: ' + r.status + (r.message ? ' ' + r.message : ''));
                    lines.push('TYPE:   ' + (r.content_type || '(none)'));
                    lines.push('BYTES:  ' + r.length + (r.truncated ? '  [TRUNCATED at ' + data.max_body + ']' : ''));
                }
                lines.push('---------------------------------------------------------');
                lines.push(r.body === '' ? '(empty body)' : r.body);
                lines.push('');
            });
            return lines.join('\n');
        }

        $(document).on('click', '.uc-gfmp-probe', function (e) {
            e.preventDefault();

            var $btn  = $(this);
            var $box  = $btn.closest('.uc-probe-box');
            var $msg  = $box.find('.uc-probe-msg');
            var $out  = $box.find('.uc-probe-out');
            var $copy = $box.find('.uc-probe-copy');
            var label = $btn.text();
            var id    = ($box.find('.uc-probe-id').val() || '').trim();

            if (!id) {
                $msg.removeClass('notice-success').addClass('notice notice-error').text('Enter a campaign ID first.').show();
                return;
            }

            $btn.prop('disabled', true).text('Probing…');
            $msg.hide().removeClass('notice notice-success notice-error').text('');
            $out.hide().empty();
            $copy.hide();

            $.post(sfafAdmin.ajaxUrl, {
                action: 'sfaf_gfmp_probe',
                nonce: sfafAdmin.nonce,
                campaign_id: id
            }).done(function (res) {
                var data = (res && res.data) || {};
                if (!res || !res.success) {
                    $msg.addClass('notice notice-error').text('Probe failed: ' + (data.message || 'Unknown error.')).show();
                    return;
                }
                $.each(data.results || [], function (_, r) { $out.append(block(r, data.max_body)); });
                lastText = asText(data);
                $msg.addClass('notice notice-success')
                    .text('Probed ' + (data.results || []).length + ' endpoint(s) for campaign ' + data.campaign_id +
                          '. Nothing was imported or changed.')
                    .show();
                $out.show();
                $copy.show();
            }).fail(function (xhr) {
                $msg.addClass('notice notice-error')
                    .text('Probe failed: the request to WordPress itself failed (HTTP ' + xhr.status + ').').show();
            }).always(function () {
                $btn.prop('disabled', false).text(label);
            });
        });

        $(document).on('click', '.uc-probe-copy', function (e) {
            e.preventDefault();
            var $btn = $(this), orig = $btn.text();
            if (!lastText) { return; }
            var done = function () { $btn.text('Copied'); setTimeout(function () { $btn.text(orig); }, 1500); };
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(lastText).then(done, function () { $btn.text('Copy failed'); });
            } else {
                var $t = $('<textarea>').val(lastText).appendTo('body').select();
                try { document.execCommand('copy'); done(); } catch (err) { $btn.text('Copy failed'); }
                $t.remove();
            }
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
