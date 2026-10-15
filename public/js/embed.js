/**
 * SFAF Calendar: the embed loader (3.112.1).
 *
 * This is the file a host page loads, and the address every pasted snippet
 * names:
 *
 *   <div class="sfaf-calendar-embed" data-sfaf-calendar
 *        data-category="support-groups"></div>
 *   <script src="https://example.org/wp-content/plugins/sfaf-calendar/public/js/embed.js" async></script>
 *
 * IT HOLDS NO RELEASE LOGIC, AND ITS CODE DOES NOT CHANGE BETWEEN RELEASES.
 * It asks the calendar site which release it is on, on the embed route the
 * blocks already use, and loads the calendar itself from a versioned address:
 *
 *   public/js/embed-app.js?ver=<the site's version>
 *
 * So a host page holding a cached copy of THIS file is harmless: the cached
 * copy asks the same question and gets today's answer. Until 3.112.1 this file
 * was the calendar, and a browser that kept an old copy kept the old calendar,
 * which is what sfaf.org/calendar showed after 3.112.0 was installed.
 *
 * THE SNIPPET STILL CARRIES NO VERSION, and must never carry one. A version in
 * pasted HTML pins the page to that release rather than busting anything (the
 * 2.10.1 fault). A snippet pasted before 2.10.1 with ?ver= on it reaches this
 * loader too, and the query string is ignored.
 *
 * NO RELOADS. The loader adds one script, once, and never reloads the page or
 * itself. If the site cannot be asked, the calendar loads unversioned, which
 * is what every page did before this file existed.
 *
 * Do not add features here. Anything that would have to change in a release
 * belongs in embed-app.js, because this file is the one a browser may keep.
 */
(function () {
    'use strict';

    // A second snippet on the page, or a cached pre-3.112.1 embed.js that got
    // here first: claim any new blocks and stop.
    if (window.sfafCalendarEmbedLoader || window.sfafCalendarEmbed) {
        if (window.sfafCalendarEmbed) {
            window.sfafCalendarEmbed.scan();
        }
        return;
    }
    window.sfafCalendarEmbedLoader = true;

    var SCRIPT = document.currentScript || (function () {
        var all = document.getElementsByTagName('script');
        return all[all.length - 1];
    })();
    var SRC = (SCRIPT && SCRIPT.src) || '';
    var PATTERN = /\/js\/embed\.js(\?.*)?$/;

    var ROOT = (function (src) {
        var at = src.indexOf('/wp-content/');
        if (at > -1) {
            return src.slice(0, at);
        }
        var a = document.createElement('a');
        a.href = src || '/';
        return a.protocol + '//' + a.host;
    })(SRC);

    function appUrl(version) {
        var base = PATTERN.test(SRC)
            ? SRC.replace(PATTERN, '/js/embed-app.js')
            : ROOT + '/wp-content/plugins/sfaf-calendar/public/js/embed-app.js';
        return version ? base + '?ver=' + encodeURIComponent(version) : base;
    }

    var started = false;
    function start(version) {
        if (started) {
            return;
        }
        started = true;
        var tag = document.createElement('script');
        tag.src = appUrl(version);
        tag.async = true;
        // data-endpoint and anything else the snippet's tag says, for the
        // calendar to read off its own tag as it always has.
        if (SCRIPT && SCRIPT.attributes) {
            for (var i = 0; i < SCRIPT.attributes.length; i++) {
                var a = SCRIPT.attributes[i];
                if (a.name.indexOf('data-') === 0) {
                    tag.setAttribute(a.name, a.value);
                }
            }
        }
        (document.head || document.documentElement).appendChild(tag);
    }

    var override = (SCRIPT && SCRIPT.getAttribute && SCRIPT.getAttribute('data-endpoint')) || '';
    if (!override) {
        var block = document.querySelector('[data-sfaf-calendar][data-endpoint]');
        override = block ? block.getAttribute('data-endpoint') : '';
    }
    var CANDIDATES = override ? [override] : [
        ROOT + '/wp-json/sfaf-calendar/v1/embed',
        ROOT + '/?rest_route=/sfaf-calendar/v1/embed'
    ];

    function ask(index) {
        if (index >= CANDIDATES.length) {
            start('');
            return;
        }
        var url = CANDIDATES[index];
        var xhr = new XMLHttpRequest();
        try {
            xhr.open('GET', url + (url.indexOf('?') > -1 ? '&' : '?') + 'embed_version=1', true);
        } catch (e) {
            ask(index + 1);
            return;
        }
        xhr.timeout = 8000;
        xhr.onload = function () {
            var version = '';
            if (xhr.status >= 200 && xhr.status < 300) {
                try {
                    version = JSON.parse(xhr.responseText).embed_version;
                } catch (e) {
                    version = '';
                }
            }
            if (typeof version === 'string' && /^[0-9][0-9A-Za-z.\-]*$/.test(version)) {
                start(version);
            } else {
                ask(index + 1);
            }
        };
        xhr.onerror = function () { ask(index + 1); };
        xhr.ontimeout = function () { ask(index + 1); };
        xhr.send();
    }

    ask(0);
})();
