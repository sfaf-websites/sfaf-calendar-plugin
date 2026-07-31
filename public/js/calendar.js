/**
 * SFAF Calendar Frontend JS
 */
(function($) {
    'use strict';

    var modal = null;
    var currentEventId = null;

    $(document).ready(function() {
        initFilters();
        initSearch();
        initRSVP();
        initReminders();
        initAddToCalendar();
        initFAQ();
        initPagination();
        initViews();
    });

    /* -----------------------------------------------------------------------
     * View toggle and month grid, on this site.
     *
     * The MARKUP is identical to the embed's, because both come out of
     * SFAF_Shortcodes. Only the transport differs: here a month is fetched
     * through admin-ajax with a nonce, where the embed uses the public REST
     * route because it cannot obtain one from another origin. Keeping the two
     * behaviours in step is a matter of keeping these two functions honest;
     * everything they operate on is server-rendered once.
     * -------------------------------------------------------------------- */

    function initViews() {
        $('.uc-calendar').each(function () {
            var $block = $(this);
            restoreView($block);
            bindMonthGrid($block);
        });

        $(document).on('click', '.uc-view-btn', function () {
            var $block = $(this).closest('.uc-calendar');
            showView($block, $(this).attr('data-view') || 'list', true);
        });

        $(document).on('click', '.uc-month [data-goto]', function () {
            loadMonth($(this).closest('.uc-calendar'), $(this).attr('data-goto'));
        });

        // Tap a day: show it below the grid. A click on an event link inside
        // the cell is a navigation and is left alone.
        $(document).on('click', '.uc-month td.uc-day', function (e) {
            if ($(e.target).closest('a').length) {
                return;
            }
            selectDay($(this).closest('.uc-calendar'), $(this).attr('data-day'));
        });

        $(document).on('keydown', '.uc-month td.uc-day', function (e) {
            var $cells = $(this).closest('.uc-month').find('td.uc-day');
            var index = $cells.index(this);
            var next = -1;

            if (e.key === 'ArrowRight') { next = index + 1; }
            else if (e.key === 'ArrowLeft') { next = index - 1; }
            else if (e.key === 'ArrowDown') { next = index + 7; }
            else if (e.key === 'ArrowUp') { next = index - 7; }
            else if (e.key === 'Home') { next = index - (index % 7); }
            else if (e.key === 'End') { next = index - (index % 7) + 6; }
            else if (e.key === 'Enter' || e.key === ' ') {
                selectDay($(this).closest('.uc-calendar'), $(this).attr('data-day'));
                e.preventDefault();
                return;
            } else { return; }

            if (next >= 0 && next < $cells.length) {
                $cells.attr('tabindex', '-1');
                $cells.eq(next).attr('tabindex', '0').focus();
                e.preventDefault();
            }
        });
    }

    /** Storage key scoped to this block's filters, so blocks stay independent. */
    function viewKey($block) {
        return 'sfafView:' + [
            $block.attr('data-filter-category') || '',
            $block.attr('data-filter-organizer') || '',
            $block.attr('data-filter-series') || '',
            $block.attr('data-filter-venue') || '',
            $block.attr('data-view') || ''
        ].join('|');
    }

    function restoreView($block) {
        var stored = '';
        try {
            stored = window.localStorage.getItem(viewKey($block)) || '';
        } catch (err) { stored = ''; }
        if (stored === 'list' || stored === 'calendar') {
            showView($block, stored, false);
        } else {
            syncDayPanel($block);
        }
    }

    function showView($block, view, remember) {
        $block.find('.uc-panel-list').prop('hidden', view !== 'list');
        $block.find('.uc-panel-calendar').prop('hidden', view !== 'calendar');
        $block.removeClass('uc-view-list uc-view-calendar').addClass('uc-view-' + view);
        $block.attr('data-view', view);

        $block.find('.uc-view-btn').each(function () {
            var on = ($(this).attr('data-view') === view);
            $(this).toggleClass('active', on).attr('aria-pressed', on ? 'true' : 'false');
        });

        if (remember) {
            try { window.localStorage.setItem(viewKey($block), view); } catch (err) { /* private mode */ }
        }
        if (view === 'calendar') {
            syncDayPanel($block);
        }
    }

    function bindMonthGrid($block) {
        syncDayPanel($block);
        prefetchMonths($block);
    }

    function monthParams($block, month) {
        return {
            action: 'uc_load_month',
            nonce: ucData.nonce,
            month: month,
            category: $block.attr('data-filter-category') || '',
            organizer: $block.attr('data-filter-organizer') || '',
            series: $block.attr('data-filter-series') || '',
            venue: $block.attr('data-filter-venue') || ''
        };
    }

    var monthCache = {};

    function loadMonth($block, month) {
        var $panel = $block.find('.uc-panel-calendar');
        if (!$panel.length || !month) {
            return;
        }
        var key = viewKey($block) + '#' + month;
        if (monthCache[key]) {
            $panel.html(monthCache[key]);
            bindMonthGrid($block);
            return;
        }

        var $grid = $block.find('.uc-month');
        $grid.addClass('uc-month-loading').attr('aria-busy', 'true');

        $.post(ucData.ajaxUrl, monthParams($block, month))
            .done(function (res) {
                if (!res || !res.success || !res.data || typeof res.data.html !== 'string') {
                    showMonthError($block, month);
                    return;
                }
                monthCache[key] = res.data.html;
                $panel.html(res.data.html);
                bindMonthGrid($block);
            })
            .fail(function () {
                showMonthError($block, month);
            });
    }

    /* A failed month must never look like an empty one: whole months here
       genuinely have no Friday or Sunday events, so silence would be a lie. */
    function showMonthError($block, month) {
        var $grid = $block.find('.uc-month');
        $grid.removeClass('uc-month-loading').removeAttr('aria-busy');
        $grid.find('.uc-month-error').remove();

        var $box = $('<div class="uc-month-error" role="alert"><span>That month could not be loaded.</span></div>');
        $('<button type="button" class="uc-month-retry">Try again</button>')
            .on('click', function () {
                $box.remove();
                loadMonth($block, month);
            })
            .appendTo($box);
        $grid.prepend($box);
    }

    /** Warm the neighbouring months quietly, after first paint. */
    function prefetchMonths($block) {
        var $grid = $block.find('.uc-month');
        if (!$grid.length) {
            return;
        }
        $.each([$grid.attr('data-prev'), $grid.attr('data-next')], function (_, month) {
            var key = viewKey($block) + '#' + month;
            if (!month || monthCache[key]) {
                return;
            }
            $.post(ucData.ajaxUrl, monthParams($block, month)).done(function (res) {
                if (res && res.success && res.data && typeof res.data.html === 'string') {
                    monthCache[key] = res.data.html;
                }
            });
        });
    }

    function selectDay($block, day) {
        var $grid = $block.find('.uc-month');
        if (!$grid.length || !day) {
            return;
        }
        $grid.attr('data-selected', day);
        $grid.find('td.uc-day').each(function () {
            var on = ($(this).attr('data-day') === day);
            $(this).toggleClass('uc-day-selected', on).attr('aria-selected', on ? 'true' : 'false');
        });
        renderDayPanel($block, day);
    }

    function renderDayPanel($block, day) {
        var $grid = $block.find('.uc-month');
        var $panel = $grid.find('.uc-month-day-panel');
        var $cell = $grid.find('td.uc-day[data-day="' + day + '"]');
        if (!$panel.length || !$cell.length) {
            return;
        }
        $panel.empty();
        $panel.append($('<h4 class="uc-day-panel-title"></h4>')
            .text(($cell.attr('aria-label') || '').split('.')[0]));

        var $events = $cell.find('.uc-day-events');
        if (!$events.length || !$events.children().length) {
            $panel.append('<p class="uc-day-panel-empty">Nothing scheduled on this day.</p>');
            return;
        }
        $panel.append($events.clone());
    }

    /** Never open on a blank day: today if it has events, else the next that does. */
    function syncDayPanel($block) {
        var $grid = $block.find('.uc-month');
        if (!$grid.length) {
            return;
        }
        var already = $grid.attr('data-selected');
        if (already) {
            renderDayPanel($block, already);
            return;
        }
        var today = $grid.attr('data-today') || '';
        var $todayCell = $grid.find('td.uc-day[data-day="' + today + '"]');
        var day = '';

        if ($todayCell.length && parseInt($todayCell.attr('data-count') || '0', 10) > 0) {
            day = today;
        } else {
            $grid.find('td.uc-day').each(function () {
                var d = $(this).attr('data-day');
                if (parseInt($(this).attr('data-count') || '0', 10) > 0) {
                    if (!day) { day = d; }
                    if (d >= today) { day = d; return false; }
                }
            });
        }
        if (day) {
            selectDay($block, day);
        }
    }

    /**
     * Pagination: Load More button + Infinite scroll.
     * (Next/Previous is plain links and needs no JS.)
     */
    function initPagination() {
        // Load More
        $(document).on('click', '.uc-load-more', function() {
            var $container = $(this).closest('[data-render]');
            loadMoreEvents($container, $(this));
        });

        // Infinite scroll
        if ('IntersectionObserver' in window) {
            document.querySelectorAll('.uc-infinite-sentinel').forEach(function(sentinel) {
                var observer = new IntersectionObserver(function(entries) {
                    entries.forEach(function(entry) {
                        if (entry.isIntersecting) {
                            var $container = $(sentinel).closest('[data-render]');
                            loadMoreEvents($container, $(sentinel));
                        }
                    });
                }, { rootMargin: '300px' });
                observer.observe(sentinel);
                sentinel._ucObserver = observer;
            });
        }
    }

    function loadMoreEvents($container, $trigger) {
        if (!$container.length || $container.data('uc-loading')) {
            return;
        }
        var page = parseInt($container.attr('data-page') || '1', 10);
        var maxPages = parseInt($container.attr('data-max-pages') || '1', 10);
        var next = page + 1;

        if (next > maxPages) {
            finishPagination($container, $trigger);
            return;
        }

        $container.data('uc-loading', true);
        var isButton = $trigger.is('button');
        if (isButton) {
            $trigger.prop('disabled', true).text('Loading…');
        } else {
            $trigger.closest('.uc-pagination-infinite').addClass('is-loading');
        }

        $.ajax({
            url: ucData.ajaxUrl,
            method: 'POST',
            data: {
                action:    'uc_load_events',
                nonce:     ucData.nonce,
                page:      next,
                per_page:  $container.attr('data-per-page'),
                category:  $container.attr('data-filter-category') || '',
                organizer: $container.attr('data-filter-organizer') || '',
                series:    $container.attr('data-filter-series') || '',
                venue:     $container.attr('data-filter-venue') || '',
                render:    $container.attr('data-render') || 'card'
            },
            success: function(resp) {
                if (resp && resp.html) {
                    $container.find('.uc-event-list, .uc-upcoming-list').first().append(resp.html);
                    $container.attr('data-page', next);
                }
                if (!resp || !resp.has_more || next >= maxPages) {
                    finishPagination($container, $trigger);
                } else if (isButton) {
                    $trigger.prop('disabled', false).text('Load More');
                } else {
                    $trigger.closest('.uc-pagination-infinite').removeClass('is-loading');
                }
            },
            error: function() {
                if (isButton) {
                    $trigger.prop('disabled', false).text('Load More');
                } else {
                    $trigger.closest('.uc-pagination-infinite').removeClass('is-loading');
                }
            },
            complete: function() {
                $container.data('uc-loading', false);
            }
        });
    }

    function finishPagination($container, $trigger) {
        var $pagination = $trigger.closest('.uc-pagination');
        if ($trigger.is('button')) {
            $pagination.remove();
        } else {
            if ($trigger[0] && $trigger[0]._ucObserver) {
                $trigger[0]._ucObserver.disconnect();
            }
            $pagination.remove();
        }
    }

    /**
     * FAQ accordion (single event page).
     */
    function initFAQ() {
        $(document).on('click', '.uc-faq-q', function() {
            var $item = $(this).closest('.uc-faq-item');
            var open  = $item.toggleClass('open').hasClass('open');
            $(this).attr('aria-expanded', open ? 'true' : 'false');
            $(this).find('.uc-faq-toggle').text(open ? '–' : '+');
        });
    }

    /**
     * Category filter buttons
     */
    function initFilters() {
        $('.uc-filter-btn').on('click', function() {
            var cat = $(this).data('category');
            
            $('.uc-filter-btn').removeClass('active');
            $(this).addClass('active');

            if (cat === 'all') {
                $('.uc-event-card').show();
            } else {
                $('.uc-event-card').each(function() {
                    var cardCat = $(this).data('category');
                    $(this).toggle(cardCat === cat);
                });
            }

            updateCount();
        });

        $('.uc-organizer-select').on('change', function() {
            var org = $(this).val();
            // For now, this is a client-side hint. 
            // Full AJAX filtering can be added in Phase 2.
        });
    }

    /**
     * Search
     */
    function initSearch() {
        var searchTimer;
        $('.uc-search').on('input', function() {
            clearTimeout(searchTimer);
            var query = $(this).val().toLowerCase();
            
            searchTimer = setTimeout(function() {
                if (query.length === 0) {
                    $('.uc-event-card').show();
                } else {
                    $('.uc-event-card').each(function() {
                        var title = $(this).find('.uc-card-title').text().toLowerCase();
                        var excerpt = $(this).find('.uc-card-excerpt').text().toLowerCase();
                        var meta = $(this).find('.uc-card-meta').text().toLowerCase();
                        var match = title.indexOf(query) > -1 || 
                                    excerpt.indexOf(query) > -1 || 
                                    meta.indexOf(query) > -1;
                        $(this).toggle(match);
                    });
                }
                updateCount();
            }, 200);
        });
    }

    /**
     * Update visible event count
     */
    function updateCount() {
        var visible = $('.uc-event-card:visible').length;
        $('.uc-count-number').text(visible);
    }

    /**
     * RSVP system
     */
    function initRSVP() {
        // Build the modal (inject into body once)
        var modalHTML = '<div class="uc-rsvp-modal-overlay" id="uc-rsvp-modal">' +
            '<div class="uc-rsvp-modal">' +
                '<div class="uc-rsvp-form-view">' +
                    '<h3>Register for this Event</h3>' +
                    '<p class="uc-modal-subtitle" id="uc-rsvp-event-title"></p>' +
                    '<div class="uc-rsvp-form">' +
                        '<div><label for="uc-rsvp-name">Full Name *</label>' +
                            '<input type="text" id="uc-rsvp-name" placeholder="Your name" /></div>' +
                        '<div><label for="uc-rsvp-email">Email *</label>' +
                            '<input type="email" id="uc-rsvp-email" placeholder="your@email.com" />' +
                            // Said next to the field it applies to, in plain
                            // words, because this is the address the morning-of
                            // reminder will go to.
                            '<p class="uc-rsvp-note">Event reminders and updates will be sent to this email address.</p></div>' +
                        '<div><label for="uc-rsvp-phone">Phone (optional)</label>' +
                            '<input type="tel" id="uc-rsvp-phone" placeholder="(555) 000-0000" /></div>' +
                        // SEPARATE FROM THE RSVP, AND NEVER PRE-TICKED.
                        // Registering for an event is not consent to a mailing
                        // list, so this is its own decision and it starts off.
                        '<label class="uc-rsvp-optin">' +
                            '<input type="checkbox" id="uc-rsvp-optin" value="1" />' +
                            '<span>Receive monthly email updates from SFAF with events, news and updates.</span>' +
                        '</label>' +
                        '<div class="uc-rsvp-error" id="uc-rsvp-error" style="display:none;"></div>' +
                        '<button class="uc-rsvp-submit" id="uc-rsvp-submit-btn">Register Now</button>' +
                        '<button class="uc-rsvp-cancel" id="uc-rsvp-cancel-btn">Cancel</button>' +
                    '</div>' +
                '</div>' +
                '<div class="uc-rsvp-success" id="uc-rsvp-success" style="display:none;">' +
                    '<div class="uc-check">&#10003;</div>' +
                    '<p>You are registered!</p>' +
                    '<p class="uc-modal-subtitle">We look forward to seeing you.</p>' +
                    '<button class="uc-rsvp-cancel" id="uc-rsvp-close-btn" style="margin-top: 16px;">Close</button>' +
                '</div>' +
            '</div>' +
        '</div>';

        $('body').append(modalHTML);
        modal = $('#uc-rsvp-modal');

        // Open modal
        $(document).on('click', '.uc-rsvp-btn', function(e) {
            e.preventDefault();
            currentEventId = $(this).data('event-id');
            
            // Get event title from card
            var card = $(this).closest('.uc-event-card');
            var title = card.find('.uc-card-title').text().trim();
            $('#uc-rsvp-event-title').text(title);

            // Reset form. The opt-in is cleared with everything else: it must
            // never carry a previous visitor's tick into a fresh form.
            $('#uc-rsvp-name, #uc-rsvp-email, #uc-rsvp-phone').val('');
            $('#uc-rsvp-optin').prop('checked', false);
            $('#uc-rsvp-error').hide();
            // A cleared field is not an invalid field. Reopening the modal must
            // not show last time's complaint about an empty box.
            if (window.sfafEmail) { window.sfafEmail.clear(document.getElementById('uc-rsvp-email')); }
            $('#uc-rsvp-submit-btn').prop('disabled', false).text('Register Now');
            $('.uc-rsvp-form-view').show();
            $('#uc-rsvp-success').hide();

            modal.addClass('active');
        });

        // Close modal
        modal.on('click', function(e) {
            if (e.target === this) closeModal();
        });
        $(document).on('click', '#uc-rsvp-cancel-btn, #uc-rsvp-close-btn', function() {
            closeModal();
        });
        $(document).on('keydown', function(e) {
            if (e.key === 'Escape') closeModal();
        });

        // Submit RSVP
        $(document).on('click', '#uc-rsvp-submit-btn', function() {
            submitRSVP();
        });

        // Submit on Enter
        modal.on('keydown', 'input', function(e) {
            if (e.key === 'Enter') submitRSVP();
        });
    }

    function closeModal() {
        modal.removeClass('active');
        currentEventId = null;
    }

    function submitRSVP() {
        var name  = $('#uc-rsvp-name').val().trim();
        var email = $('#uc-rsvp-email').val().trim();
        var phone = $('#uc-rsvp-phone').val().trim();
        var optin = $('#uc-rsvp-optin').is(':checked') ? '1' : '';

        /*
         * Validation. The email field is judged by the shared validator, which
         * marks the field itself and leaves a specific message under it that
         * stays put — rather than the old "Please enter a valid email address"
         * in a box above the button, which named neither the field nor the
         * problem. Name is still checked here because it is not an email.
         */
        if (!name) {
            $('#uc-rsvp-error').text('Please fill in your name.').show();
            $('#uc-rsvp-name').focus();
            return;
        }
        if (window.sfafEmail && !window.sfafEmail.validate(document.getElementById('uc-rsvp-email'))) {
            $('#uc-rsvp-error').hide();
            $('#uc-rsvp-email').focus();
            return;
        }
        if (!email) {
            $('#uc-rsvp-error').text('Please fill in your email.').show();
            return;
        }

        var btn = $('#uc-rsvp-submit-btn');
        btn.prop('disabled', true).text('Submitting...');
        $('#uc-rsvp-error').hide();

        $.ajax({
            url: ucData.ajaxUrl,
            method: 'POST',
            data: {
                action:   'uc_submit_rsvp',
                nonce:    ucData.nonce,
                event_id: currentEventId,
                name:     name,
                email:    email,
                phone:    phone,
                optin:    optin
            },
            success: function(response) {
                if (response.success) {
                    // Show success
                    $('.uc-rsvp-form-view').hide();
                    $('#uc-rsvp-success').show();

                    // Update the capacity bar and count on the card
                    if (response.count !== undefined) {
                        var card = $('.uc-rsvp-btn[data-event-id="' + currentEventId + '"]').closest('.uc-event-card');
                        var capacityText = card.find('.uc-capacity-text');
                        var capacityFill = card.find('.uc-capacity-fill');
                        
                        if (capacityText.length) {
                            var text = capacityText.text();
                            // Update count in "X/Y spots filled" or "X registered"
                            if (text.indexOf('/') > -1) {
                                var parts = text.split('/');
                                var total = parseInt(parts[1]);
                                capacityText.text(response.count + '/' + total + ' spots filled');
                                capacityFill.css('width', Math.min((response.count / total) * 100, 100) + '%');
                            } else {
                                capacityText.text(response.count + ' registered');
                            }
                        }
                    }
                } else {
                    $('#uc-rsvp-error').text(response.message || 'Something went wrong.').show();
                    btn.prop('disabled', false).text('Register Now');
                }
            },
            error: function() {
                $('#uc-rsvp-error').text('Network error. Please try again.').show();
                btn.prop('disabled', false).text('Register Now');
            }
        });
    }

    /**
     * "Get Reminders" modal — captures an email and saves it with
     * status "subscribed" (separate from RSVP "confirmed").
     */
    function initReminders() {
        var modalHTML = '<div class="uc-rsvp-modal-overlay" id="uc-reminder-modal">' +
            '<div class="uc-rsvp-modal">' +
                '<div class="uc-reminder-form-view">' +
                    '<h3>Get Event Reminders</h3>' +
                    '<p class="uc-modal-subtitle" id="uc-reminder-event-title"></p>' +
                    '<div class="uc-rsvp-form">' +
                        '<div><label for="uc-reminder-email">Email *</label>' +
                            '<input type="email" id="uc-reminder-email" placeholder="your@email.com" /></div>' +
                        '<div class="uc-rsvp-error" id="uc-reminder-error" style="display:none;"></div>' +
                        '<button class="uc-rsvp-submit" id="uc-reminder-submit-btn">Notify Me</button>' +
                        '<button class="uc-rsvp-cancel" id="uc-reminder-cancel-btn">Cancel</button>' +
                    '</div>' +
                '</div>' +
                '<div class="uc-rsvp-success" id="uc-reminder-success" style="display:none;">' +
                    '<div class="uc-check">&#128276;</div>' +
                    '<p id="uc-reminder-success-msg">You are on the list!</p>' +
                    '<button class="uc-rsvp-cancel" id="uc-reminder-close-btn" style="margin-top: 16px;">Close</button>' +
                '</div>' +
            '</div>' +
        '</div>';

        $('body').append(modalHTML);
        var rmodal = $('#uc-reminder-modal');
        var reminderEventId = null;

        $(document).on('click', '.uc-reminder-btn', function(e) {
            e.preventDefault();
            reminderEventId = $(this).data('event-id');
            $('#uc-reminder-event-title').text($(this).data('event-title') || '');
            $('#uc-reminder-email').val('');
            $('#uc-reminder-error').hide();
            if (window.sfafEmail) { window.sfafEmail.clear(document.getElementById('uc-reminder-email')); }
            $('#uc-reminder-submit-btn').prop('disabled', false).text('Notify Me');
            $('.uc-reminder-form-view').show();
            $('#uc-reminder-success').hide();
            rmodal.addClass('active');
        });

        rmodal.on('click', function(e) {
            if (e.target === this) rmodal.removeClass('active');
        });
        $(document).on('click', '#uc-reminder-cancel-btn, #uc-reminder-close-btn', function() {
            rmodal.removeClass('active');
        });
        $(document).on('keydown', function(e) {
            if (e.key === 'Escape') rmodal.removeClass('active');
        });

        function submitReminder() {
            var email = $('#uc-reminder-email').val().trim();
            // Same shared validator as the RSVP form, so the two never disagree
            // about what counts as an address or how they say so.
            if (window.sfafEmail && !window.sfafEmail.validate(document.getElementById('uc-reminder-email'))) {
                $('#uc-reminder-error').hide();
                $('#uc-reminder-email').focus();
                return;
            }
            if (!email) {
                $('#uc-reminder-error').text('Please enter your email address.').show();
                return;
            }

            var btn = $('#uc-reminder-submit-btn');
            btn.prop('disabled', true).text('Submitting...');
            $('#uc-reminder-error').hide();

            $.ajax({
                url: ucData.ajaxUrl,
                method: 'POST',
                data: {
                    action:   'uc_subscribe_reminder',
                    nonce:    ucData.nonce,
                    event_id: reminderEventId,
                    email:    email
                },
                success: function(response) {
                    if (response.success) {
                        $('.uc-reminder-form-view').hide();
                        $('#uc-reminder-success-msg').text(response.message || 'You are on the list!');
                        $('#uc-reminder-success').show();
                    } else {
                        $('#uc-reminder-error').text(response.message || 'Something went wrong.').show();
                        btn.prop('disabled', false).text('Notify Me');
                    }
                },
                error: function() {
                    $('#uc-reminder-error').text('Network error. Please try again.').show();
                    btn.prop('disabled', false).text('Notify Me');
                }
            });
        }

        $(document).on('click', '#uc-reminder-submit-btn', submitReminder);
        rmodal.on('keydown', 'input', function(e) {
            if (e.key === 'Enter') submitReminder();
        });
    }

    /**
     * "Add to Calendar" dropdown toggle.
     */
    function initAddToCalendar() {
        $(document).on('click', '.uc-addcal-toggle', function(e) {
            e.preventDefault();
            e.stopPropagation();
            var parent = $(this).closest('.uc-addcal');
            $('.uc-addcal').not(parent).removeClass('open');
            parent.toggleClass('open');
        });
        $(document).on('click', '.uc-addcal-menu', function(e) {
            e.stopPropagation();
        });
        $(document).on('click', function() {
            $('.uc-addcal').removeClass('open');
        });
    }

})(jQuery);
