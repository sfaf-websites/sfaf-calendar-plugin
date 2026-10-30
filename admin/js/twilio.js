/**
 * Twilio panel: Test connection (3.130.0).
 *
 * PLAIN SCRIPT, as mailchimp.js is. Posts the Account SID and the token in
 * the boxes, or, when the token box is blank, asks the server to use the
 * saved one. What comes back is never the token: the server takes it out of
 * every message before it leaves.
 */
(function () {
    'use strict';

    function init() {
        var form = document.querySelector('[data-uc-twilio-form]');
        if (!form) { return; }
        var btn = form.querySelector('[data-uc-twilio-test]');
        var msg = form.querySelector('[data-uc-twilio-test-msg]');
        if (!btn || !msg) { return; }

        function say(text, ok) {
            msg.textContent = text;
            msg.classList.remove('notice', 'notice-success', 'notice-error');
            msg.classList.add('notice', ok ? 'notice-success' : 'notice-error');
            msg.hidden = false;
        }

        btn.addEventListener('click', function () {
            var data = new FormData();
            data.append('action', 'sfaf_twilio_test');
            data.append('nonce', (window.sfafAdmin && window.sfafAdmin.nonce) || '');
            data.append('sid', (form.querySelector('[name="twilio_account_sid"]') || {}).value || '');
            data.append('token', (form.querySelector('[data-uc-twilio-token]') || {}).value || '');
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
