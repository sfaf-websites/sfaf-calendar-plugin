/**
 * SFAF Calendar — embed script.
 *
 * Served from the calendar site and loaded by a block on any other site:
 *
 *   <div class="sfaf-calendar-embed" data-sfaf-calendar
 *        data-category="support-groups"
 *        data-per-page="12"
 *        data-show-filters="yes"></div>
 *   <script src="https://example.org/wp-content/plugins/sfaf-calendar/public/js/embed.js" async></script>
 *
 * It fetches rendered HTML from the public embed endpoint and drops it in, so
 * the cards are the same markup the calendar site renders for itself.
 *
 * No jQuery. The host site is someone else's, and assuming a library is there
 * is a good way to break on the one site that matters. The calendar's own
 * calendar.js is jQuery-based and deliberately not reused here; the handful of
 * interactions an embed needs — load more, paging, filtering, search, the
 * add-to-calendar menu — are re-implemented below against the DOM directly.
 *
 * RSVP and reminder signup are not handled here at all. Those post to
 * admin-ajax with a WordPress nonce, which cannot be obtained or validated from
 * another origin, so the server renders them as links to the event page instead.
 */
(function () {
    'use strict';

    if (window.sfafCalendarEmbed) {
        // A second copy of the tag — one per block is the normal case. Pick up
        // any blocks the first copy has not claimed and stop, rather than
        // binding a duplicate set of handlers to the ones already running.
        window.sfafCalendarEmbed.scan();
        return;
    }

    /* -----------------------------------------------------------------------
     * Where we came from
     *
     * Captured immediately: document.currentScript is only meaningful while the
     * script is executing, and everything below needs to know which site served
     * it so it can call home for events, styles and links.
     * -------------------------------------------------------------------- */

    var SCRIPT = document.currentScript || (function () {
        var all = document.getElementsByTagName('script');
        return all[all.length - 1];
    })();

    var SRC = (SCRIPT && SCRIPT.src) || '';

    /**
     * The WordPress root of the site that served this file. Taken from the path
     * above /wp-content/ so a subdirectory install works; falls back to the
     * origin if the plugin has been moved somewhere unusual.
     */
    var ROOT = (function (src) {
        var marker = '/wp-content/';
        var at = src.indexOf(marker);
        if (at > -1) {
            return src.slice(0, at);
        }
        var a = document.createElement('a');
        a.href = src || '/';
        return a.protocol + '//' + a.host;
    })(SRC);

    /** The plugin stylesheet that goes with this script, version query and all. */
    var CSS_URL = /\/js\/embed\.js(\?.*)?$/.test(SRC)
        ? SRC.replace(/\/js\/embed\.js(\?.*)?$/, '/css/calendar.css$1')
        : '';

    /**
     * Endpoints to try, in order. Pretty permalinks give the first one; the
     * second is the same route on a site with plain permalinks. Whichever
     * answers first is remembered for the rest of the page.
     */
    var ENDPOINTS = [
        ROOT + '/wp-json/sfaf-calendar/v1/embed',
        ROOT + '/?rest_route=/sfaf-calendar/v1/embed'
    ];
    var workingEndpoint = null;

    /** Hostname of the calendar site, for the "view the calendar" fallback link. */
    var HOST = (function () {
        var a = document.createElement('a');
        a.href = ROOT || '/';
        return a.hostname;
    })();

    /* -----------------------------------------------------------------------
     * Requests
     * -------------------------------------------------------------------- */

    /**
     * The filters a block asks for, read off its data attributes. Attribute
     * names match the shortcode's attribute names on purpose.
     */
    function paramsFor(container, page, mode) {
        var params = {
            category: container.getAttribute('data-category') || '',
            organizer: container.getAttribute('data-organizer') || '',
            series: container.getAttribute('data-series') || '',
            venue: container.getAttribute('data-venue') || '',
            per_page: container.getAttribute('data-per-page') || '',
            show_filters: container.getAttribute('data-show-filters') || '',
            layout: container.getAttribute('data-layout') || '',
            page: page || 1,
            mode: mode || ''
        };

        var query = [];
        for (var key in params) {
            if (Object.prototype.hasOwnProperty.call(params, key) && params[key] !== '') {
                query.push(encodeURIComponent(key) + '=' + encodeURIComponent(params[key]));
            }
        }
        return query.join('&');
    }

    /**
     * GET the endpoint. XMLHttpRequest rather than fetch: it is available
     * everywhere without a polyfill, and it sends no credentials by default,
     * which is what keeps the wide-open CORS header on the other end valid.
     *
     * @param {string}   url     Endpoint, without the query string.
     * @param {string}   query   Encoded query string.
     * @param {Function} onDone  Called with the parsed payload.
     * @param {Function} onFail  Called with no arguments.
     */
    function get(url, query, onDone, onFail) {
        var xhr = new XMLHttpRequest();
        var join = url.indexOf('?') > -1 ? '&' : '?';

        try {
            xhr.open('GET', url + join + query, true);
        } catch (e) {
            onFail();
            return;
        }

        xhr.onload = function () {
            if (xhr.status < 200 || xhr.status >= 300) {
                onFail();
                return;
            }
            var data;
            try {
                data = JSON.parse(xhr.responseText);
            } catch (e) {
                onFail();
                return;
            }
            if (!data || typeof data.html !== 'string') {
                onFail();
                return;
            }
            onDone(data);
        };
        xhr.onerror = function () { onFail(); };
        xhr.ontimeout = function () { onFail(); };
        xhr.send();
    }

    /**
     * Ask for a page of events, falling back to the alternate endpoint shape
     * once before giving up.
     */
    function request(container, page, mode, onDone, onFail) {
        var override = container.getAttribute('data-endpoint') ||
            (SCRIPT && SCRIPT.getAttribute('data-endpoint')) || '';
        var candidates = override ? [override] : (workingEndpoint ? [workingEndpoint] : ENDPOINTS.slice());
        var query = paramsFor(container, page, mode);

        (function attempt(index) {
            if (index >= candidates.length) {
                onFail();
                return;
            }
            get(candidates[index], query, function (data) {
                workingEndpoint = candidates[index];
                onDone(data);
            }, function () {
                attempt(index + 1);
            });
        })(0);
    }

    /* -----------------------------------------------------------------------
     * Assets and states
     * -------------------------------------------------------------------- */

    /** Load the calendar stylesheet once, however many blocks are on the page. */
    function ensureStylesheet() {
        if (!CSS_URL || document.querySelector('link[data-sfaf-calendar-css]')) {
            return;
        }
        var link = document.createElement('link');
        link.rel = 'stylesheet';
        link.href = CSS_URL;
        link.setAttribute('data-sfaf-calendar-css', '1');
        (document.head || document.getElementsByTagName('head')[0]).appendChild(link);
    }

    function setLoading(container) {
        var box = document.createElement('div');
        box.className = 'sfaf-embed-loading';
        box.setAttribute('style', 'padding:24px 0;color:#6B7280;font-size:14px;');
        box.textContent = 'Loading events…';
        container.innerHTML = '';
        container.appendChild(box);
    }

    /**
     * Fail visibly. An empty gap on someone else's page reads as a broken site,
     * so say what happened and offer the calendar itself.
     *
     * Styles are inline because the most likely reason we are here is that the
     * calendar site is unreachable — in which case its stylesheet never loaded.
     */
    function showError(container) {
        var url = container.getAttribute('data-calendar-url') || (ROOT + '/events/');

        var box = document.createElement('div');
        box.className = 'sfaf-embed-error';
        box.setAttribute('style', 'padding:20px;border:1px solid #D1D3D4;border-radius:12px;' +
            'font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,Helvetica,Arial,sans-serif;' +
            'font-size:14px;line-height:1.5;color:#373433;');

        var message = document.createElement('p');
        message.setAttribute('style', 'margin:0 0 8px;');
        message.textContent = 'The events calendar could not be loaded just now.';

        var wrap = document.createElement('p');
        wrap.setAttribute('style', 'margin:0;');
        var link = document.createElement('a');
        link.href = url;
        link.setAttribute('style', 'color:#0E818C;font-weight:600;');
        link.textContent = 'View upcoming events on ' + HOST + ' →';
        wrap.appendChild(link);

        box.appendChild(message);
        box.appendChild(wrap);

        container.innerHTML = '';
        container.appendChild(box);
    }

    /* -----------------------------------------------------------------------
     * Loading pages
     * -------------------------------------------------------------------- */

    /** The rendered calendar inside a block, once the server markup has landed. */
    function inner(container) {
        return container.querySelector('.uc-calendar, .uc-upcoming-widget');
    }

    /** The element cards live in. */
    function listOf(container) {
        return container.querySelector('.uc-event-list, .uc-upcoming-list');
    }

    /** Replace the whole block (first load, and numbered page navigation). */
    function loadBlock(container, page, scrollIntoView) {
        request(container, page, 'block', function (data) {
            container.innerHTML = data.html;
            // The block arrives with a fresh filter bar — "All Events" active,
            // search box empty — so the remembered filter state resets with it.
            container.setAttribute('data-active-category', 'all');
            container.setAttribute('data-active-search', '');
            bind(container);
            if (scrollIntoView) {
                var top = container.getBoundingClientRect().top;
                if (top < 0) {
                    container.scrollIntoView();
                }
            }
        }, function () {
            showError(container);
        });
    }

    /** Fetch the next page and append it (load more, infinite scroll). */
    function loadMore(container, trigger) {
        var block = inner(container);
        var list = listOf(container);
        if (!block || !list || block.getAttribute('data-loading') === '1') {
            return;
        }

        var page = parseInt(block.getAttribute('data-page') || '1', 10);
        var maxPages = parseInt(block.getAttribute('data-max-pages') || '1', 10);
        var next = page + 1;

        if (next > maxPages) {
            removePagination(container);
            return;
        }

        block.setAttribute('data-loading', '1');
        var isButton = trigger && trigger.tagName === 'BUTTON';
        if (isButton) {
            trigger.disabled = true;
            trigger.textContent = 'Loading…';
        }

        request(container, next, 'items', function (data) {
            block.setAttribute('data-loading', '');

            var holder = document.createElement('div');
            holder.innerHTML = data.html;
            while (holder.firstChild) {
                list.appendChild(holder.firstChild);
            }
            block.setAttribute('data-page', String(next));

            // Newly appended cards must obey the filter that is already on.
            applyFilters(container);

            if (!data.has_more || next >= maxPages) {
                removePagination(container);
            } else if (isButton) {
                trigger.disabled = false;
                trigger.textContent = 'Load More';
            }
        }, function () {
            block.setAttribute('data-loading', '');
            if (isButton) {
                trigger.disabled = false;
                trigger.textContent = 'Load More';
            }
        });
    }

    function removePagination(container) {
        var pagination = container.querySelector('.uc-pagination');
        if (pagination && pagination.parentNode) {
            pagination.parentNode.removeChild(pagination);
        }
    }

    /* -----------------------------------------------------------------------
     * Interaction inside a block
     *
     * Everything is scoped to one container, so several blocks on a page filter
     * and page independently of each other.
     * -------------------------------------------------------------------- */

    /**
     * Wire up the controls in freshly rendered markup.
     *
     * Everything bound here belongs to elements that are replaced wholesale on
     * the next render, so re-running it cannot double-bind. Anything that has to
     * survive a render — or reach cards appended later — is delegated from the
     * container in init() instead.
     */
    function bind(container) {
        var block = inner(container);
        if (!block) {
            return;
        }

        bindPagination(container, block);
        bindFilters(container);
        bindSearch(container);
    }

    function bindPagination(container, block) {
        var loadMoreBtn = container.querySelector('.uc-load-more');
        if (loadMoreBtn) {
            loadMoreBtn.addEventListener('click', function () {
                loadMore(container, loadMoreBtn);
            });
        }

        // Numbered pages: the server rendered these as buttons carrying the
        // page number, because a link would point at the REST URL.
        var pageButtons = container.querySelectorAll('.uc-pagination-pages button[data-page]');
        for (var i = 0; i < pageButtons.length; i++) {
            (function (button) {
                button.addEventListener('click', function () {
                    loadBlock(container, parseInt(button.getAttribute('data-page'), 10) || 1, true);
                });
            })(pageButtons[i]);
        }

        var sentinel = container.querySelector('.uc-infinite-sentinel');
        if (sentinel && 'IntersectionObserver' in window) {
            var observer = new IntersectionObserver(function (entries) {
                for (var j = 0; j < entries.length; j++) {
                    if (entries[j].isIntersecting) {
                        loadMore(container, sentinel);
                    }
                }
            }, { rootMargin: '300px' });
            observer.observe(sentinel);
        }
    }

    /** Every card in a block, whichever layout it was rendered in. */
    function cardsIn(container) {
        return container.querySelectorAll('.uc-event-card, .uc-compact-card');
    }

    /**
     * The text a search looks through: title, summary and meta line, the same
     * fields the calendar site searches. Falls back to the whole card if the
     * markup ever changes shape.
     */
    function searchTextOf(card) {
        var parts = card.querySelectorAll(
            '.uc-card-title, .uc-card-excerpt, .uc-card-meta, .uc-compact-title, .uc-compact-meta'
        );
        if (!parts.length) {
            return card.textContent || '';
        }
        var text = '';
        for (var i = 0; i < parts.length; i++) {
            text += ' ' + (parts[i].textContent || '');
        }
        return text;
    }

    /**
     * Show only the cards matching the active category and search text.
     *
     * Client-side, over cards that are already on the page — the same thing the
     * filter buttons do on the calendar site, so the behaviour matches.
     */
    function applyFilters(container) {
        var category = container.getAttribute('data-active-category') || 'all';
        var term = (container.getAttribute('data-active-search') || '').toLowerCase();
        var cards = cardsIn(container);
        var visible = 0;

        for (var i = 0; i < cards.length; i++) {
            var card = cards[i];
            var matchesCategory = (category === 'all') || (card.getAttribute('data-category') === category);
            var matchesTerm = (term === '') || (searchTextOf(card).toLowerCase().indexOf(term) > -1);
            var show = matchesCategory && matchesTerm;

            card.style.display = show ? '' : 'none';
            if (show) {
                visible++;
            }
        }

        var count = container.querySelector('.uc-count-number');
        if (count) {
            count.textContent = String(visible);
        }
    }

    function bindFilters(container) {
        var buttons = container.querySelectorAll('.uc-filter-btn');
        for (var i = 0; i < buttons.length; i++) {
            (function (button) {
                button.addEventListener('click', function () {
                    for (var j = 0; j < buttons.length; j++) {
                        buttons[j].classList.remove('active');
                    }
                    button.classList.add('active');
                    container.setAttribute('data-active-category', button.getAttribute('data-category') || 'all');
                    applyFilters(container);
                });
            })(buttons[i]);
        }
    }

    function bindSearch(container) {
        var input = container.querySelector('.uc-search');
        if (!input) {
            return;
        }
        var timer = null;
        input.addEventListener('input', function () {
            clearTimeout(timer);
            timer = setTimeout(function () {
                container.setAttribute('data-active-search', input.value);
                applyFilters(container);
            }, 200);
        });
    }

    /**
     * Walk up from an event target to the add-to-calendar button it sits in.
     * The click often lands on the SVG icon inside the button, not the button.
     */
    function toggleFor(node, container) {
        while (node && node !== container) {
            if (node.classList && node.classList.contains('uc-addcal-toggle')) {
                return node;
            }
            node = node.parentNode;
        }
        return null;
    }

    /**
     * The "Add to Calendar" dropdown on each card.
     *
     * Delegated from the container and bound once, so it also covers cards that
     * arrive later from load more or infinite scroll.
     */
    function bindAddToCalendar(container) {
        container.addEventListener('click', function (event) {
            var toggle = toggleFor(event.target, container);
            if (!toggle) {
                return;
            }
            event.preventDefault();
            event.stopPropagation();

            var menu = toggle.parentNode;
            var open = menu.classList.contains('open');
            closeMenus();
            if (!open) {
                menu.classList.add('open');
            }
        });
    }

    function closeMenus() {
        var open = document.querySelectorAll('[data-sfaf-calendar] .uc-addcal.open');
        for (var i = 0; i < open.length; i++) {
            open[i].classList.remove('open');
        }
    }

    document.addEventListener('click', closeMenus);

    /* -----------------------------------------------------------------------
     * Start-up
     * -------------------------------------------------------------------- */

    function init(container) {
        if (container.getAttribute('data-sfaf-ready') === '1') {
            return;
        }
        container.setAttribute('data-sfaf-ready', '1');
        container.setAttribute('data-active-category', 'all');
        container.setAttribute('data-active-search', '');

        // Bound to the container itself, once, so it survives every re-render.
        bindAddToCalendar(container);

        ensureStylesheet();
        setLoading(container);
        loadBlock(container, 1, false);
    }

    /**
     * Find blocks that have not been set up yet. Safe to call repeatedly, which
     * is what makes a second copy of the script tag harmless and lets a host
     * page start a block it added after load.
     */
    function scan() {
        var blocks = document.querySelectorAll('[data-sfaf-calendar]');
        for (var i = 0; i < blocks.length; i++) {
            init(blocks[i]);
        }
    }

    window.sfafCalendarEmbed = { scan: scan };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', scan);
    } else {
        scan();
    }
})();
