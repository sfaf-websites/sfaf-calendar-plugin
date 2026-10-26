/**
 * Mailchimp panel: Test connection (3.124.0).
 *
 * PLAIN SCRIPT, as everyaction.js is. Posts the key in the box, or, when the
 * box is blank, asks the server to use the saved one, and fills the Default
 * audience dropdown from the answer. What comes back is never the key: the
 * server scrubs every message before it leaves.
 *
 * THE AUDIENCE NAMES ARE SET AS TEXT, never as markup.
 */
(function () {
    'use strict';

    function init() {
        var form = document.querySelector('[data-uc-mc-form]');
        if (!form) { return; }
        var btn = form.querySelector('[data-uc-mc-test]');
        var msg = form.querySelector('[data-uc-mc-test-msg]');
        var select = form.querySelector('[data-uc-mc-audience]');
        if (!btn || !msg || !select) { return; }

        function say(text, ok) {
            msg.textContent = text;
            msg.classList.remove('notice', 'notice-success', 'notice-error');
            msg.classList.add('notice', ok ? 'notice-success' : 'notice-error');
            msg.hidden = false;
        }

        btn.addEventListener('click', function () {
            var data = new FormData();
            data.append('action', 'sfaf_mailchimp_test');
            data.append('nonce', (window.sfafAdmin && window.sfafAdmin.nonce) || '');
            data.append('id', (form.querySelector('[name="id"]') || {}).value || '');
            data.append('api_key', (form.querySelector('[name="api_key"]') || {}).value || '');
            btn.disabled = true;
            fetch((window.sfafAdmin && window.sfafAdmin.ajaxUrl) || '', { method: 'POST', credentials: 'same-origin', body: data })
                .then(function (res) {
                    return res.json().catch(function () {
                        return { success: false, data: { message: 'WordPress answered HTTP ' + res.status + ' with something that is not JSON.' } };
                    });
                })
                .then(function (r) {
                    var d = (r && r.data) || {};
                    say(d.message || (r && r.success ? 'Connected.' : 'Something went wrong.'), !!(r && r.success));
                    if (r && r.success && d.audiences) {
                        var keep = select.value;
                        while (select.firstChild) { select.removeChild(select.firstChild); }
                        d.audiences.forEach(function (a) {
                            var o = document.createElement('option');
                            o.value = a.id;
                            o.textContent = a.name;
                            if (a.id === keep) { o.selected = true; }
                            select.appendChild(o);
                        });
                    }
                })
                .catch(function () { say('The test could not reach WordPress. Try again.', false); })
                .then(function () { btn.disabled = false; });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
