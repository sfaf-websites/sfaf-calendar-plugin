/**
 * caladmin: nothing typed is lost to an expired sign-in (3.110.5).
 *
 * Three jobs, one file, loaded by SFAF_Portal::foot() on every caladmin page
 * BEFORE the footer scripts and portal.js:
 *
 *   DRAFTS. Every caladmin form that takes typing keeps a copy of its fields
 *   in this browser as the person works, keyed to the screen ("events/edit/12")
 *   and the form. Opening the screen again with a copy that differs from what
 *   the page drew offers "Restore what you were typing?" with Restore and
 *   Discard. The server names the draft it accepted in the uc_draft_done
 *   cookie and this clears it on the next page.
 *
 *   THE CHECK BEFORE A POST. Every caladmin form's submit is held while the
 *   server is asked whether the person is still signed in and whether this
 *   form's token is good. If either is not, the post never goes out: the
 *   draft is written, and the person goes to the sign-in (or straight back to
 *   the screen when only the token was stale) with ?uc_restore=1 on the
 *   return address. If the question cannot be asked, the post goes as it
 *   would have, and the server's session-ended page is the net under it.
 *
 *   THE KEEPALIVE. Every ten minutes while a caladmin page is open the same
 *   endpoint is asked with keepalive=1, and the server renews a sign-in that
 *   is close to ending, over the same session so no open form's token breaks.
 *
 * WHY BEFORE THE FOOTER SCRIPTS. A restore is applied when this file runs, so
 * the values are in the fields before TinyMCE and portal.js start and read
 * them. Restore therefore reloads the page with ?uc_restore=1 rather than
 * writing values into controls that have already drawn themselves.
 *
 * WHAT A RESTORE CANNOT DO. It fills the fields the page drew. A row added in
 * the browser and never saved (a FAQ, a question, an extra date) is not
 * drawn again, so its fields have nowhere to go.
 */
(function () {
    'use strict';

    var node = document.getElementById('uc-session-config');
    if (!node) { return; }
    var cfg;
    try { cfg = JSON.parse(node.textContent); } catch (e) { return; }

    var PREFIX = 'ucDraft:';
    var MAX_AGE = 14 * 864e5;
    var KEEPALIVE_MS = 10 * 60 * 1000;
    var PASS_MS = 2 * 60 * 1000;
    var SIGN_IN_FORMS = { login: 1, forgot_password: 1, reset_password: 1 };
    var NOT_A_FIELD = /(^|_)nonce$|^_wpnonce$|^uc_action$|^_wp_http_referer$|^uc_draft_key$|^redirect_to$/;
    var TYPED = /^(text|email|url|tel|number|search|date|time|datetime-local|month|week)$/;

    /* The one way this file leaves the page, so a check can stand in for it. */
    var api = window.ucSession = { go: function (url) { window.location.assign(url); } };

    /* ---- storage, every call guarded: private windows refuse it --------- */
    function box() { try { return window.localStorage; } catch (e) { return null; } }
    function read(key) {
        var s = box();
        if (!s) { return null; }
        try { return JSON.parse(s.getItem(key) || 'null'); } catch (e) { return null; }
    }
    function write(key, value) {
        var s = box();
        if (!s) { return; }
        try { s.setItem(key, JSON.stringify(value)); } catch (e) { /* full or refused */ }
    }
    function drop(key) {
        var s = box();
        if (!s) { return; }
        try { s.removeItem(key); } catch (e) { /* refused */ }
    }

    /* ---- which forms, and their keys ------------------------------------ */
    function actionOf(form) {
        var a = form.querySelector('input[name="uc_action"]');
        return a ? a.value : '';
    }
    function isCaladminPost(form) {
        var a = actionOf(form);
        return (form.getAttribute('method') || '').toLowerCase() === 'post' && a !== '' && !SIGN_IN_FORMS[a];
    }
    /* A form somebody types into. A row of tick boxes alone is not drafted. */
    function takesTyping(form) {
        return Array.prototype.some.call(form.elements, function (el) {
            if (!el.name || NOT_A_FIELD.test(el.name)) { return false; }
            if (el.tagName === 'TEXTAREA') { return true; }
            return el.tagName === 'INPUT' && TYPED.test((el.type || 'text').toLowerCase());
        });
    }
    function draftForms() {
        return Array.prototype.filter.call(document.forms, function (f) {
            return isCaladminPost(f) && takesTyping(f);
        });
    }
    function keyOf(form) {
        var a = actionOf(form), n = 0, all = draftForms();
        for (var i = 0; i < all.length && all[i] !== form; i++) {
            if (actionOf(all[i]) === a) { n++; }
        }
        return PREFIX + cfg.screen + '|' + a + '|' + n;
    }

    /* ---- reading and writing a form's fields ---------------------------- */
    function fieldsOf(form) {
        var out = [], seen = {};
        Array.prototype.forEach.call(form.elements, function (el) {
            var n = el.name, t = (el.type || '').toLowerCase();
            if (!n || NOT_A_FIELD.test(n) || el.disabled) { return; }
            if (t === 'password' || t === 'file' || t === 'submit' || t === 'button' || t === 'reset' || t === 'image') { return; }
            if (t === 'checkbox' || t === 'radio') {
                out.push({ n: n, v: el.value, c: el.checked ? 1 : 0 });
                return;
            }
            var i = seen[n] = (seen[n] === undefined ? 0 : seen[n] + 1);
            if (el.tagName === 'SELECT' && el.multiple) {
                out.push({ n: n, i: i, m: Array.prototype.filter.call(el.options, function (o) { return o.selected; }).map(function (o) { return o.value; }) });
                return;
            }
            out.push({ n: n, i: i, v: el.value });
        });
        return out;
    }
    function same(a, b) { return JSON.stringify(a) === JSON.stringify(b); }
    function apply(form, fields) {
        var ticks = {}, byName = {};
        fields.forEach(function (f) {
            if (f.c !== undefined) {
                ticks[f.n] = ticks[f.n] || {};
                if (f.c) { ticks[f.n][f.v] = 1; }
            }
        });
        Array.prototype.forEach.call(form.elements, function (el) {
            if (!el.name || NOT_A_FIELD.test(el.name)) { return; }
            (byName[el.name] = byName[el.name] || []).push(el);
        });
        Object.keys(ticks).forEach(function (n) {
            (byName[n] || []).forEach(function (el) {
                var t = (el.type || '').toLowerCase();
                if (t === 'checkbox' || t === 'radio') { el.checked = !!ticks[n][el.value]; }
            });
        });
        fields.forEach(function (f) {
            if (f.c !== undefined) { return; }
            var others = (byName[f.n] || []).filter(function (el) {
                var t = (el.type || '').toLowerCase();
                return t !== 'checkbox' && t !== 'radio';
            });
            var el = others[f.i];
            if (!el) { return; }
            if (f.m) {
                Array.prototype.forEach.call(el.options, function (o) { o.selected = f.m.indexOf(o.value) !== -1; });
            } else if (el.type !== 'file') {
                el.value = f.v;
            }
        });
    }

    /* TinyMCE keeps its text to itself until asked. */
    function syncEditors() {
        if (!window.tinymce || !window.tinymce.editors) { return; }
        try {
            Array.prototype.forEach.call(window.tinymce.editors, function (ed) {
                if (ed && ed.isDirty && ed.isDirty()) {
                    ed.save();
                    var ta = ed.getElement && ed.getElement();
                    if (ta && ta.form) { dirty(ta.form); }
                }
            });
        } catch (e) { /* an editor mid-teardown */ }
    }

    /* ---- the drafts ------------------------------------------------------ */
    var baseline = {}, touched = [];
    function dirty(form) { if (touched.indexOf(form) === -1) { touched.push(form); } }
    function save(form) {
        var key = keyOf(form), now = fieldsOf(form);
        if (same(now, baseline[key])) { drop(key); return; }
        write(key, { t: Date.now(), f: now });
    }
    function saveTouched() {
        syncEditors();
        touched.forEach(save);
    }

    /* What the server accepted last time is no longer a draft. */
    (function clearAccepted() {
        var m = document.cookie.match(/(?:^|;\s*)uc_draft_done=([^;]*)/);
        if (!m) { return; }
        var key;
        try { key = decodeURIComponent(m[1]); } catch (e) { key = m[1]; }
        if (key.indexOf(PREFIX) === 0) { drop(key); }
        document.cookie = 'uc_draft_done=; Max-Age=0; path=/';
    })();

    /* Old drafts go. */
    (function prune() {
        var s = box();
        if (!s) { return; }
        try {
            for (var i = s.length - 1; i >= 0; i--) {
                var k = s.key(i);
                if (k && k.indexOf(PREFIX) === 0) {
                    var d = read(k);
                    if (!d || !d.t || Date.now() - d.t > MAX_AGE) { drop(k); }
                }
            }
        } catch (e) { /* refused */ }
    })();

    var forms = draftForms();
    var waiting = [];
    forms.forEach(function (form) {
        var key = keyOf(form);
        baseline[key] = fieldsOf(form);
        var d = read(key);
        if (d && d.f && !same(d.f, baseline[key])) { waiting.push({ form: form, key: key, fields: d.f }); }
    });

    var params = new URLSearchParams(window.location.search);
    var restoring = params.get('uc_restore') === '1';
    if (restoring && window.history && window.history.replaceState) {
        params.delete('uc_restore');
        var q = params.toString();
        window.history.replaceState(null, '', window.location.pathname + (q ? '?' + q : '') + window.location.hash);
    }

    function here() {
        var u = new URL(window.location.href);
        u.searchParams.delete('uc_restore');
        u.searchParams.set('uc_restore', '1');
        return u.toString();
    }
    function topOfPage() {
        var main = document.querySelector('.uc-portal-main');
        if (!main) { return null; }
        var bar = main.querySelector(':scope > .uc-portal-topbar');
        return { parent: main, before: bar ? bar.nextSibling : main.firstChild };
    }
    function say(el) {
        var at = topOfPage();
        if (!at) { return false; }
        at.parent.insertBefore(el, at.before);
        return true;
    }

    if (waiting.length && restoring) {
        waiting.forEach(function (w) {
            /* The baseline stays what the server drew, so the copy is kept
               until a save is accepted: a second lapse loses nothing. */
            apply(w.form, w.fields);
            dirty(w.form);
        });
        var back = document.createElement('div');
        back.className = 'uc-flash uc-draft-restored';
        back.setAttribute('role', 'status');
        back.setAttribute('data-uc-draft-restored', '');
        back.textContent = 'What you were typing is back. Save to keep it.';
        say(back);
    } else if (waiting.length) {
        var bar = document.createElement('div');
        bar.className = 'uc-flash uc-flash-attention uc-draft-prompt';
        bar.setAttribute('role', 'status');
        bar.setAttribute('data-uc-draft-prompt', '');
        var words = document.createElement('span');
        words.className = 'uc-draft-prompt-text';
        words.textContent = 'Restore what you were typing?';
        var acts = document.createElement('span');
        acts.className = 'uc-draft-prompt-actions';
        var yes = document.createElement('button');
        yes.type = 'button';
        yes.className = 'uc-btn uc-btn-primary';
        yes.setAttribute('data-uc-draft-restore', '');
        yes.textContent = 'Restore';
        var no = document.createElement('button');
        no.type = 'button';
        no.className = 'uc-btn';
        no.setAttribute('data-uc-draft-discard', '');
        no.textContent = 'Discard';
        acts.appendChild(yes);
        acts.appendChild(no);
        bar.appendChild(words);
        bar.appendChild(acts);
        yes.addEventListener('click', function () { api.go(here()); });
        no.addEventListener('click', function () {
            waiting.forEach(function (w) { drop(w.key); });
            waiting = [];
            bar.parentNode.removeChild(bar);
        });
        if (!say(bar)) { waiting = []; }
    }

    /* Typing into a form that has a copy waiting replaces the copy. */
    function onEdit(e) {
        var t = e.target;
        var form = t && (t.form || (t.closest && t.closest('form')));
        if (!form || forms.indexOf(form) === -1) { return; }
        dirty(form);
        clearTimeout(onEdit.t);
        onEdit.t = setTimeout(saveTouched, 400);
    }
    document.addEventListener('input', onEdit, true);
    document.addEventListener('change', onEdit, true);
    document.addEventListener('click', onEdit, true);
    setInterval(saveTouched, 5000);
    window.addEventListener('pagehide', saveTouched);

    /* ---- the check before a post ---------------------------------------- */
    var passUntil = 0;
    function resubmit(form, submitter) {
        if (form.requestSubmit) {
            if (submitter && submitter.form === form) { form.requestSubmit(submitter); } else { form.requestSubmit(); }
            return;
        }
        if (submitter && submitter.name) {
            var h = document.createElement('input');
            h.type = 'hidden'; h.name = submitter.name; h.value = submitter.value;
            form.appendChild(h);
        }
        form.submit();
    }
    function tag(form) {
        if (forms.indexOf(form) === -1) { return; }
        var k = form.querySelector('input[name="uc_draft_key"]');
        if (!k) {
            k = document.createElement('input');
            k.type = 'hidden'; k.name = 'uc_draft_key';
            form.appendChild(k);
        }
        k.value = keyOf(form);
    }
    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!form || form.tagName !== 'FORM' || !isCaladminPost(form)) { return; }
        tag(form);
        if (Date.now() < passUntil || !window.fetch || !window.URLSearchParams) { return; }
        e.preventDefault();
        e.stopImmediatePropagation();
        if (form.ucChecking) { return; }
        form.ucChecking = true;
        var submitter = e.submitter || null;
        if (forms.indexOf(form) !== -1) { syncEditors(); dirty(form); save(form); }
        var nonce = form.querySelector('input[name="uc_nonce"]');
        var body = new URLSearchParams();
        body.append('action', cfg.action);
        body.append('check', actionOf(form));
        body.append('nonce', nonce ? nonce.value : '');
        function go_on() {
            form.ucChecking = false;
            passUntil = Date.now() + PASS_MS;
            resubmit(form, submitter);
        }
        window.fetch(cfg.ajax, {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: body.toString()
        }).then(function (res) { return res.json(); }).then(function (json) {
            var d = json && json.success === true && json.data ? json.data : null;
            /* Only a clear no stops a post. Anything unreadable lets it go. */
            if (!d || (d.signed_in !== false && d.valid !== false)) { go_on(); return; }
            form.ucChecking = false;
            var back = here();
            api.go(d.signed_in === false
                ? cfg.signin + (cfg.signin.indexOf('?') === -1 ? '?' : '&') + 'redirect_to=' + encodeURIComponent(back)
                : back);
        }).catch(go_on);
    }, true);

    /* ---- the keepalive --------------------------------------------------- */
    if (cfg.signedIn && window.fetch) {
        setInterval(function () {
            var body = new URLSearchParams();
            body.append('action', cfg.action);
            body.append('keepalive', '1');
            window.fetch(cfg.ajax, {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                body: body.toString()
            }).catch(function () { /* the next one, or the check before a post */ });
        }, KEEPALIVE_MS);
    }
})();
