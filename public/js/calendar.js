/**
 * SFAF Calendar Frontend JS
 */
(function($) {
    'use strict';

    var modal = null;
    var currentEventId = null;

    /**
     * Run one initialiser without letting it take the others down.
     *
     * These ran as a bare list, so one exception silently killed every
     * initialiser below it, with nothing on the page or in the console to say
     * which. The reveal was last in that list, which is exactly the position
     * that fails first and reports last.
     */
    function run(name, fn) {
        try {
            fn();
        } catch (err) {
            if (window.console && window.console.error) {
                window.console.error('SFAF calendar: ' + name + ' failed and was skipped.', err);
            }
        }
    }

    $(document).ready(function() {
        // FIRST, not last. Everything below binds handlers for things a visitor
        // may not do for another minute; this one decides what the page looks
        // like on arrival, so it must not sit behind eight other functions that
        // could throw first.
        run('reveal', initReveal);
        run('filters', initFilters);
        run('search', initSearch);
        run('rsvp', initRSVP);
        run('follow', initFollow);
        run('addToCalendar', initAddToCalendar);
        run('faq', initFAQ);
        run('pagination', initPagination);
        run('views', initViews);
        // After views, which is what puts a month grid on the screen. It is
        // delegated, so the order does not actually matter, and it is here
        // rather than earlier because nothing about the page's first paint
        // depends on it.
        run('monthPreview', initMonthPreview);
        // No initMaps. The event map is server-rendered as an ordinary iframe
        // since 3.26.1, so there is nothing here to initialise and the map does
        // not depend on this file running at all.
    });

    /* -----------------------------------------------------------------------
     * ENTRANCE: list cards on approach, the event page in two halves.
     *
     * THIS OBSERVER CONTACTS NOTHING. It adds a class to an element that is
     * already in the document and already rendered. It has never had anything
     * to do with the map, which the server now renders directly.
     *
     * NOTHING IS EVER HIDDEN WITHOUT A WAY BACK. .uc-reveal is what makes an
     * element start invisible, and it is only ever added here, immediately
     * before the element is handed to an observer that will reveal it. If the
     * observer cannot be built, nothing is marked at all and the page is
     * simply the page. There is no path through this function that leaves
     * content invisible.
     *
     * THE HOST THEME IS NOT CONSULTED AND CANNOT INTERFERE. This calendar runs
     * inside sfaf.org's page, and that page may well have its own scroll
     * behaviour, AOS, a WOW.js, a theme's own observer. An IntersectionObserver
     * is a per-instance object with no shared state and no global handler to
     * collide with, and the class it sets is our own. The only way a theme
     * could affect this is by styling .uc-reveal, which is not a name anything
     * else uses. See the twin of this function in embed.js.
     * -------------------------------------------------------------------- */
    function initReveal() {
        /*
         * WHICH BRANCH RAN, WRITTEN WHERE IT CAN BE READ.
         *
         * "The scroll animations are not appearing" and "this machine asks for
         * reduced motion" look identical from the outside, and guessing between
         * them is what cost 3.16.0 a release. Inspect <html> and it now says
         * data-uc-motion="on" or data-uc-motion="reduced", and if it says
         * neither then this function never ran at all, which is a third and
         * quite different answer.
         */
        var reduced = !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
        document.documentElement.setAttribute('data-uc-motion', reduced ? 'reduced' : 'on');

        // Reduced motion: leave every element exactly as the server sent it.
        if (reduced) {
            return;
        }
        if (!('IntersectionObserver' in window)) {
            document.documentElement.setAttribute('data-uc-motion', 'no-observer');
            return;
        }

        // LIST VIEW ONLY. .uc-event-list is the list panel's own container; the
        // month grid is a <table class="uc-month-grid"> and has no cards in it.
        revealAll(document.querySelectorAll('.uc-event-list .uc-event-card'));

        // The event page. Header and picture are on screen at load, so they are
        // revealed on the next frame rather than on approach; everything below
        // is treated like a list card.
        var single = document.querySelector('.uc-single');
        if (single) {
            var now = single.querySelectorAll('.uc-single-header, .uc-single-image');
            Array.prototype.forEach.call(now, function (el) {
                el.classList.add('uc-reveal', 'uc-reveal-now');
            });
            // Two frames: the first lets the browser paint the hidden state, so
            // the transition has something to run from. One frame is enough in
            // Chrome and is not in Safari.
            requestAnimationFrame(function () {
                requestAnimationFrame(function () {
                    Array.prototype.forEach.call(now, function (el) { el.classList.add('is-in'); });
                });
            });

            revealAll(single.querySelectorAll(
                '.uc-single-body, .uc-donate-block, .uc-galaxy-block, .uc-faq, .uc-series-list, .uc-map, .uc-single-card'
            ));
        }
    }

    /**
     * Hide these, then reveal each one the first time it enters the viewport.
     *
     * ONCE PER ELEMENT: the observer stops watching on the first crossing, so a
     * long list scrolled up and down does not replay. rootMargin brings the
     * trigger 40px inside the bottom edge, so a card has started moving by the
     * time it is properly on screen rather than after.
     */
    function revealAll(nodes) {
        if (!nodes || !nodes.length) {
            return;
        }
        var observer = new IntersectionObserver(function (entries, obs) {
            entries.forEach(function (entry) {
                if (!entry.isIntersecting) { return; }
                entry.target.classList.add('is-in');
                obs.unobserve(entry.target);
            });
        }, { rootMargin: '0px 0px -40px 0px', threshold: 0.01 });

        Array.prototype.forEach.call(nodes, function (el) {
            if (el.classList.contains('uc-reveal')) { return; }
            el.classList.add('uc-reveal');
            observer.observe(el);
        });
    }

    // Cards appended by "Load more" and by a filter change have never been
    // observed, so they are picked up here. Exposed rather than called inline
    // because the two append sites are in different functions.
    window.sfafRevealNew = function (scope) {
        if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) { return; }
        if (!('IntersectionObserver' in window)) { return; }
        var root = scope || document;
        revealAll(root.querySelectorAll('.uc-event-list .uc-event-card:not(.uc-reveal)'));
    };

    /* -----------------------------------------------------------------------
     * THE EVENT MAP USED TO BE BUILT HERE, AND IS NOT ANY MORE.
     *
     * initMaps() bound a click handler that assembled a Google Maps iframe from
     * data attributes when somebody pressed "Show map". The button is gone as of
     * 3.26.1 and the map is server-rendered, so the whole function went with it.
     *
     * The reasoning behind the deferred load, which was real and has been
     * weighed rather than forgotten, is recorded at sfaf_event_map_html() in
     * sfaf-template-functions.php. Read it there before adding anything back.
     * -------------------------------------------------------------------- */

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

    /**
     * The per-block set-up, which has to run again on markup that arrives later.
     *
     * Everything else in this file is delegated from the document and therefore
     * survives a block being replaced. These three are bound to elements inside
     * one block, so choosing a group, which re-renders the block, has to redo
     * them or the month grid, the search box and infinite scroll would all go
     * quiet on markup that looks identical.
     */
    function initViewsFor($blocks) {
        $blocks.filter('.uc-calendar').each(function () {
            var $block = $(this);
            restoreView($block);
            bindMonthGrid($block);
        });
        bindSearchIn($blocks);
        $blocks.each(function () { observeSentinels(this); });
    }

    function initViews() {
        initViewsFor($('.uc-calendar'));

        $(document).on('click', '.uc-view-btn', function () {
            var $block = $(this).closest('.uc-calendar');
            showView($block, $(this).attr('data-view') || 'list', true);
        });

        /* SCOPED TO THE BLOCK, NOT TO THE GRID (3.45.0). The month head moved
           out of .uc-month in the combined mode, to span both halves, and the
           month tabs live under the sidebar. Both carry data-goto and both mean
           the same thing, so one handler answers for all of them. */
        $(document).on('click', '.uc-calendar [data-goto]', function () {
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
        /* A COMBINED BLOCK MAY REMEMBER 'list' AND NOTHING ELSE (3.82.0), which
         * is the same rule viewFor() applies in embed.js and for the same
         * reason: its toggle offers combined and list, so 'list' is the only
         * value its own control could have written, and a stray 'calendar' from
         * another block must not collapse a layout somebody configured. */
        if (($block.attr('data-view') || '') === 'combined') {
            if (stored === 'list') { showView($block, 'list', false); }
            return;
        }
        if (stored === 'list' || stored === 'calendar') {
            showView($block, stored, false);
        } else {
            syncDayPanel($block);
        }
    }

    /**
     * WHICH PANELS A VIEW SHOWS. The twin of panelHiddenFor() in embed.js, and
     * the reasoning is written out there.
     *
     * 'combined' REACHES THIS NOW (3.82.0), which the note here used to say it
     * could not: the mode has a toggle again, because stacked it is a very tall
     * grid with no route to the list. The clause below was written before it
     * was reachable, on the principle that "this call site happens not to do
     * that" is not a property either file should depend on, and it is the
     * reason this one did not have to be found the hard way.
     */
    function panelHiddenFor(view, panel) {
        if (view === 'combined') {
            return (panel === 'list');
        }
        if (view !== 'list' && view !== 'calendar') {
            return false;
        }
        return (view !== panel);
    }

    function showView($block, view, remember) {
        $block.find('.uc-panel-list').prop('hidden', panelHiddenFor(view, 'list'));
        $block.find('.uc-panel-calendar').prop('hidden', panelHiddenFor(view, 'calendar'));

        /* The two pieces only a combined block has, and the wrapper class that
         * makes it a two-column card. The twin of the block in embed.js's
         * showView(), where the reasoning is written out. */
        var $panels = $block.find('.uc-view-panels[data-uc-combined="1"]');
        if ($panels.length) {
            var toCombined = (view === 'combined');
            $block.find('.uc-combined-head').prop('hidden', !toCombined);
            $block.find('.uc-panel-sidebar').prop('hidden', !toCombined);
            $panels.toggleClass('uc-view-panels-combined', toCombined);
        }
        // Every mode named, so applying one removes the last rather than
        // leaving the block wearing two.
        $block.removeClass('uc-view-list uc-view-calendar uc-view-combined uc-view-sidebar').addClass('uc-view-' + view);
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
        /* The combined mode redraws both halves, so the request has to say so
           and has to carry what the sidebar was built with. See
           ajax_load_month(). */
        var $panels = $block.find('.uc-view-panels-combined');
        return {
            action: 'uc_load_month',
            nonce: ucData.nonce,
            month: month,
            category: activeCategory($block),
            scope_category: $block.attr('data-scope-category') || '',
            groups: activeGroups($block),
            organizer: $block.attr('data-filter-organizer') || '',
            series: $block.attr('data-filter-series') || '',
            venue: $block.attr('data-filter-venue') || '',
            /* THE VIEW, NOT A BOOLEAN. The server decides the shape from this,
               with the same normalize_view() the first render used, so a redraw
               cannot produce a shape the first render would not have. */
            view: $block.attr('data-view') || ($panels.length ? 'combined' : ''),
            side_count: $panels.attr('data-uc-side-count') || '',
            side_heading: $panels.attr('data-uc-side-heading') || ''
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
        /*
         * BOTH HALVES MOVE TOGETHER (3.45.0).
         *
         * The sidebar shows the month the grid shows, so swapping the grid
         * alone would leave October beside September's list. Applied in one
         * place, from one response, so the two cannot get out of step: there is
         * no path here that updates one and not the other.
         */
        function applyMonth($block, data) {
            /* A payload, not a string. jQuery's .html(undefined) is a getter,
               so without this a malformed entry redraws nothing and looks
               exactly like a month that would not move. */
            if (!data || typeof data.html !== 'string') {
                return;
            }
            $panel.html(data.html);
            if (typeof data.side === 'string') {
                $block.find('.uc-panel-sidebar').html(data.side);
            }
            if (typeof data.head === 'string') {
                $block.find('.uc-combined-head').html(data.head);
            }
            bindMonthGrid($block);
        }

        var key = monthKey($block, month);
        if (monthCache[key]) {
            applyMonth($block, monthCache[key]);
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
                monthCache[key] = res.data;
                applyMonth($block, res.data);
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
            /* THE WHOLE PAYLOAD, the same shape loadMonth() stores (3.45.2).
               This stored the grid string alone while loadMonth() stored the
               object, so applyMonth() read .html off a string, got undefined,
               and jQuery's .html(undefined) is a GETTER: clicking through to a
               warmed month changed nothing at all, silently, and only on the
               months the prefetch had reached. */
            $.post(ucData.ajaxUrl, monthParams($block, month)).done(function (res) {
                if (res && res.success && res.data && typeof res.data.html === 'string') {
                    monthCache[key] = res.data;
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
                    // Cards that were not in the document when initReveal() ran
                    // have never been observed. Without this they would simply
                    // be visible, which is correct but inconsistent with the
                    // page they were appended to.
                    if (window.sfafRevealNew) { window.sfafRevealNew($container[0]); }
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

    /** The groups this block is narrowed to, as the server wants them. */
    function activeGroups($block) {
        return $block.attr('data-active-groups') || '';
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
            groups:         activeGroups($block),
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
            // A different category holds different groups, so the second-level
            // choice cannot survive the first-level one changing. Left in place
            // it would be a filter for something not on screen.
            $block.attr('data-active-groups', '');
            reloadBlock($block);
        });

        /*
         * THE ORGANIZER, AS A REAL QUERY (3.50.0).
         *
         * This control existed and did nothing: it rendered on this site only,
         * with no handler in either script, so choosing an organizer changed
         * neither the rows nor the count. It runs the same server query the
         * chips run now, for the same reason they do: hiding rows would only
         * ever see the page already downloaded.
         *
         * The group choice is cleared with it. A different organizer holds
         * different series, so a pill left selected would be a filter for
         * something no longer on screen.
         */
        $(document).on('change', '[data-uc-organizer]', function () {
            var $sel   = $(this);
            var $block = $sel.closest('.uc-calendar, .uc-upcoming-widget');
            var slug   = String($sel.val() || 'all');

            if (!$block.length) {
                return;
            }

            $block.attr('data-active-organizer', slug === 'all' ? '' : slug);
            $block.attr('data-active-groups', '');
            reloadBlock($block);
        });

        /*
         * THE SECOND LEVEL. Multi-select, because asking for two groups means
         * "either of these", and a server query for the same reason the category
         * chips are one: hiding rows would only ever see the current page, and a
         * shorter list is indistinguishable from "no such group".
         *
         * The whole block is re-rendered rather than just the list, because the
         * row itself and the breadcrumb above it both change with the answer.
         */
        $(document).on('click', '[data-uc-group]', function (e) {
            var $el = $(this);
            if ($el.is('input')) {
                return; // the change handler below owns the checkbox form
            }
            e.preventDefault();
            toggleGroup($el.closest('.uc-calendar'), String($el.attr('data-uc-group') || ''));
        });

        $(document).on('change', 'input[data-uc-group]', function () {
            toggleGroup($(this).closest('.uc-calendar'), String($(this).attr('data-uc-group') || ''));
        });

        $(document).on('click', '[data-uc-group-clear]', function (e) {
            e.preventDefault();
            var $block = $(this).closest('.uc-calendar');
            $block.attr('data-active-groups', '');
            reloadBlock($block);
        });

        /*
         * THE ORGANIZER, AS A REAL QUERY (3.50.0).
         *
         * This control existed and did nothing: it rendered on this site only,
         * with no handler in either script, so choosing an organizer changed
         * neither the rows nor the count. It runs the same server query the
         * chips run now, for the same reason they do: hiding rows would only
         * ever see the page already downloaded.
         *
         * The group choice is cleared with it. A different organizer holds
         * different series, so a pill left selected would be a filter for
         * something no longer on screen.
         */
        $(document).on('change', '[data-uc-organizer]', function () {
            var $sel   = $(this);
            var $block = $sel.closest('.uc-calendar, .uc-upcoming-widget');
            var slug   = String($sel.val() || 'all');

            if (!$block.length) {
                return;
            }

            $block.attr('data-active-organizer', slug === 'all' ? '' : slug);
            $block.attr('data-active-groups', '');
            reloadBlock($block);
        });

        /* The breadcrumb steps back up a level. "All" clears both, the category
         * segment clears only the groups under it. */
        $(document).on('click', '[data-uc-crumb]', function (e) {
            e.preventDefault();
            var $block = $(this).closest('.uc-calendar');
            if ($(this).attr('data-uc-crumb') === 'all') {
                $block.attr('data-active-category', '');
            }
            $block.attr('data-active-groups', '');
            reloadBlock($block);
        });

        /*
         * THE ORGANIZER, AS A REAL QUERY (3.50.0).
         *
         * This control existed and did nothing: it rendered on this site only,
         * with no handler in either script, so choosing an organizer changed
         * neither the rows nor the count. It runs the same server query the
         * chips run now, for the same reason they do: hiding rows would only
         * ever see the page already downloaded.
         *
         * The group choice is cleared with it. A different organizer holds
         * different series, so a pill left selected would be a filter for
         * something no longer on screen.
         */
        $(document).on('change', '[data-uc-organizer]', function () {
            var $sel   = $(this);
            var $block = $sel.closest('.uc-calendar, .uc-upcoming-widget');
            var slug   = String($sel.val() || 'all');

            if (!$block.length) {
                return;
            }

            $block.attr('data-active-organizer', slug === 'all' ? '' : slug);
            $block.attr('data-active-groups', '');
            reloadBlock($block);
        });
    }

    /** Add or remove one group from the selection, then ask the server again. */
    function toggleGroup($block, slug) {
        if (!$block.length || !slug) {
            return;
        }
        var current = activeGroups($block);
        var list = current === '' ? [] : current.split(',');
        var at = $.inArray(slug, list);

        if (at === -1) {
            list.push(slug);
        } else {
            list.splice(at, 1);
        }
        $block.attr('data-active-groups', list.join(','));
        reloadBlock($block);
    }

    /**
     * Re-render the whole block for the filters it now holds.
     *
     * The list alone is not enough here: the Groups row is derived from what the
     * chosen category contains, and the breadcrumb states where the visitor is.
     * Both are server-rendered, so both come back with the block.
     */
    function reloadBlock($block) {
        if (!$block.length) {
            return;
        }
        /*
         * The block is asked for in the SAME shape the shortcode renders it:
         * `category` is the block's own scope and `active_category` is what the
         * visitor picked, kept apart exactly as they are on a page load. Sending
         * the effective category as the scope would let a chip promote itself
         * into the block's definition, and the next request could not tell the
         * two apart any more.
         */
        var params = {
            action:          'uc_load_block',
            nonce:           ucData.nonce,
            per_page:        $block.attr('data-per-page'),
            category:        $block.attr('data-scope-category') || '',
            active_category: $block.attr('data-active-category') || '',
            active_groups:   activeGroups($block),
            organizer:        $block.attr('data-scope-organizer') || $block.attr('data-filter-organizer') || '',
            active_organizer: $block.attr('data-active-organizer') || '',
            filters:          $block.attr('data-filters') || '',
            series:          $block.attr('data-filter-series') || '',
            venue:           $block.attr('data-filter-venue') || '',
            s:               $block.attr('data-filter-s') || '',
            layout:          ($block.attr('data-render') === 'compact') ? 'compact' : 'cards',
            view:            $block.attr('data-view') || 'list',
            month:           $block.find('.uc-month').attr('data-month') || $block.attr('data-month') || '',
            toggle:          $block.find('.uc-view-toggle').length ? 'yes' : 'no'
        };

        $block.addClass('uc-searching');
        $.ajax({
            url: ucData.ajaxUrl,
            method: 'POST',
            data: params,
            success: function (resp) {
                if (!resp || !resp.html) {
                    // Say nothing rather than blank the calendar somebody was
                    // reading. The filters on the block are unchanged, so
                    // pressing again retries.
                    return;
                }
                var $fresh = $(resp.html);
                $block.replaceWith($fresh);
                initViewsFor($fresh);
            },
            complete: function () {
                $block.removeClass('uc-searching');
            }
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
            $block.attr('data-active-groups', '');
            reloadBlock($block);
        });

        /*
         * THE ORGANIZER, AS A REAL QUERY (3.50.0).
         *
         * This control existed and did nothing: it rendered on this site only,
         * with no handler in either script, so choosing an organizer changed
         * neither the rows nor the count. It runs the same server query the
         * chips run now, for the same reason they do: hiding rows would only
         * ever see the page already downloaded.
         *
         * The group choice is cleared with it. A different organizer holds
         * different series, so a pill left selected would be a filter for
         * something no longer on screen.
         */
        $(document).on('change', '[data-uc-organizer]', function () {
            var $sel   = $(this);
            var $block = $sel.closest('.uc-calendar, .uc-upcoming-widget');
            var slug   = String($sel.val() || 'all');

            if (!$block.length) {
                return;
            }

            $block.attr('data-active-organizer', slug === 'all' ? '' : slug);
            $block.attr('data-active-groups', '');
            reloadBlock($block);
        });
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
        bindSearchIn($('.uc-calendar, .uc-upcoming-widget'));
    }

    /**
     * Bind the search box inside each of these blocks.
     *
     * Guarded, because a block that has just been re-rendered is passed through
     * here again and a second listener on the same box would fire two requests
     * per keystroke.
     */
    function bindSearchIn($blocks) {
        $blocks.filter('.uc-calendar, .uc-upcoming-widget').each(function () {
            var $block = $(this);
            var $input = $block.find('.uc-search');
            if (!$input.length || $input.attr('data-uc-search-bound') === '1') {
                return;
            }
            $input.attr('data-uc-search-bound', '1');

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
                        if (window.sfafRevealNew) { window.sfafRevealNew($block[0]); }
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

    /* ---------------------------------------------------------------------
     * OPENING A MODAL IN THE TOP LAYER.
     *
     * Both modals here used to be divs toggled with an `active` class, relying
     * on `position: fixed` and a large z-index. On resources.sfaf.org that put
     * the registration dialog under the theme's header and centred it on the
     * page rather than the viewport. See the block above
     * `dialog.uc-rsvp-modal-overlay` in calendar.css for why one cause
     * produces both symptoms and why no number written by us could fix it.
     *
     * showModal() is what changes: the element is painted above every stacking
     * context in the document and its containing block is the viewport, so
     * neither an ancestor's transform nor a theme's header can reach it. Focus
     * moves into the dialog and the rest of the page goes inert, both of which
     * this dialog should always have had.
     *
     * THE FALLBACK IS THE OLD BEHAVIOUR, not an error. A browser without
     * showModal() gets the `open` attribute instead, which makes the dialog
     * display: flex and leaves it competing on z-index exactly as before. That
     * is worse on this one theme and no worse than today anywhere else.
     *
     * THE FRAME BETWEEN display AND opacity IS NOT DECORATION. The element
     * goes from `display: none` to `display: flex` in the same tick, and a
     * transition cannot start from a box that did not exist a moment ago. One
     * animation frame later, it can, and the fade is preserved.
     * ------------------------------------------------------------------- */
    function openOverlay($el) {
        var el = $el[0];
        if (!el) { return; }
        if (typeof el.showModal === 'function') {
            if (!el.open) { el.showModal(); }
        } else {
            el.setAttribute('open', '');
        }
        if (window.requestAnimationFrame) {
            window.requestAnimationFrame(function () { $el.addClass('active'); });
        } else {
            $el.addClass('active');
        }
    }

    function closeOverlay($el) {
        var el = $el[0];
        if (!el) { return; }
        $el.removeClass('active');
        if (typeof el.close === 'function') {
            if (el.open) { el.close(); }
        } else {
            el.removeAttribute('open');
        }
    }

    /**
     * RSVP system
     */
    function initRSVP() {
        // Build the modal (inject into body once)
        var modalHTML = '<dialog class="uc-rsvp-modal-overlay" id="uc-rsvp-modal">' +
            '<div class="uc-rsvp-modal">' +
                '<div class="uc-rsvp-form-view">' +
                    '<h3>Register for this Event</h3>' +
                    '<p class="uc-modal-subtitle" id="uc-rsvp-event-title"></p>' +
                    '<div class="uc-rsvp-form">' +
                        // TWO FIELDS, AND THE SECOND IS GENUINELY OPTIONAL.
                        //
                        // Somebody registering for an HIV testing session or a
                        // trans health group has good reason to give a first
                        // name and no more, and some people have one name. The
                        // label says optional, the validator does not ask for
                        // it, and the server does not require it either, so
                        // there is no level at which it quietly becomes
                        // mandatory.
                        '<div><label for="uc-rsvp-first-name">First Name *</label>' +
                            '<input type="text" id="uc-rsvp-first-name" autocomplete="given-name" placeholder="Your first name" /></div>' +
                        '<div><label for="uc-rsvp-last-name">Last Name (optional)</label>' +
                            '<input type="text" id="uc-rsvp-last-name" autocomplete="family-name" placeholder="Your last name" /></div>' +
                        '<div><label for="uc-rsvp-email">Email *</label>' +
                            '<input type="email" id="uc-rsvp-email" placeholder="your@email.com" />' +
                            // Said next to the field it applies to, in plain
                            // words, because this is the address the morning-of
                            // reminder will go to.
                            '<p class="uc-rsvp-note">Event reminders and updates will be sent to this email address.</p></div>' +
                        // NOT FORMATTED AS THEY TYPE. The number is punctuated
                        // once, on the server, when it is complete and only if
                        // it is a plain US ten-digit number. See
                        // SFAF_RSVP::format_phone(). Nothing rewrites the value
                        // under the caret while somebody is still typing it,
                        // and an international number, a country code or an
                        // extension is kept exactly as entered.
                        '<div><label for="uc-rsvp-phone">Phone (optional)</label>' +
                            '<input type="tel" id="uc-rsvp-phone" autocomplete="tel" placeholder="(555) 000-0000" /></div>' +
                        // SEPARATE FROM THE RSVP, AND NEVER PRE-TICKED.
                        // Registering for an event is not consent to a mailing
                        // list, so this is its own decision and it starts off.
                        '<label class="uc-rsvp-optin">' +
                            '<input type="checkbox" id="uc-rsvp-optin" value="1" />' +
                            '<span>Receive monthly email updates from SFAF with events, news, and updates.</span>' +
                        '</label>' +
                        '<div class="uc-rsvp-error" id="uc-rsvp-error" style="display:none;"></div>' +
                        '<button class="uc-rsvp-submit" id="uc-rsvp-submit-btn">Register Now</button>' +
                        '<button class="uc-rsvp-cancel" id="uc-rsvp-cancel-btn">Cancel</button>' +
                    '</div>' +
                '</div>' +
                '<div class="uc-rsvp-success" id="uc-rsvp-success" style="display:none;">' +
                    '<div class="uc-check">&#10003;</div>' +
                    '<p id="uc-rsvp-success-msg">You are registered!</p>' +
                    '<p class="uc-modal-subtitle">We look forward to seeing you.</p>' +
                    // THE EMAIL, STATED NEUTRALLY.
                    //
                    // "It may be in your spam folder" undercuts a message that
                    // has just been sent: it invites the reader to doubt
                    // whether it went at all. This says the confirmation is
                    // coming and names the two places to look, in one
                    // sentence, as an instruction rather than an apology.
                    '<p class="uc-rsvp-inbox-note">Check your inbox for a confirmation, including your spam folder.</p>' +
                    // ADD TO CALENDAR, HERE, BECAUSE THIS IS THE MOMENT.
                    //
                    // Somebody who has just registered is thinking about the
                    // date. The confirmation email carries these same two
                    // links, and it may take a minute to arrive or land
                    // somewhere they have to go looking; this is the second
                    // where the answer to "will I remember this" is one tap.
                    // The two destinations are built server side by the same
                    // helpers the email uses, so the modal cannot offer a
                    // different link from the message.
                    '<div class="uc-rsvp-addcal-row" id="uc-rsvp-addcal" style="display:none;">' +
                        '<a class="uc-rsvp-addcal uc-rsvp-addcal-primary" id="uc-rsvp-gcal" href="#" target="_blank" rel="noopener noreferrer">Add to Google Calendar</a>' +
                        '<a class="uc-rsvp-addcal uc-rsvp-addcal-secondary" id="uc-rsvp-ics" href="#">Add to Apple or Outlook</a>' +
                    '</div>' +
                    '<button class="uc-rsvp-cancel" id="uc-rsvp-close-btn" style="margin-top: 16px;">Close</button>' +
                '</div>' +
            '</div>' +
        '</dialog>';

        $('body').append(modalHTML);
        modal = $('#uc-rsvp-modal');

        /* A dialog closes itself on Escape, and that path does not go through
           closeOverlay(). Listening for its own close event is what keeps the
           fade class and the pending event id from surviving it. */
        modal.on('close', function () {
            modal.removeClass('active');
            currentEventId = null;
        });

        // Open modal
        $(document).on('click', '.uc-rsvp-btn', function(e) {
            e.preventDefault();
            currentEventId = $(this).data('event-id');
            
            // The event name, from the button that knows it. This used to read
            // .uc-card-title out of the enclosing .uc-event-card, which only
            // exists in a list: pressing RSVP on the event page itself found
            // no card, no title, and opened the modal with a blank subtitle.
            // The card is still the fallback for any older markup that has the
            // class but not the attribute.
            var title = ($(this).data('event-title') || '').toString().trim();
            if (!title) {
                title = $(this).closest('.uc-event-card').find('.uc-card-title').text().trim();
            }
            $('#uc-rsvp-event-title').text(title);

            // Reset form. The opt-in is cleared with everything else: it must
            // never carry a previous visitor's tick into a fresh form.
            $('#uc-rsvp-first-name, #uc-rsvp-last-name, #uc-rsvp-email, #uc-rsvp-phone').val('');
            $('#uc-rsvp-optin').prop('checked', false);
            $('#uc-rsvp-error').hide();
            // The previous registrant's greeting and their add-to-calendar
            // links belong to their event, not to this one.
            $('#uc-rsvp-success-msg').text('You are registered!');
            $('#uc-rsvp-addcal').hide();
            // A cleared field is not an invalid field. Reopening the modal must
            // not show last time's complaint about an empty box.
            if (window.sfafEmail) { window.sfafEmail.clear(document.getElementById('uc-rsvp-email')); }
            $('#uc-rsvp-submit-btn').prop('disabled', false).text('Register Now');
            $('.uc-rsvp-form-view').show();
            $('#uc-rsvp-success').hide();

            openOverlay(modal);
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
        closeOverlay(modal);
        currentEventId = null;
    }

    function submitRSVP() {
        var first = $('#uc-rsvp-first-name').val().trim();
        var last  = $('#uc-rsvp-last-name').val().trim();
        var email = $('#uc-rsvp-email').val().trim();
        var phone = $('#uc-rsvp-phone').val().trim();
        var optin = $('#uc-rsvp-optin').is(':checked') ? '1' : '';

        /*
         * Validation. The email field is judged by the shared validator, which
         * marks the field itself and leaves a specific message under it that
         * stays put — rather than the old "Please enter a valid email address"
         * in a box above the button, which named neither the field nor the
         * problem. The first name is still checked here because it is not an
         * email.
         *
         * THERE IS NO CHECK ON THE LAST NAME, deliberately. It is optional on
         * the label, in this function and in SFAF_RSVP::submit(), and a check
         * added here would be the one that quietly made it required.
         */
        if (!first) {
            $('#uc-rsvp-error').text('Please fill in your first name.').show();
            $('#uc-rsvp-first-name').focus();
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
                action:     'uc_submit_rsvp',
                nonce:      ucData.nonce,
                event_id:   currentEventId,
                first_name: first,
                last_name:  last,
                email:      email,
                phone:      phone,
                optin:      optin
            },
            success: function(response) {
                if (response.success) {
                    /*
                     * THE GREETING IS THE FIRST NAME, and it comes back from
                     * the server rather than being read off the field here.
                     * The server is what stored it and what the confirmation
                     * email greets from, so the screen and the email say the
                     * same word.
                     */
                    if (response.first_name) {
                        $('#uc-rsvp-success-msg').text('You are registered, ' + response.first_name + '!');
                    }

                    // Add to calendar, drawn only where there is somewhere to
                    // go. An event with no usable start time returns empty
                    // strings and gets no buttons rather than dead ones.
                    var anyCal = false;
                    if (response.gcal) {
                        $('#uc-rsvp-gcal').attr('href', response.gcal).show();
                        anyCal = true;
                    } else {
                        $('#uc-rsvp-gcal').hide();
                    }
                    if (response.ics) {
                        $('#uc-rsvp-ics').attr('href', response.ics).show();
                        anyCal = true;
                    } else {
                        $('#uc-rsvp-ics').hide();
                    }
                    $('#uc-rsvp-addcal').toggle(anyCal);

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
     * "Follow this series" dialog.
     *
     * IT SAYS WHAT ARRIVES BEFORE IT ASKS FOR ANYTHING. The dialog this
     * replaces was headed "Get Event Reminders", asked for an address and
     * explained nothing, so the one thing a person needed in order to decide —
     * what will be sent, and when — was the one thing it did not say. Every
     * line here is either that or an instruction.
     *
     * NOTHING IS ACTIVE UNTIL THE EMAIL IS ANSWERED, and the success panel says
     * so rather than claiming they are on a list. It also says the same thing
     * whatever the server did: sent, already following, or refused by a rate
     * limit all render this panel, because a different one for any of them
     * would answer "is that address known here".
     */
    function initFollow() {
        var modalHTML = '<dialog class="uc-rsvp-modal-overlay" id="uc-follow-modal">' +
            '<div class="uc-rsvp-modal">' +
                '<div class="uc-follow-form-view">' +
                    /* THE SERIES NAME GOES IN THE HEADING AND NOWHERE ELSE. It
                       used to be a subtitle under a generic "Follow this
                       series", which said the name twice over and led with the
                       half that carries no information. */
                    '<h3 id="uc-follow-heading"></h3>' +
                    '<p class="uc-follow-what">Be the first to know when new dates are added.</p>' +
                    '<div class="uc-rsvp-form">' +
                        '<div><label for="uc-follow-email">Email *</label>' +
                            '<input type="email" id="uc-follow-email" placeholder="your@email.com" /></div>' +
                        '<div class="uc-rsvp-error" id="uc-follow-error" style="display:none;"></div>' +
                        '<button class="uc-rsvp-submit uc-follow-submit" id="uc-follow-submit-btn">Yes, follow this series</button>' +
                        /* WHAT ARRIVES AND HOW TO STOP IT, under the button
                           rather than above the field: it is the reassurance
                           somebody wants at the moment of pressing, not a
                           condition they have to read before typing. */
                        '<p class="uc-follow-terms">One email when dates are added. Stop any time.</p>' +
                        '<button class="uc-rsvp-cancel" id="uc-follow-cancel-btn">Cancel</button>' +
                    '</div>' +
                '</div>' +
                '<div class="uc-rsvp-success" id="uc-follow-success" style="display:none;">' +
                    '<div class="uc-check">&#128276;</div>' +
                    '<p id="uc-follow-success-msg"></p>' +
                    '<button class="uc-rsvp-cancel" id="uc-follow-close-btn" style="margin-top: 16px;">Close</button>' +
                '</div>' +
            '</div>' +
        '</dialog>';

        $('body').append(modalHTML);
        var fmodal = $('#uc-follow-modal');
        fmodal.on('close', function () { fmodal.removeClass('active'); });
        var followSeriesId = null;

        $(document).on('click', '.uc-follow-btn', function(e) {
            e.preventDefault();
            followSeriesId = $(this).data('series-id');
            /* .text(), so a series called "Women & Trans Night" arrives as
               itself and a name is never markup. */
            var seriesName = $(this).data('series-name') || '';
            $('#uc-follow-heading').text(seriesName ? 'Follow ' + seriesName + '?' : 'Follow this series?');
            $('#uc-follow-email').val('');
            $('#uc-follow-error').hide();
            if (window.sfafEmail) { window.sfafEmail.clear(document.getElementById('uc-follow-email')); }
            $('#uc-follow-submit-btn').prop('disabled', false).text('Yes, follow this series');
            $('.uc-follow-form-view').show();
            $('#uc-follow-success').hide();
            openOverlay(fmodal);
        });

        fmodal.on('click', function(e) {
            if (e.target === this) closeOverlay(fmodal);
        });
        $(document).on('click', '#uc-follow-cancel-btn, #uc-follow-close-btn', function() {
            closeOverlay(fmodal);
        });
        $(document).on('keydown', function(e) {
            if (e.key === 'Escape') closeOverlay(fmodal);
        });

        function submitFollow() {
            var email = $('#uc-follow-email').val().trim();
            // Same shared validator as the RSVP form, so the two never disagree
            // about what counts as an address or how they say so.
            if (window.sfafEmail && !window.sfafEmail.validate(document.getElementById('uc-follow-email'))) {
                $('#uc-follow-error').hide();
                $('#uc-follow-email').focus();
                return;
            }
            if (!email) {
                $('#uc-follow-error').text('Please enter your email address.').show();
                return;
            }

            var btn = $('#uc-follow-submit-btn');
            btn.prop('disabled', true).text('Submitting...');
            $('#uc-follow-error').hide();

            $.ajax({
                url: ucData.ajaxUrl,
                method: 'POST',
                data: {
                    action:    'uc_follow_series',
                    nonce:     ucData.nonce,
                    series_id: followSeriesId,
                    email:     email
                },
                success: function(response) {
                    if (response.success) {
                        $('.uc-follow-form-view').hide();
                        $('#uc-follow-success-msg').text(response.message || "Almost there! Check your email to confirm and you're all set.");
                        $('#uc-follow-success').show();
                    } else {
                        $('#uc-follow-error').text(response.message || 'Something went wrong.').show();
                        btn.prop('disabled', false).text('Yes, follow this series');
                    }
                },
                error: function() {
                    $('#uc-follow-error').text('Network error. Please try again.').show();
                    btn.prop('disabled', false).text('Yes, follow this series');
                }
            });
        }

        $(document).on('click', '#uc-follow-submit-btn', submitFollow);
        fmodal.on('keydown', 'input', function(e) {
            if (e.key === 'Enter') submitFollow();
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

    /* -----------------------------------------------------------------------
     * THE MONTH GRID'S HOVER PREVIEW (3.75.0).
     *
     * A tile in the grid is a thumbnail, a title and a time. Hovering one shows
     * the rest: the picture, the date, the times and where it is, with a button
     * to the event itself.
     *
     * ---------------------------------------------------------------------
     * THE TOP LAYER, AND WHY NOT A NUMBER
     * ---------------------------------------------------------------------
     * 3.70.1 spent a release on this exact question for the registration
     * dialog. A div with `position: fixed` and a large z-index rendered UNDER
     * the theme's header on resources.sfaf.org, and no number we could write
     * would have fixed it: an ancestor had a transform, which makes it the
     * containing block for fixed descendants and traps every z-index inside its
     * own stacking context. The fix was to stop competing and use the browser's
     * top layer, which no stacking context can reach into.
     *
     * SO THIS USES THE TOP LAYER TOO, THROUGH THE POPOVER API rather than
     * showModal(). They are the two doors into the same layer and only one of
     * them is right here:
     *
     *   showModal()    top layer, but MODAL. Focus moves into it, the rest of
     *                  the page goes inert, and Escape closes it. Correct for
     *                  a registration form. Absurd for something that appears
     *                  because a mouse passed over a tile.
     *   showPopover()  top layer, NOT modal. The page stays live, focus stays
     *                  where it was, nothing goes inert.
     *
     * `popover="manual"` rather than "auto", because auto popovers light-dismiss
     * and close each other, and this one is opened and closed by pointer
     * intent rather than by clicks.
     *
     * NO FALLBACK, DELIBERATELY. A browser without showPopover() gets no
     * preview at all and keeps a tile that is still a link to the event. The
     * alternative is a z-index we already know can lose, on the one theme that
     * matters, in a way nobody would notice until somebody complained that the
     * preview was behind the header. An enhancement that is absent is honest;
     * one that renders underneath the page is not.
     *
     * ---------------------------------------------------------------------
     * DESKTOP ONLY, AND THAT IS A DECISION
     * ---------------------------------------------------------------------
     * There is no hover on a touch screen. A "hover" preview there fires on
     * tap, which means the first tap shows a panel and the second opens the
     * event, and a control that needs two taps where it used to need one is
     * worse than no control. So the whole thing is behind
     * `(hover: hover) and (pointer: fine)`, which asks the device what it can
     * do rather than guessing from the width, and a phone keeps exactly the
     * behaviour it has: tap a tile, open the event.
     *
     * ---------------------------------------------------------------------
     * THE DELAY
     * ---------------------------------------------------------------------
     * Moving a mouse diagonally across a month crosses a dozen tiles. Without a
     * delay that is a dozen previews, each fetching an image. OPEN_DELAY is the
     * pause before one appears, and CLOSE_DELAY is a shorter one before it goes,
     * so moving the pointer from the tile onto the panel itself does not close
     * the thing being reached for.
     * -------------------------------------------------------------------- */
    /* SFAF-PREVIEW-START
     *
     * EVERYTHING BETWEEN THESE TWO MARKERS EXISTS TWICE, BYTE FOR BYTE, IN
     * calendar.js AND embed.js, AND `.claude/hover-preview-test.php` FAILS IF
     * THE TWO COPIES DIVERGE BY A SINGLE CHARACTER.
     *
     * WHY IT HAS TO BE IN BOTH. 3.75.0 shipped this in calendar.js only, and
     * calendar.js is the shortcode's script. **The calendar has no front end on
     * resources.sfaf.org: it exists only as an embed on sfaf.org**, which runs
     * embed.js, a deliberately jQuery-free reimplementation of the handful of
     * interactions an embed needs. So the preview was correct, tested, and
     * never once executed anywhere anybody could see it. The month grid markup
     * was right the whole time, because both routes call the same
     * render_month_grid(); only the behaviour was missing.
     *
     * WHY A DUPLICATED BLOCK RATHER THAN A THIRD FILE. A shared file means a
     * second network request from a third-party page, injected by a script
     * that is already deriving one URL from its own src, with an ordering
     * question attached. This project has solved the same problem once before,
     * for the recurrence engine, which exists in PHP and in JavaScript and is
     * kept honest by a cross-check that slices the JS between markers. This is
     * that arrangement: one logical copy, enforced by the build rather than by
     * anybody remembering.
     *
     * SO IT USES NO jQUERY AND NOTHING FROM EITHER FILE'S SCOPE. Plain
     * addEventListener, plain closest(), and two constants of its own. If you
     * edit it here, the build will tell you to paste it there.
     */
    var PREVIEW_OPEN_DELAY  = 260;
    var PREVIEW_CLOSE_DELAY = 140;

    function initMonthPreview() {
        /* The device, asked rather than guessed. matchMedia is old enough to
           assume; if it is missing, so is any device this would suit. */
        if (!window.matchMedia || !window.matchMedia('(hover: hover) and (pointer: fine)').matches) {
            return;
        }

        var probe = document.createElement('div');
        if (typeof probe.showPopover !== 'function') {
            return; // See "NO FALLBACK, DELIBERATELY" above.
        }

        /* ONE PANEL FOR THE WHOLE PAGE, built once and refilled. A panel per
           tile would be sixty panels and sixty images on a busy month. */
        var panel = document.createElement('div');
        panel.className = 'uc-mp';
        panel.setAttribute('popover', 'manual');
        /* It describes the tile the pointer is on and is never focused, so it
           is decoration to a screen reader: the tile's own link already carries
           the title, the time and the date. */
        panel.setAttribute('aria-hidden', 'true');
        /*
         * TWO TARGETS: THE PICTURE AND THE BUTTON (3.78.0).
         *
         * THE ADDRESS WAS NEVER MISSING. The tile this previews IS an <a> with
         * the event's href on it, so the URL has always been on the element
         * fill() is handed. 3.76.0's fault was that nothing read it and the
         * panel was built entirely out of spans: a pill that looked like a
         * button, was not one, and had no destination behind it.
         *
         * 3.77.0 THEN MADE THE WHOLE PANEL ONE LINK, WHICH WAS THE WRONG
         * ANSWER AND IS WHY THIS NOTE IS LONGER THAN THE CODE. Wrapping
         * everything in an <a> meant the panel inherited link styling, and on
         * this theme that tinted the entire box teal: the date, the time and
         * the place all read as links because they were inside one. A preview
         * whose every line looks clickable says less than one with two things
         * that are.
         *
         * SO IT IS TWO, AND ONLY THOSE TWO. The picture is what somebody aims
         * at without thinking and the pill is what somebody aims at having
         * read it. The TITLE is not one, because a title that is a link in a
         * panel where the date beside it is not is the same confusion in
         * miniature, and the tile underneath is already a link on the title.
         *
         * WHAT SURVIVES FROM THE ONE-LINK VERSION IS ITS REASONING, AND ALL OF
         * IT STILL HOLDS:
         *
         *   BOTH DESTINATIONS COME OFF THE TILE, copied rather than
         *   re-derived, so two links cannot disagree with each other or with
         *   the tile about where they go or how they open.
         *
         *   NEITHER IS A TAB STOP. The panel is aria-hidden, and a focusable
         *   element inside an aria-hidden container is the worst of both:
         *   invisible to a screen reader and still a stop. A keyboard user tabs
         *   to the TILE, which opens this and carries the same destination, so
         *   a stop in here would be a second per event and sixty extra on a
         *   busy month. tabindex="-1" on both.
         */
        panel.innerHTML =
            '<a class="uc-mp-shot" tabindex="-1" href="#">' +
                '<span class="uc-mp-media"><img alt="" decoding="async" /></span>' +
            '</a>' +
            '<span class="uc-mp-body">' +
                '<span class="uc-mp-off"></span>' +
                '<span class="uc-mp-title"></span>' +
                '<span class="uc-mp-date"></span>' +
                '<span class="uc-mp-time"></span>' +
                '<span class="uc-mp-place"></span>' +
                '<a class="uc-mp-go" tabindex="-1" href="#">View Event Details</a>' +
            '</span>';
        document.body.appendChild(panel);

        /* The two targets, kept in one list so nothing can fill one and forget
           the other. */
        var links  = [ panel.querySelector('.uc-mp-shot'), panel.querySelector('.uc-mp-go') ];
        var img    = panel.querySelector('.uc-mp-media img');
        var media  = panel.querySelector('.uc-mp-shot');
        var off    = panel.querySelector('.uc-mp-off');
        var openT  = null;
        var closeT = null;
        var current = null;

        function fill(a) {
            /*
             * THE DESTINATION AND HOW IT OPENS BOTH COME OFF THE TILE, never
             * from anything this file decides. The tile carries target and rel
             * from sfaf_new_tab_attrs(), and a panel that opened in the same
             * tab while the tile opened a new one would be two answers to one
             * question. Copied rather than re-derived for that reason, and
             * BOTH TARGETS GET THE SAME THREE in one loop, so the picture and
             * the pill cannot go to different places or open differently.
             */
            links.forEach(function (link) {
                if (!link) { return; }
                link.setAttribute('href', a.getAttribute('href') || '#');
                copyAttr(a, link, 'target');
                copyAttr(a, link, 'rel');
            });

            var src = a.getAttribute('data-uc-pv-img') || '';
            if (src) {
                img.src = src;
                media.hidden = false;
            } else {
                /* No src at all rather than an empty one: setting src="" makes
                   a browser re-request the current document. */
                img.removeAttribute('src');
                media.hidden = true;
            }
            var cancelled = a.getAttribute('data-uc-pv-off') || '';
            off.textContent = cancelled;
            off.hidden = !cancelled;

            panel.querySelector('.uc-mp-title').textContent = a.getAttribute('data-uc-pv-title') || '';
            fillLine(panel.querySelector('.uc-mp-date'), a.getAttribute('data-uc-pv-date'));
            fillLine(panel.querySelector('.uc-mp-time'), a.getAttribute('data-uc-pv-time'));
            fillLine(panel.querySelector('.uc-mp-place'), a.getAttribute('data-uc-pv-place'));
        }

        /* An empty line is removed rather than left as an empty row, or an
           online event with no place would render a gap where an address goes. */
        function fillLine(el, text) {
            el.textContent = text || '';
            el.hidden = !text;
        }

        /* Copy an attribute, or take it off when the source has none. The
           second half matters: without it a tile that opens in a new tab would
           leave target="_blank" behind on the panel for the next tile that does
           not. */
        function copyAttr(from, to, name) {
            var value = from.getAttribute(name);
            if (null === value) {
                to.removeAttribute(name);
            } else {
                to.setAttribute(name, value);
            }
        }

        /*
         * WHERE IT GOES. Below the tile by default; above when there is not
         * room below; and pinned inside the viewport horizontally either way.
         *
         * THE BOTTOM ROW IS THE CASE THIS EXISTS FOR. A tile on the last week
         * of the month has the fold a few pixels under it, and a panel that
         * only ever opened downward would be off screen exactly where the
         * month is busiest.
         *
         * MEASURED AFTER THE PANEL IS SHOWN, NOT BEFORE. A popover has no size
         * until it is in the top layer, so it is shown first, measured, then
         * placed. It carries a class that keeps it invisible for that one
         * frame, or the first paint would be a flash in the corner.
         */
        function place(a) {
            var tile = a.getBoundingClientRect();
            var box  = panel.getBoundingClientRect();
            var gap  = 10;
            var edge = 8;

            var below = window.innerHeight - tile.bottom;
            var above = tile.top;
            var top;
            if (below >= box.height + gap + edge) {
                top = tile.bottom + gap;
                panel.classList.remove('is-above');
            } else if (above >= box.height + gap + edge) {
                top = tile.top - box.height - gap;
                panel.classList.add('is-above');
            } else {
                /* Neither side fits, which is a short window rather than a
                   bottom row. Sit it against the top edge and let it be beside
                   the tile rather than off screen. */
                top = Math.max(edge, Math.min(tile.top, window.innerHeight - box.height - edge));
                panel.classList.add('is-above');
            }

            var left = tile.left + (tile.width / 2) - (box.width / 2);
            left = Math.max(edge, Math.min(left, window.innerWidth - box.width - edge));

            panel.style.top  = Math.round(top) + 'px';
            panel.style.left = Math.round(left) + 'px';
        }

        function show(a) {
            current = a;
            fill(a);
            panel.classList.add('is-measuring');
            try {
                if (!panel.matches(':popover-open')) { panel.showPopover(); }
            } catch (e) {
                return; // Already open, or refused. Either way, nothing to do.
            }
            place(a);
            panel.classList.remove('is-measuring');
            panel.classList.add('is-open');
        }

        function hide() {
            current = null;
            panel.classList.remove('is-open');
            try {
                if (panel.matches(':popover-open')) { panel.hidePopover(); }
            } catch (e) { /* already closed */ }
        }

        function clearTimers() {
            if (openT) { window.clearTimeout(openT); openT = null; }
            if (closeT) { window.clearTimeout(closeT); closeT = null; }
        }

        function wantOpen(a) {
            clearTimers();
            if (current === a) { return; }
            openT = window.setTimeout(function () { show(a); }, PREVIEW_OPEN_DELAY);
        }

        function wantClose() {
            clearTimers();
            closeT = window.setTimeout(hide, PREVIEW_CLOSE_DELAY);
        }

        /* DELEGATED, so a month fetched by the view toggle or the arrows gets
           the behaviour without anything being rebound. mouseover rather than
           mouseenter because mouseenter does not bubble, and focusin/focusout
           rather than focus/blur for exactly the same reason.

           PLAIN addEventListener AND NO jQUERY, WHICH IS WHAT LETS THIS BLOCK
           EXIST IN BOTH SCRIPTS. embed.js has no jQuery on purpose, and the
           host page is somebody else's. See the marker note at the top. */
        function tileFrom(e) {
            var t = e.target;
            if (!t || typeof t.closest !== 'function') { return null; }
            return t.closest('.uc-day-event a[data-uc-preview]');
        }
        document.addEventListener('mouseover', function (e) {
            var tile = tileFrom(e);
            if (tile) { wantOpen(tile); }
        });
        document.addEventListener('mouseout', function (e) {
            if (tileFrom(e)) { wantClose(); }
        });
        /* The keyboard gets it too. Tabbing through a month is how somebody not
           using a mouse reads it, and there is no reason for them to have less.
           Focus is immediate: they asked for this tile. */
        document.addEventListener('focusin', function (e) {
            var tile = tileFrom(e);
            if (tile) { clearTimers(); show(tile); }
        });
        document.addEventListener('focusout', function (e) {
            if (tileFrom(e)) { wantClose(); }
        });

        /* Moving onto the panel keeps it; leaving it closes it. Without this,
           the panel closes as the pointer crosses the gap toward it. */
        panel.addEventListener('mouseover', clearTimers);
        panel.addEventListener('mouseout', wantClose);

        /* A scroll moves the tile out from under the panel, and a resize moves
           everything. Close rather than chase: the pointer is already somewhere
           else by the time either finishes. */
        window.addEventListener('scroll', function () { clearTimers(); hide(); }, true);
        window.addEventListener('resize', function () { clearTimers(); hide(); });
    }
    /* SFAF-PREVIEW-END */

})(jQuery);
