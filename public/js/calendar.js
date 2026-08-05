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
        initMaps();
    });

    /* -----------------------------------------------------------------------
     * Click to load the event map.
     *
     * NOTHING HERE RUNS UNTIL A BUTTON IS PRESSED, WHICH IS THE WHOLE POINT.
     * The server sends an empty div and the address as an ordinary link. No
     * iframe, no src, no preconnect, no prefetch: until somebody chooses to
     * see a map, this page makes no request to Google and Google learns
     * nothing about who opened it. These pages carry HIV services, substance
     * use programmes and trans health groups, so that is not a detail.
     *
     * Do not add an IntersectionObserver here, and do not "warm" the frame on
     * hover. Scrolling past something is not consent.
     * -------------------------------------------------------------------- */
    function initMaps() {
        $(document).on('click', '[data-uc-map-show]', function(e) {
            e.preventDefault();

            var wrap = $(this).closest('[data-uc-map]');
            if (!wrap.length || wrap.attr('data-uc-map-loaded') === '1') {
                return;
            }
            wrap.attr('data-uc-map-loaded', '1');

            var key = wrap.attr('data-map-key') || '';
            var query = wrap.attr('data-map-query') || '';
            if (!key || !query) {
                return;
            }

            var src = 'https://www.google.com/maps/embed/v1/place'
                + '?key=' + encodeURIComponent(key)
                + '&q=' + encodeURIComponent(query);

            var frame = document.createElement('iframe');
            frame.src = src;
            frame.title = wrap.attr('data-map-title') || 'Map';
            frame.setAttribute('loading', 'lazy');
            // The frame needs nothing from this page and this page needs
            // nothing from it, so it is given nothing.
            frame.setAttribute('referrerpolicy', 'no-referrer-when-downgrade');
            frame.setAttribute('allowfullscreen', '');
            frame.width = '100%';
            frame.height = '320';
            frame.style.border = '0';

            var holder = wrap.find('[data-uc-map-frame]');
            holder.empty().append(frame).removeAttr('hidden');
            wrap.find('[data-uc-map-placeholder]').remove();
        });
    }

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
            $block.attr('data-scope-category') || $block.attr('data-filter-category') || '',
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
            category: activeCategory($block),
            scope_category: $block.attr('data-scope-category') || '',
            organizer: $block.attr('data-filter-organizer') || '',
            series: $block.attr('data-filter-series') || '',
            venue: $block.attr('data-filter-venue') || ''
        };
    }

    var monthCache = {};

    /**
     * Cache key for one month of one block.
     *
     * The chosen category is part of it. Without that, filtering to a category
     * and then flipping back would serve the other category's grid out of cache,
     * which is the same class of fault the filter bar itself had.
     */
    function monthKey($block, month) {
        return viewKey($block) + '#' + activeCategory($block) + '#' + month;
    }

    function loadMonth($block, month) {
        var $panel = $block.find('.uc-panel-calendar');
        if (!$panel.length || !month) {
            return;
        }
        var key = monthKey($block, month);
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
            var key = monthKey($block, month);
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

        observeSentinels(document);
    }

    /**
     * Watch every infinite-scroll sentinel that is not already watched.
     *
     * Called on load AND after a filter or search rebuilds the control. A
     * sentinel put back into the page by syncPagination() is a new element, and
     * one that nothing observes is an infinite scroll that silently stops at
     * page one.
     */
    function observeSentinels(root) {
        if (!('IntersectionObserver' in window)) {
            return;
        }
        var sentinels = (root || document).querySelectorAll('.uc-infinite-sentinel');
        Array.prototype.forEach.call(sentinels, function (sentinel) {
            if (sentinel._ucObserver) {
                return;
            }
            var observer = new IntersectionObserver(function (entries) {
                entries.forEach(function (entry) {
                    if (entry.isIntersecting) {
                        loadMoreEvents($(sentinel).closest('[data-render]'), $(sentinel));
                    }
                });
            }, { rootMargin: '300px' });
            observer.observe(sentinel);
            sentinel._ucObserver = observer;
        });
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
            // Carries the chosen category and the search term, so the next page
            // is the next page of what is on screen rather than of everything.
            data: listParams($container, next),
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
     * The category this block is currently showing.
     *
     * TWO ATTRIBUTES, NOT ONE. data-scope-category is what the shortcode was
     * written to show and never changes; data-active-category is what the
     * visitor picked. The server clamps the second to the first, so a block
     * scoped to one programme cannot be widened from here.
     */
    function activeCategory($block) {
        var active = $block.attr('data-active-category') || '';
        return active !== '' ? active : ($block.attr('data-scope-category') || $block.attr('data-filter-category') || '');
    }

    /** Everything a list request needs, in one place, so no caller can differ. */
    function listParams($block, page) {
        return {
            action:         'uc_load_events',
            nonce:          ucData.nonce,
            page:           page,
            per_page:       $block.attr('data-per-page'),
            category:       activeCategory($block),
            scope_category: $block.attr('data-scope-category') || '',
            organizer:      $block.attr('data-filter-organizer') || '',
            series:         $block.attr('data-filter-series') || '',
            venue:          $block.attr('data-filter-venue') || '',
            s:              $block.attr('data-filter-s') || '',
            render:         $block.attr('data-render') || 'card'
        };
    }

    /**
     * Category filter buttons.
     *
     * THIS ASKS THE SERVER. It used to hide cards that were already in the page,
     * which meant three separate wrong answers: it only ever looked at the
     * events on the current page, so a category with events on page two showed
     * fewer than it had; it compared one slug against one slug, so an event in
     * two categories was findable under only one of them; and the count it wrote
     * was how many cards were left visible rather than how many events matched.
     *
     * The query is the same one the first page load and the search box run, so
     * a category chosen here returns exactly the events that category holds.
     * The count changing is the point.
     */
    function initFilters() {
        $(document).on('click', '.uc-filter-btn', function () {
            var $btn   = $(this);
            var $block = $btn.closest('.uc-calendar, .uc-upcoming-widget');
            var cat    = String($btn.attr('data-category') || 'all');

            if (!$block.length) {
                return;
            }

            $block.find('.uc-filter-btn').removeClass('active').attr('aria-pressed', 'false');
            $btn.addClass('active').attr('aria-pressed', 'true');

            $block.attr('data-active-category', cat === 'all' ? '' : cat);
            reloadList($block);
            reloadMonth($block);
        });

        /*
         * A chip on a card filters IN PLACE rather than navigating. The href is
         * a real calendar URL carrying ?uc_cat=, because that is what has to
         * happen without JavaScript, in a new tab, or in an embed whose script
         * did not run. When the script IS running and the chip is inside a
         * calendar block, there is nowhere to go: the calendar is already here.
         */
        $(document).on('click', '.uc-lc-chip-link', function (e) {
            var $block = $(this).closest('.uc-calendar, .uc-upcoming-widget');
            if (!$block.length || e.which > 1 || e.metaKey || e.ctrlKey || e.shiftKey) {
                return;
            }
            var slug = String($(this).attr('data-uc-cat') || '');
            if (!slug) {
                return;
            }
            e.preventDefault();

            var $btn = $block.find('.uc-filter-btn[data-category="' + slug + '"]');
            if ($btn.length) {
                $btn.trigger('click');
                return;
            }
            // No button for it: the bar is switched off, or the block is scoped
            // elsewhere. Run the same query anyway; the server decides.
            $block.attr('data-active-category', slug);
            reloadList($block);
            reloadMonth($block);
        });
    }

    /** Re-fetch page one of the list for whatever filters the block now holds. */
    function reloadList($block) {
        var $list = $block.find('.uc-event-list, .uc-upcoming-list').first();
        if (!$list.length) {
            return;
        }

        $block.addClass('uc-searching');
        $.ajax({
            url: ucData.ajaxUrl,
            method: 'POST',
            data: listParams($block, 1),
            success: function (resp) {
                $list.html((resp && resp.html) ? resp.html : '<p class="uc-empty">No events in that category just now.</p>');
                $block.attr('data-page', '1');
                if (resp && typeof resp.max_pages !== 'undefined') {
                    $block.attr('data-max-pages', String(resp.max_pages));
                }
                setCount($block, (resp && typeof resp.total !== 'undefined') ? resp.total : null);
                syncPagination($block, resp);
            },
            complete: function () {
                $block.removeClass('uc-searching');
            }
        });
    }

    /** The month grid follows the same filter; a stale one would contradict the list. */
    function reloadMonth($block) {
        if (!$block.find('.uc-panel-calendar').length) {
            return;
        }
        var month = $block.find('.uc-month').attr('data-month') || $block.attr('data-month') || '';
        loadMonth($block, month);
    }

    /**
     * Put the pagination control back, or take it away, to match the answer.
     *
     * A category with three events must not keep a Load More button from the
     * unfiltered list, and going back to All Events must bring it back.
     */
    function syncPagination($block, resp) {
        var hasMore = !!(resp && resp.has_more);
        var $pag    = $block.find('.uc-pagination');
        var style   = $block.attr('data-pagination') || 'load_more';

        if (!hasMore) {
            $pag.remove();
            return;
        }
        // Numbered page links are server-rendered against a page count this
        // request has changed, so they are not rebuilt here: they would be
        // links to pages of the previous result set. Load More and the infinite
        // sentinel carry no page numbers and can be put back safely.
        if (!$pag.length && style !== 'pages') {
            var html = (style === 'infinite')
                ? '<div class="uc-pagination uc-pagination-infinite"><div class="uc-infinite-sentinel" aria-hidden="true"></div><div class="uc-loading-indicator">Loading…</div></div>'
                : '<div class="uc-pagination uc-pagination-loadmore"><button type="button" class="uc-load-more">Load More</button></div>';
            // Immediately after the cards, which is where the server puts it.
            $block.find('.uc-event-list, .uc-upcoming-list').first().after(html);
            observeSentinels($block[0]);
        }
    }

    /**
     * Search, run by the server.
     *
     * WHAT THIS REPLACES, AND WHY IT HAD TO GO. The old version read the text
     * out of the cards already on the page and hid the ones that did not
     * match. That has two problems and the smaller one is that it only ever
     * looked at the current page: with twelve events shown and forty on the
     * calendar, searching found nothing in the other twenty-eight and said so
     * by showing a shorter list, which reads exactly like "no such event".
     *
     * The bigger one is that it searched the CARD, not the EVENT. A card
     * carries the title, a summary trimmed to twenty-five words, and the time
     * and location line. Everything else about an event, the rest of the
     * description, the venue, the organizer, the series, the category, the
     * FAQ, is not on the card and so could not be found, which is why the
     * search appeared not to look through an event's text. It could not.
     *
     * The query now goes to the server and comes back as a new list, through
     * exactly the same renderer and the same filters as the first page load.
     * SFAF_Search decides what "matches" means, in one place, for this and for
     * the embed and for the caladmin list.
     */
    function initSearch() {
        $('.uc-calendar, .uc-upcoming-widget').each(function () {
            var $block = $(this);
            var $input = $block.find('.uc-search');
            if (!$input.length) {
                return;
            }

            var timer = null;
            var seq = 0;

            function run() {
                var term = $.trim($input.val() || '');
                $block.attr('data-filter-s', term);

                var $list = $block.find('.uc-event-list, .uc-upcoming-list').first();
                if (!$list.length) {
                    return;
                }

                // Every request carries a number and only the newest one is
                // allowed to write. Without this, a slow request for "har"
                // can land after a fast one for "harm reduction" and put the
                // wrong list on screen under the right search box.
                seq++;
                var mine = seq;
                $block.addClass('uc-searching');

                $.ajax({
                    url: ucData.ajaxUrl,
                    method: 'POST',
                    // The same parameter builder the filter chips and Load More
                    // use, so a search inside a chosen category keeps that
                    // category instead of quietly widening back to everything.
                    data: listParams($block, 1),
                    success: function (resp) {
                        if (mine !== seq) {
                            return; // a newer search has already answered
                        }
                        $list.html((resp && resp.html) ? resp.html : '');
                        // Back to page one: the list on screen is now the
                        // first page of a different result set, and leaving
                        // the old number here would make Load More append
                        // page five of it.
                        $block.attr('data-page', '1');
                        if (!resp || !resp.html) {
                            $list.html('<p class="uc-empty">No events match that search.</p>');
                        }
                        if (resp && typeof resp.max_pages !== 'undefined') {
                            $block.attr('data-max-pages', String(resp.max_pages));
                        }
                        setCount($block, (resp && typeof resp.total !== 'undefined') ? resp.total : null);
                        syncPagination($block, resp);
                    },
                    complete: function () {
                        if (mine === seq) {
                            $block.removeClass('uc-searching');
                        }
                    }
                });
            }

            $input.on('input', function () {
                clearTimeout(timer);
                timer = setTimeout(run, 250);
            });
            // Enter should not submit whatever form the shortcode happens to
            // sit inside, and should not wait out the debounce either.
            $input.on('keydown', function (e) {
                if (e.key === 'Enter' || e.keyCode === 13) {
                    e.preventDefault();
                    clearTimeout(timer);
                    run();
                }
            });
        });
    }

    /**
     * The number above the list.
     *
     * PREFER THE SERVER'S TOTAL. Counting the cards on screen was only ever
     * right when there was one page, and it is what made a filtered calendar
     * report "12 events coming up" because twelve was the page size. The card
     * count remains as a fallback for a response that did not carry a total.
     */
    function setCount($block, total) {
        var $scope = ($block && $block.length) ? $block : $(document);
        var value  = (total === null || typeof total === 'undefined')
            ? $scope.find('.uc-event-card:visible, .uc-compact-card:visible').length
            : total;
        $scope.find('.uc-count-number').text(value);
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
