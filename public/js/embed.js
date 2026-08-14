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

    /**
     * The plugin stylesheet that goes with this script.
     *
     * THE QUERY STRING IS DELIBERATELY DROPPED, and that is the bug fix.
     *
     * Until 2.10.1 this carried the script's own ?ver= across:
     *
     *     SRC.replace(/\/js\/embed\.js(\?.*)?$/, '/css/calendar.css$1')
     *
     * The embed snippet is copy-pasted HTML on someone else's page, and it was
     * generated with ?ver=<plugin version> baked in. That pin never changes
     * again, so the host page kept asking for calendar.css?ver=2.9.0 long after
     * the plugin had moved on, and the browser kept serving the copy it had
     * cached under that exact URL. The HTML came from the server and was
     * current; the stylesheet was months old. That is how the month grid
     * arrived as unstyled markup: every rule for it lives in a stylesheet the
     * page was pinned away from.
     *
     * Unpinned, the browser revalidates normally and the stylesheet follows the
     * plugin. The server also sends the authoritative URL in every payload, so
     * even this derivation is only a first guess. See adoptStylesheet().
     */
    var CSS_URL = /\/js\/embed\.js(\?.*)?$/.test(SRC)
        ? SRC.replace(/\/js\/embed\.js(\?.*)?$/, '/css/calendar.css')
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
    function paramsFor(container, page, mode, extra) {
        var params = {
            // data-category is the SNIPPET's scope, written by whoever pasted
            // the embed, and is never changed from here. data-active-category is
            // what the visitor picked from the filter bar. The endpoint clamps
            // the second to the first, so a chip can narrow this block and can
            // never widen it past what its author chose.
            category: container.getAttribute('data-category') || '',
            active_category: activeCategory(container),
            // The second-level choice, clamped at the endpoint against what this
            // snippet's own scope actually contains. See effective_groups().
            active_groups: container.getAttribute('data-active-groups') || '',
            organizer: container.getAttribute('data-organizer') || '',
            series: container.getAttribute('data-series') || '',
            venue: container.getAttribute('data-venue') || '',
            per_page: container.getAttribute('data-per-page') || '',
            show_filters: container.getAttribute('data-show-filters') || '',
            layout: container.getAttribute('data-layout') || '',
            view: viewFor(container),
            toggle: container.getAttribute('data-toggle') || '',
            // Absent means "the shipped default", and an empty string is how
            // that is said to the endpoint. A block pasted before this existed
            // therefore follows the default forever, including after somebody
            // flips it, rather than being frozen at what it was when pasted.
            source_links: container.getAttribute('data-source-links') || '',
            count: container.getAttribute('data-count') || '',
            // The active search, so every request a block makes after one has
            // been typed keeps it: load more, page two, month navigation.
            s: container.getAttribute('data-active-search') || '',
            page: page || 1,
            mode: mode || ''
        };

        /*
         * THE HEADING IS ADDED ONLY WHEN THE BLOCK CARRIES THE ATTRIBUTE.
         *
         * getAttribute returns null for an attribute that is not there and ''
         * for data-heading="", and those are two different instructions: the
         * first means "you decide" and the second means "no heading". Every
         * embed pasted before 3.18.0 is the first case and has to keep its
         * default. So the key is not set at all unless the attribute exists,
         * and the empty case is carried through by the exception below.
         */
        var heading = container.getAttribute('data-heading');
        if (heading !== null) {
            params.heading = heading;
        }

        if (extra) {
            for (var k in extra) {
                if (Object.prototype.hasOwnProperty.call(extra, k)) {
                    params[k] = extra[k];
                }
            }
        }

        // Empty values are dropped, because every other parameter treats empty
        // as "not set". heading is the one that does not: an empty heading is a
        // deliberate instruction and has to reach the endpoint to be obeyed.
        var sendWhenEmpty = { heading: true };

        var query = [];
        for (var key in params) {
            if (!Object.prototype.hasOwnProperty.call(params, key)) { continue; }
            if (params[key] === '' && !sendWhenEmpty[key]) { continue; }
            query.push(encodeURIComponent(key) + '=' + encodeURIComponent(params[key]));
        }
        return query.join('&');
    }

    /* -----------------------------------------------------------------------
     * Remembering the visitor's chosen view
     *
     * SCOPED PER BLOCK. Two embeds on one page are usually two different
     * programmes, and choosing Calendar on one must not silently flip the
     * other. The key is built from what makes a block distinct — its filters
     * and configured mode — so the same block on the same page keeps its
     * setting across visits while a different block keeps its own.
     *
     * Every storage call is wrapped: Safari in private mode throws on
     * localStorage, and a thrown exception here would stop the block rendering
     * at all. A forgotten preference is not worth a blank box on someone
     * else's page.
     * -------------------------------------------------------------------- */

    function viewKey(container) {
        return 'sfafView:' + [
            container.getAttribute('data-category') || '',
            container.getAttribute('data-organizer') || '',
            container.getAttribute('data-series') || '',
            container.getAttribute('data-venue') || '',
            container.getAttribute('data-view') || ''
        ].join('|');
    }

    function storedView(container) {
        try {
            var v = window.localStorage.getItem(viewKey(container));
            return (v === 'list' || v === 'calendar') ? v : '';
        } catch (e) {
            return '';
        }
    }

    function rememberView(container, view) {
        try {
            window.localStorage.setItem(viewKey(container), view);
        } catch (e) { /* private mode: not worth failing over */ }
    }

    /**
     * The view a block should open in.
     *
     * Sidebar is a configuration, not a visitor choice, so it is never
     * overridden. Otherwise a remembered choice wins over the configured
     * default, which itself defaults to list: real months have entire weeks
     * with no Friday or Sunday events, and an empty-looking grid is a poor
     * first impression.
     */
    function viewFor(container) {
        var configured = container.getAttribute('data-view') || 'list';
        if (configured === 'sidebar') {
            return 'sidebar';
        }
        /*
         * COMBINED IS A CONFIGURATION, LIKE SIDEBAR, AND IS NEVER OVERRIDDEN.
         *
         * A remembered choice comes from pressing the view toggle, and the
         * combined mode has no toggle: both views are on screen. So there is
         * nothing a visitor could have chosen here, and letting a "calendar"
         * left in localStorage by another block on the same site collapse this
         * one to a single panel would take away the layout somebody picked.
         */
        if (configured === 'combined') {
            return 'combined';
        }
        return storedView(container) || (configured === 'calendar' ? 'calendar' : 'list');
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

        // Without an explicit timeout the ontimeout handler below can never
        // fire: a request that is blackholed rather than refused (a proxy that
        // swallows it, a captive portal, a firewalled origin) would leave
        // "Loading events…" on someone else's page for as long as the tab is
        // open. Fail over to the next endpoint, then to the error box, instead.
        xhr.timeout = 15000;

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
    function request(container, page, mode, onDone, onFail, extra) {
        var override = container.getAttribute('data-endpoint') ||
            (SCRIPT && SCRIPT.getAttribute('data-endpoint')) || '';
        var candidates = override ? [override] : (workingEndpoint ? [workingEndpoint] : ENDPOINTS.slice());
        var query = paramsFor(container, page, mode, extra);

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

    /**
     * Take the stylesheet URL the SERVER says is current.
     *
     * The URL derived from this script's own src is a guess made by a file that
     * may itself be a cached old copy. The payload is generated by the running
     * plugin, so its css_url is the only value that is definitely right. If the
     * two differ, the server wins.
     *
     * Loaded as a second <link> rather than by rewriting the first: swapping an
     * href that is already applied causes a visible flash of unstyled content,
     * where appending simply layers the correct rules on top and the older link
     * becomes a no-op.
     */
    function adoptStylesheet(url) {
        if (!url || url === CSS_URL) {
            return;
        }
        if (document.querySelector('link[data-sfaf-calendar-css="server"]')) {
            return;
        }
        var link = document.createElement('link');
        link.rel = 'stylesheet';
        link.href = url;
        link.setAttribute('data-sfaf-calendar-css', 'server');
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
        return container.querySelector('.uc-calendar, .uc-upcoming-widget, .uc-sidebar');
    }

    /** The element cards live in. */
    function listOf(container) {
        return container.querySelector('.uc-event-list, .uc-upcoming-list');
    }

    /**
     * Apply the calendar site's card style to the block.
     *
     * On the calendar site this arrives as a body class (sfaf_body_class). The
     * host page's <body> is not ours to touch, so the style rides in the payload
     * and goes on the block instead — the CSS rules are descendant selectors
     * (.sfaf-card-minimal .uc-event-card), so they match from here just as well.
     */
    function applyCardStyle(container, style) {
        var classes = container.className.split(/\s+/);
        var kept = [];
        for (var i = 0; i < classes.length; i++) {
            if (classes[i] && classes[i].indexOf('sfaf-card-') !== 0) {
                kept.push(classes[i]);
            }
        }
        if (style && /^[A-Za-z0-9_-]+$/.test(style)) {
            kept.push('sfaf-card-' + style);
        }
        container.className = kept.join(' ');
    }

    /** Replace the whole block (first load, and numbered page navigation). */
    function loadBlock(container, page, scrollIntoView) {
        request(container, page, 'block', function (data) {
            adoptStylesheet(data.css_url);
            container.innerHTML = data.html;
            applyCardStyle(container, data.card_style);
            /*
             * The block arrives already showing what was asked for, so the
             * state is NOT reset here any more. It used to be, because the only
             * way a block was re-fetched was a first load; a group choice
             * re-fetches it too, and clearing the attributes would have thrown
             * away the choice that caused the request one line after the server
             * honoured it. The search box is the exception: the fresh markup
             * carries the term the request was made with, so the attribute
             * already agrees with what is on screen.
             */
            bind(container);
            // Warm the neighbouring months once the block is on screen, so the
            // first arrow click is instant. After first paint, never before.
            var grid = gridOf(container);
            if (grid) {
                prefetchAround(container, grid.getAttribute('data-month') || '');
            }
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
            // The new cards were not in the document when bind() ran, so they
            // have never been observed. revealCards() skips anything already
            // carrying .uc-reveal, so this only ever reaches the new ones.
            revealCards(container);

            // Nothing to reapply: the request already carried the chosen
            // category, so what just landed is page N of the filtered set.
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
        bindViewToggle(container, block);
        bindMonth(container);
        revealCards(container);
    }

    /* -----------------------------------------------------------------------
     * ENTRANCE: each list card as it reaches the viewport.
     *
     * THE TWIN OF initReveal() IN calendar.js. Two runtimes render the same
     * markup, this file on somebody else's page, that one on the calendar site
     *, and neither can load the other. The behaviour is deliberately identical
     * and the CSS is literally the same file (calendar.css, adopted by
     * adoptStylesheet), so a change to the look is one edit; a change to WHEN it
     * fires is two, and they are cross-referenced.
     *
     * THIS BLOCK LIVES INSIDE SFAF.ORG'S PAGE AND MUST NOT FIGHT IT. An
     * IntersectionObserver is a private object: no global handler, no scroll
     * listener, no shared registry. A host theme running AOS or its own reveal
     * script cannot see this one and this one cannot see it. The class name
     * .uc-reveal is ours and appears nowhere in the host's stylesheet, and the
     * rules that use it are scoped under .uc-calendar.
     *
     * DEGRADES TO VISIBLE, ALWAYS. .uc-reveal, the class that hides, is only
     * ever added on the line before the element is observed. No observer, no
     * class, no hiding: reduced motion returns early, a browser without
     * IntersectionObserver returns early, and a card added after the block was
     * bound is simply visible. There is no arrangement in which a card ends up
     * invisible with nothing left to reveal it.
     *
     * LIST VIEW ONLY. .uc-event-list is the list panel's container. The month
     * grid is a table of 42 cells and staggering it would look like a fault.
     * -------------------------------------------------------------------- */
    function revealCards(container) {
        // Which branch ran, on the block rather than on <html>: this is
        // somebody else's page and the root element is not ours to stamp.
        // Inspect the embed container and it says on, reduced or no-observer.
        var reduced = !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
        container.setAttribute('data-uc-motion', reduced ? 'reduced' : 'on');

        if (reduced) {
            return;
        }
        if (!('IntersectionObserver' in window)) {
            container.setAttribute('data-uc-motion', 'no-observer');
            return;
        }

        var cards = container.querySelectorAll('.uc-event-list .uc-event-card');
        if (!cards.length) {
            return;
        }

        // ONCE PER CARD: unobserved on the first crossing, so scrolling back up
        // a long list does not replay anything.
        var observer = new IntersectionObserver(function (entries, obs) {
            for (var i = 0; i < entries.length; i++) {
                if (!entries[i].isIntersecting) { continue; }
                entries[i].target.classList.add('is-in');
                obs.unobserve(entries[i].target);
            }
        }, { rootMargin: '0px 0px -40px 0px', threshold: 0.01 });

        for (var j = 0; j < cards.length; j++) {
            if (cards[j].classList.contains('uc-reveal')) { continue; }
            cards[j].classList.add('uc-reveal');
            observer.observe(cards[j]);
        }
    }

    /* -----------------------------------------------------------------------
     * View toggle
     * -------------------------------------------------------------------- */

    function panelOf(container, name) {
        return container.querySelector('.uc-panel-' + name);
    }

    /** Show one panel, hide the other, and keep the buttons in step. */
    function showView(container, view, remember) {
        var block = inner(container);
        if (!block) {
            return;
        }

        var list = panelOf(container, 'list');
        var cal = panelOf(container, 'calendar');
        if (list) { list.hidden = (view !== 'list'); }
        if (cal) { cal.hidden = (view !== 'calendar'); }

        block.setAttribute('data-view', view);
        block.className = block.className.replace(/\buc-view-(list|calendar)\b/g, '').trim() + ' uc-view-' + view;

        // aria-pressed is the state a screen reader announces, so it has to
        // move with the visual active class rather than being set once.
        var buttons = container.querySelectorAll('.uc-view-btn');
        for (var i = 0; i < buttons.length; i++) {
            var on = (buttons[i].getAttribute('data-view') === view);
            buttons[i].classList.toggle('active', on);
            buttons[i].setAttribute('aria-pressed', on ? 'true' : 'false');
        }

        if (remember) {
            rememberView(container, view);
        }
        if (view === 'calendar') {
            syncDayPanel(container);
        }
    }

    function bindViewToggle(container, block) {
        var buttons = container.querySelectorAll('.uc-view-btn');
        for (var i = 0; i < buttons.length; i++) {
            (function (button) {
                button.addEventListener('click', function () {
                    showView(container, button.getAttribute('data-view') || 'list', true);
                });
            })(buttons[i]);
        }
        // The server rendered whichever view it was told to; a remembered
        // choice may differ, and this is where the two are reconciled.
        showView(container, viewFor(container), false);
    }

    /* -----------------------------------------------------------------------
     * Month grid
     * -------------------------------------------------------------------- */

    function gridOf(container) {
        return container.querySelector('.uc-month');
    }

    /** Prefetched month HTML, keyed by block and month. */
    var monthCache = {};

    function monthCacheKey(container, month) {
        // The chosen category belongs in the key: without it, filtering and then
        // returning would serve the other category's grid out of this cache.
        return viewKey(container) + '#' + activeCategory(container) + '#' + month;
    }

    /**
     * Swap in a month's grid.
     *
     * A VISIBLE loading state, unlike the silent prefetch: this one is a
     * response to a click, so the visitor is waiting and should be told.
     */
    function loadMonth(container, month) {
        var grid = gridOf(container);
        var panel = panelOf(container, 'calendar');
        if (!panel || !month) {
            return;
        }

        var cached = monthCache[monthCacheKey(container, month)];
        if (cached) {
            panel.innerHTML = cached;
            bindMonth(container);
            prefetchAround(container, month);
            return;
        }

        if (grid) {
            grid.classList.add('uc-month-loading');
            grid.setAttribute('aria-busy', 'true');
        }

        request(container, 1, 'month', function (data) {
            panel.innerHTML = data.html;
            monthCache[monthCacheKey(container, month)] = data.html;
            bindMonth(container);
            prefetchAround(container, month);
        }, function () {
            // FAILURE MUST NOT LOOK LIKE AN EMPTY MONTH. A blank grid is
            // indistinguishable from a month with nothing on, which is common
            // in real data, so say what happened and offer a way to retry.
            var g = gridOf(container);
            if (g) {
                g.classList.remove('uc-month-loading');
                g.removeAttribute('aria-busy');
            }
            showMonthError(container, month);
        }, { month: month });
    }

    function showMonthError(container, month) {
        var grid = gridOf(container);
        if (!grid) {
            return;
        }
        var old = grid.querySelector('.uc-month-error');
        if (old && old.parentNode) {
            old.parentNode.removeChild(old);
        }

        var box = document.createElement('div');
        box.className = 'uc-month-error';
        box.setAttribute('role', 'alert');

        var text = document.createElement('span');
        text.textContent = 'That month could not be loaded.';
        box.appendChild(text);

        var retry = document.createElement('button');
        retry.type = 'button';
        retry.className = 'uc-month-retry';
        retry.textContent = 'Try again';
        retry.addEventListener('click', function () {
            if (box.parentNode) { box.parentNode.removeChild(box); }
            loadMonth(container, month);
        });
        box.appendChild(retry);

        grid.insertBefore(box, grid.firstChild);
    }

    /**
     * Warm the months either side, quietly.
     *
     * No loading state and no error handling on purpose: nobody asked for
     * these, so a failure should be invisible and the click that needs them
     * will simply fetch normally.
     */
    function prefetchAround(container, month) {
        var grid = gridOf(container);
        if (!grid) {
            return;
        }
        var neighbours = [grid.getAttribute('data-prev'), grid.getAttribute('data-next')];
        for (var i = 0; i < neighbours.length; i++) {
            (function (target) {
                if (!target || monthCache[monthCacheKey(container, target)]) {
                    return;
                }
                request(container, 1, 'month', function (data) {
                    monthCache[monthCacheKey(container, target)] = data.html;
                }, function () { /* silent by design */ }, { month: target });
            })(neighbours[i]);
        }
    }

    function bindMonth(container) {
        var grid = gridOf(container);
        if (!grid) {
            return;
        }

        var navs = grid.querySelectorAll('[data-goto]');
        for (var i = 0; i < navs.length; i++) {
            (function (button) {
                button.addEventListener('click', function () {
                    loadMonth(container, button.getAttribute('data-goto'));
                });
            })(navs[i]);
        }

        bindGridKeys(container, grid);
        bindDaySelection(container, grid);
        syncDayPanel(container);
    }

    /* -----------------------------------------------------------------------
     * Grid keyboard navigation
     *
     * A roving tabindex: exactly one cell is in the tab order, and the arrow
     * keys move focus (and the tab stop) around the month. This is what makes
     * a 42-cell grid usable without tabbing through every day.
     * -------------------------------------------------------------------- */

    function cellsOf(grid) {
        return grid.querySelectorAll('td.uc-day');
    }

    function focusCell(grid, cells, index) {
        if (index < 0 || index >= cells.length) {
            return;
        }
        for (var i = 0; i < cells.length; i++) {
            cells[i].setAttribute('tabindex', i === index ? '0' : '-1');
        }
        cells[index].focus();
    }

    function bindGridKeys(container, grid) {
        var cells = cellsOf(grid);
        if (!cells.length) {
            return;
        }

        grid.addEventListener('keydown', function (event) {
            var current = -1;
            for (var i = 0; i < cells.length; i++) {
                if (cells[i] === document.activeElement) {
                    current = i;
                    break;
                }
            }
            if (current < 0) {
                return;
            }

            var next = -1;
            switch (event.key) {
                case 'ArrowRight': next = current + 1; break;
                case 'ArrowLeft':  next = current - 1; break;
                case 'ArrowDown':  next = current + 7; break;
                case 'ArrowUp':    next = current - 7; break;
                case 'Home':       next = current - (current % 7); break;
                case 'End':        next = current - (current % 7) + 6; break;
                case 'Enter':
                case ' ':
                    selectDay(container, cells[current].getAttribute('data-day'));
                    event.preventDefault();
                    return;
                default: return;
            }

            if (next >= 0 && next < cells.length) {
                focusCell(grid, cells, next);
                event.preventDefault();
            }
        });
    }

    /* -----------------------------------------------------------------------
     * Mobile: tap a date, see that day below the grid
     *
     * Seven columns do not fit a phone, so the grid collapses to a date number
     * and one dot per event (CSS), and the chosen day's events render underneath
     * as ordinary list cards. Never opens blank: today when today has events,
     * otherwise the next day that does.
     * -------------------------------------------------------------------- */

    function bindDaySelection(container, grid) {
        var cells = cellsOf(grid);
        for (var i = 0; i < cells.length; i++) {
            (function (cell) {
                cell.addEventListener('click', function (event) {
                    // A click straight on an event link is a navigation, not a
                    // day selection.
                    var node = event.target;
                    while (node && node !== cell) {
                        if (node.tagName === 'A') { return; }
                        node = node.parentNode;
                    }
                    selectDay(container, cell.getAttribute('data-day'));
                });
            })(cells[i]);
        }
    }

    /** The first day at or after `from` that has events, or ''. */
    function firstDayWithEvents(grid, from) {
        var cells = cellsOf(grid);
        var fallback = '';
        for (var i = 0; i < cells.length; i++) {
            var day = cells[i].getAttribute('data-day');
            var count = parseInt(cells[i].getAttribute('data-count') || '0', 10);
            if (count > 0) {
                if (!fallback) { fallback = day; }
                if (!from || day >= from) { return day; }
            }
        }
        return fallback;
    }

    function selectDay(container, day) {
        var grid = gridOf(container);
        if (!grid || !day) {
            return;
        }
        grid.setAttribute('data-selected', day);

        var cells = cellsOf(grid);
        for (var i = 0; i < cells.length; i++) {
            var on = (cells[i].getAttribute('data-day') === day);
            cells[i].classList.toggle('uc-day-selected', on);
            // Not colour alone: the state is in the accessibility tree too.
            cells[i].setAttribute('aria-selected', on ? 'true' : 'false');
        }
        renderDayPanel(container, grid, day);
    }

    /**
     * Copy the chosen day's event blocks into the panel below the grid.
     *
     * Cloned from markup already on the page rather than fetched: the month
     * response carries every event it contains, so a tap costs no request.
     */
    function renderDayPanel(container, grid, day) {
        var panel = grid.querySelector('.uc-month-day-panel');
        if (!panel) {
            return;
        }
        var cell = grid.querySelector('td.uc-day[data-day="' + day + '"]');
        panel.innerHTML = '';
        if (!cell) {
            return;
        }

        var heading = document.createElement('h4');
        heading.className = 'uc-day-panel-title';
        heading.textContent = (cell.getAttribute('aria-label') || '').split('.')[0];
        panel.appendChild(heading);

        var events = cell.querySelector('.uc-day-events');
        if (!events || !events.children.length) {
            var none = document.createElement('p');
            none.className = 'uc-day-panel-empty';
            none.textContent = 'Nothing scheduled on this day.';
            panel.appendChild(none);
            return;
        }
        panel.appendChild(events.cloneNode(true));
    }

    /** Pick a sensible day when the grid first appears, so it is never blank. */
    function syncDayPanel(container) {
        var grid = gridOf(container);
        if (!grid) {
            return;
        }
        var already = grid.getAttribute('data-selected');
        if (already) {
            renderDayPanel(container, grid, already);
            return;
        }
        var today = grid.getAttribute('data-today') || '';
        var todayCell = grid.querySelector('td.uc-day[data-day="' + today + '"]');
        var count = todayCell ? parseInt(todayCell.getAttribute('data-count') || '0', 10) : 0;
        var day = (count > 0) ? today : firstDayWithEvents(grid, today);
        if (day) {
            selectDay(container, day);
        }
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
     * The category this block is showing, as the endpoint wants it.
     *
     * 'all' and '' both mean "no choice made"; the endpoint then falls back to
     * the snippet's own scope.
     */
    function activeCategory(container) {
        var value = container.getAttribute('data-active-category') || '';
        return (value === 'all') ? '' : value;
    }

    /**
     * The category buttons and the chips both re-fetch the WHOLE block.
     *
     * They used to swap only the list, which was right while a category
     * changed nothing but the rows. It changes the controls now: choosing one
     * is what makes the Groups row appear, and the row is derived by the
     * endpoint from what that category actually contains. Rebuilding it here
     * would be a second implementation of the derivation, in another language.
     *
     * The search box still swaps the list alone, because re-rendering the block
     * would take the caret out from between two keystrokes.
     */

    /** The number above the list: the endpoint's total, never the card count. */
    function setCount(container, total) {
        var count = container.querySelector('.uc-count-number');
        if (!count) {
            return;
        }
        if (total === null || typeof total === 'undefined') {
            count.textContent = String(cardsIn(container).length);
            return;
        }
        count.textContent = String(total);
    }

    function bindFilters(container) {
        var buttons = container.querySelectorAll('.uc-filter-btn');
        for (var i = 0; i < buttons.length; i++) {
            (function (button) {
                button.addEventListener('click', function () {
                    for (var j = 0; j < buttons.length; j++) {
                        buttons[j].classList.remove('active');
                        buttons[j].setAttribute('aria-pressed', 'false');
                    }
                    button.classList.add('active');
                    button.setAttribute('aria-pressed', 'true');
                    container.setAttribute('data-active-category', button.getAttribute('data-category') || 'all');
                    // A different category holds different groups, so the
                    // second-level choice cannot outlive the first-level one.
                    container.setAttribute('data-active-groups', '');
                    loadBlock(container, 1, false);
                });
            })(buttons[i]);
        }
    }

    /**
     * The second level: groups.
     *
     * THE WHOLE BLOCK IS RE-FETCHED, not just the list. The Groups row is
     * derived by the server from what the chosen category contains, and the
     * breadcrumb states where the visitor is; rebuilding either here would be a
     * second implementation of both, in another language, free to disagree with
     * the endpoint's.
     *
     * Delegated from the container in init(), once, so it survives every render.
     */
    function bindGroups(container) {
        container.addEventListener('click', function (e) {
            var t = e.target;
            if (!t || !t.closest) {
                return;
            }

            var clear = t.closest('[data-uc-group-clear]');
            if (clear && container.contains(clear)) {
                e.preventDefault();
                container.setAttribute('data-active-groups', '');
                loadBlock(container, 1, false);
                return;
            }

            var crumb = t.closest('[data-uc-crumb]');
            if (crumb && container.contains(crumb)) {
                e.preventDefault();
                if (crumb.getAttribute('data-uc-crumb') === 'all') {
                    container.setAttribute('data-active-category', 'all');
                }
                container.setAttribute('data-active-groups', '');
                loadBlock(container, 1, false);
                return;
            }

            var pill = t.closest('[data-uc-group]');
            if (pill && container.contains(pill) && pill.tagName !== 'INPUT') {
                e.preventDefault();
                toggleGroup(container, pill.getAttribute('data-uc-group') || '');
            }
        });

        container.addEventListener('change', function (e) {
            var box = e.target;
            if (box && box.matches && box.matches('input[data-uc-group]')) {
                toggleGroup(container, box.getAttribute('data-uc-group') || '');
            }
        });
    }

    function toggleGroup(container, slug) {
        if (!slug) {
            return;
        }
        var current = container.getAttribute('data-active-groups') || '';
        var list = current === '' ? [] : current.split(',');
        var at = list.indexOf(slug);

        if (at === -1) {
            list.push(slug);
        } else {
            list.splice(at, 1);
        }
        container.setAttribute('data-active-groups', list.join(','));
        loadBlock(container, 1, false);
    }

    /**
     * A category chip on a card filters this block instead of navigating.
     *
     * The chip's href is a real calendar URL carrying ?uc_cat=, and that is what
     * has to happen when this listener is not there: no JavaScript, a
     * middle-click, or a host page where the script failed. Somebody reading an
     * embed on another site should not be thrown to a different domain for a
     * filter this block can apply itself.
     *
     * Delegated from the container, once, in init(): cards arrive from later
     * requests and would otherwise miss the binding.
     */
    function bindChips(container) {
        container.addEventListener('click', function (e) {
            var chip = e.target && e.target.closest ? e.target.closest('.uc-lc-chip-link') : null;
            if (!chip || !container.contains(chip)) {
                return;
            }
            if (e.button > 0 || e.metaKey || e.ctrlKey || e.shiftKey) {
                return;
            }
            var slug = chip.getAttribute('data-uc-cat') || '';
            if (!slug) {
                return;
            }
            e.preventDefault();

            var button = container.querySelector('.uc-filter-btn[data-category="' + slug + '"]');
            if (button) {
                button.click();
                return;
            }
            container.setAttribute('data-active-category', slug);
            container.setAttribute('data-active-groups', '');
            loadBlock(container, 1, false);
        });
    }

    /**
     * Search, run by the endpoint.
     *
     * ASKS THE SERVER, so an embed on another site and the calendar on this
     * one return the same events for the same words. Both queries are built by
     * SFAF_Search, through the same renderer, so there is nothing here that
     * could disagree with the calendar site: this file no longer decides what
     * matching means, it just asks.
     *
     * REQUESTS ONLY THE LIST, not the whole block. Re-rendering the block would
     * replace the filter bar and take the focus out of the search box between
     * keystrokes, which makes typing impossible. So this asks for `items` and
     * swaps the list underneath, leaving the box, its value and the caret
     * exactly where they were.
     */
    function bindSearch(container) {
        var input = container.querySelector('.uc-search');
        if (!input) {
            return;
        }

        var timer = null;
        var seq = 0;

        function run() {
            var term = (input.value || '').replace(/^\s+|\s+$/g, '');
            container.setAttribute('data-active-search', term);

            var list = listOf(container);
            if (!list) {
                return;
            }

            // Only the newest request may write. A slow answer for "har"
            // landing after a fast one for "harm reduction" would otherwise
            // leave the wrong list under the right search box.
            seq++;
            var mine = seq;

            request(container, 1, 'items', function (data) {
                if (mine !== seq) {
                    return;
                }
                list.innerHTML = (data && data.html) ? data.html : '';
                if (!data || !data.html) {
                    list.innerHTML = '<p class="uc-empty">No events match that search.</p>';
                }
                revealCards(container);
                var block = inner(container);
                if (block) {
                    // Page one of a different result set. Left alone, Load More
                    // would append page five of the previous one.
                    block.setAttribute('data-page', '1');
                    if (data && typeof data.max_pages !== 'undefined') {
                        block.setAttribute('data-max-pages', String(data.max_pages));
                    }
                }
                if (!data || !data.has_more) {
                    removePagination(container);
                }
                // The search request carried the chosen category too, so these
                // rows are already the right ones. Only the count needs writing.
                setCount(container, (data && typeof data.total !== 'undefined') ? data.total : null);
            }, function () {
                if (mine === seq) {
                    showError(container);
                }
            });
        }

        input.addEventListener('input', function () {
            clearTimeout(timer);
            timer = setTimeout(run, 250);
        });
        input.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.keyCode === 13) {
                e.preventDefault();
                clearTimeout(timer);
                run();
            }
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
        container.setAttribute('data-active-groups', '');
        container.setAttribute('data-active-search', '');

        // Bound to the container itself, once, so they survive every re-render
        // and reach cards that arrive from a later request.
        bindAddToCalendar(container);
        bindChips(container);
        bindGroups(container);

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

    /* -----------------------------------------------------------------------
     * THE CRON NUDGE
     *
     * WordPress's "cron" is not a scheduler. It is a check that runs when
     * somebody loads a page of the site the jobs live on, and the site these
     * jobs live on gets almost no traffic. A reminder due at 6am on a site
     * nobody visits until the afternoon goes out in the afternoon, or not at
     * all. That is not a theoretical concern: it is the difference between the
     * morning-of reminder working and not working.
     *
     * This block is the fix, and it works because of where this file runs: on
     * sfaf.org, which has plenty of visitors, while the calendar and its jobs
     * live on another site entirely. Every page carrying a calendar can lend
     * that site a heartbeat.
     *
     * IT COSTS ALMOST NOTHING, AND THERE ARE THREE SEPARATE REASONS.
     *
     *   1. IT DOES NOT RUN DURING PAGE LOAD. It waits for the window load
     *      event and then for an idle moment, so it cannot compete with the
     *      calendar's own request, with images, or with anything the host page
     *      is doing. On a page that never goes idle it never fires at all,
     *      which is the correct outcome: that visitor is busy.
     *   2. MOST VISITORS SEND NOTHING. A timestamp in localStorage means one
     *      browser sends at most one ping per interval no matter how many
     *      calendar pages it opens, so a person reading six programme pages
     *      costs one request, not six.
     *   3. THE ONES THAT DO SEND COST THE SERVER NOTHING. The endpoint reads
     *      one option and returns 204 when the interval has not elapsed. Only
     *      the first ping after the interval does any work.
     *
     * It is fire-and-forget: no-cors, so there is no preflight and no CORS
     * requirement on the response, and the reply is never read. Nothing on this
     * page depends on it, nothing waits for it, and a failure is silent by
     * design. A blocked request, a firewalled origin or a browser with no
     * fetch() leaves the page exactly as it was.
     *
     * SAFE TO CALL FROM ANYWHERE, because it carries no parameters and cannot
     * ask for anything: the endpoint takes no input and does one thing.
     * -------------------------------------------------------------------- */

    var PING_URL = ROOT + '/wp-admin/admin-ajax.php?action=sfaf_cron_ping';
    var PING_KEY = 'sfafCronPingAt';
    var PING_EVERY_MS = 15 * 60 * 1000;

    function pingDue() {
        try {
            var last = parseInt(window.localStorage.getItem(PING_KEY), 10);
            if (last && (Date.now() - last) < PING_EVERY_MS) {
                return false;
            }
            window.localStorage.setItem(PING_KEY, String(Date.now()));
        } catch (e) {
            // Private mode, a blocked cookie policy, a browser with storage
            // switched off. Ping anyway: the server-side interval is the real
            // guard, and this one is only here to spare it the requests.
            return true;
        }
        return true;
    }

    function nudgeCron() {
        if (!window.fetch || !pingDue()) {
            return;
        }
        try {
            window.fetch(PING_URL, {
                method: 'GET',
                mode: 'no-cors',
                cache: 'no-store',
                credentials: 'omit',
                keepalive: true
            })['catch'](function () { /* nothing here depends on it */ });
        } catch (e) { /* nothing here depends on it */ }
    }

    function scheduleNudge() {
        var idle = window.requestIdleCallback || function (fn) { return window.setTimeout(fn, 1200); };
        idle(nudgeCron, { timeout: 5000 });
    }

    window.sfafCalendarEmbed = { scan: scan };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', scan);
    } else {
        scan();
    }

    // After load, not with it.
    if (document.readyState === 'complete') {
        scheduleNudge();
    } else {
        window.addEventListener('load', scheduleNudge);
    }
})();
