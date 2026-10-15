/**
 * SFAF Calendar: the embed loader (3.112.1; asks a static file since 3.112.2).
 *
 * This is the file a host page loads, and the address every pasted snippet
 * names:
 *
 *   <div class="sfaf-calendar-embed" data-sfaf-calendar
 *        data-category="support-groups"></div>
 *   <script src="https://example.org/wp-content/plugins/sfaf-calendar/public/js/embed.js" async></script>
 *
 * IT HOLDS NO RELEASE LOGIC, AND ITS CODE DOES NOT CHANGE BETWEEN RELEASES.
 * It reads which release the calendar site is on from a static file beside it,
 *
 *   public/js/embed-version.js?t=<the current time, rounded down to 5 minutes>
 *
 * and loads the calendar itself from a versioned address:
 *
 *   public/js/embed-app.js?ver=<that release>
 *
 * So a cached copy of THIS file is harmless: it reads today's answer. The ?t=
 * bucket is what keeps the answer fresh: a browser or a cache in front of the
 * site may keep embed-version.js, but only under that bucket's address, so no
 * cache holds an old answer for longer than five minutes.
 *
 * WHY A STATIC FILE AND NOT THE EMBED ROUTE (3.112.2). In 3.112.1 the loader
 * asked the route, which made every page view a full WordPress request before
 * the calendar's own, on a server whose limits 3.112.x had already found. A
 * static file is served without PHP.
 *
 * THE SNIPPET STILL CARRIES NO VERSION, and must never carry one. A version in
 * pasted HTML pins the page to that release rather than busting anything (the
 * 2.10.1 fault). A snippet pasted before 2.10.1 with ?ver= on it reaches this
 * loader too, and the query string is ignored.
 *
 * NO RELOADS. The loader adds at most two scripts, once each, and never
 * reloads the page or itself. If the version cannot be read, the calendar
 * loads unversioned, which is what every page did before this file existed.
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

    /** A file beside this one, by name. */
    function beside(name) {
        return PATTERN.test(SRC)
            ? SRC.replace(PATTERN, '/js/' + name)
            : ROOT + '/wp-content/plugins/sfaf-calendar/public/js/' + name;
    }

    function addScript(src, attrsFrom, onDone) {
        var tag = document.createElement('script');
        tag.src = src;
        tag.async = true;
        if (attrsFrom && attrsFrom.attributes) {
            // data-endpoint and anything else the snippet's tag says, for the
            // calendar to read off its own tag as it always has.
            for (var i = 0; i < attrsFrom.attributes.length; i++) {
                var a = attrsFrom.attributes[i];
                if (a.name.indexOf('data-') === 0) {
                    tag.setAttribute(a.name, a.value);
                }
            }
        }
        if (onDone) {
            tag.onload = onDone;
            tag.onerror = onDone;
        }
        (document.head || document.documentElement).appendChild(tag);
    }

    var started = false;
    function start() {
        if (started) {
            return;
        }
        started = true;
        var version = window.sfafCalendarEmbedVersion;
        var ok = (typeof version === 'string' && /^[0-9][0-9A-Za-z.\-]*$/.test(version));
        addScript(beside('embed-app.js') + (ok ? '?ver=' + encodeURIComponent(version) : ''), SCRIPT, null);
    }

    // The current five minutes, in seconds: the same address for everybody
    // inside it, a new one after it.
    var bucket = Math.floor(Date.now() / 300000) * 300;
    addScript(beside('embed-version.js') + '?t=' + bucket, null, start);
    // A version file that never answers must not keep the calendar away.
    setTimeout(start, 8000);
})();
