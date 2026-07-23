/**
 * SFAF Calendar Satellite — admin JS (shortcode generator + Sync Now + Reset).
 */
(function ($) {
    'use strict';

    $(document).ready(function () {
        initShortcodeGenerator();
        initSyncNow();
        initResetEvents();
    });

    /* Sync Now (settings page) */
    function initSyncNow() {
        var $btn = $('#sfaf-sat-sync-now');
        if (!$btn.length) {
            return;
        }
        var $status = $('#sfaf-sat-sync-status');
        $btn.on('click', function () {
            $btn.prop('disabled', true).text('Syncing…');
            $status.removeClass('ok err').text('');
            $.post(sfafSat.ajaxUrl, {
                action: 'sfaf_sat_sync_now',
                nonce: sfafSat.nonce
            }).done(function (resp) {
                if (resp && resp.success) {
                    var d = resp.data || {};
                    var msg = 'Synced ' + (d.synced || 0) + ' of ' + (d.total || 0) + ' events.';
                    if (d.deleted) { msg += ' Removed ' + d.deleted + ' stale event(s).'; }
                    $status.addClass('ok').text(msg);
                    if (d.last_sync) { $('#sfaf-sat-last-sync').text(d.last_sync); }
                } else {
                    var msg = (resp && resp.data && resp.data.message) ? resp.data.message : 'Sync failed.';
                    $status.addClass('err').text(msg);
                }
            }).fail(function () {
                $status.addClass('err').text('Sync request failed.');
            }).always(function () {
                $btn.prop('disabled', false).text('Sync Now');
            });
        });
    }

    /* Reset All Events (settings page) */
    function initResetEvents() {
        var $btn = $('#sfaf-sat-reset');
        if (!$btn.length) {
            return;
        }
        var $status = $('#sfaf-sat-reset-status');
        $btn.on('click', function () {
            if (!window.confirm('Delete ALL events stored on this satellite? This cannot be undone. The main site is not affected.')) {
                return;
            }
            $btn.prop('disabled', true).text('Resetting…');
            $status.removeClass('ok err').text('');
            $.post(sfafSat.ajaxUrl, {
                action: 'sfaf_sat_reset_events',
                nonce: sfafSat.nonce
            }).done(function (resp) {
                if (resp && resp.success) {
                    var d = resp.data || {};
                    $status.addClass('ok').text('Removed ' + (d.deleted || 0) + ' event(s). You can now run a fresh sync.');
                    $('#sfaf-sat-last-sync').text('Never');
                } else {
                    var msg = (resp && resp.data && resp.data.message) ? resp.data.message : 'Reset failed.';
                    $status.addClass('err').text(msg);
                }
            }).fail(function () {
                $status.addClass('err').text('Reset request failed.');
            }).always(function () {
                $btn.prop('disabled', false).text('Reset All Events');
            });
        });
    }

    /* Shortcode generator */
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

        $('#uc-gen-copy').on('click', function () {
            var text = $out.val();
            var done = function () { $('#uc-gen-copied').stop(true, true).fadeIn().delay(1500).fadeOut(); };
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(done, function () { fallbackCopy(text); done(); });
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
})(jQuery);
