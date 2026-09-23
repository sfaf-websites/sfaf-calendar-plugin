/**
 * EveryAction panel: Test connection and the tracker probe (3.100.0).
 *
 * PLAIN SCRIPT, NOT jQuery. It is two buttons and two answers, and a file with
 * no dependency beyond the sfafAdmin global is one the browser check can run
 * as it ships.
 *
 * BOTH BUTTONS POST WHAT IS IN THE FIELDS, so they work before Save. A blank
 * key or password means "the stored one", which is what a write-only field
 * submits when it has not been retyped. What comes back is never a credential:
 * the server scrubs every message and body before it leaves.
 *
 * THE PROBE'S BODY IS SET AS TEXT, never as markup, so nothing the hub returns
 * can become HTML on this page.
 */
(function () {
    'use strict';

    function field(panel, name) {
        var el = panel.querySelector('[name="uc_settings[' + name + ']"]');
        return el ? el.value : '';
    }

    function post(panel, action) {
        var data = new FormData();
        data.append('action', action);
        data.append('nonce', (window.sfafAdmin && window.sfafAdmin.nonce) || '');
        data.append('hub', field(panel, 'everyaction_hub_url'));
        data.append('api_key', field(panel, 'everyaction_api_key'));
        data.append('username', field(panel, 'everyaction_username'));
        data.append('password', field(panel, 'everyaction_password'));
        data.append('tracker', field(panel, 'everyaction_tracker_id'));
        return fetch((window.sfafAdmin && window.sfafAdmin.ajaxUrl) || '', {
            method: 'POST',
            credentials: 'same-origin',
            body: data
        }).then(function (res) {
            return res.json().catch(function () {
                return { success: false, data: { message: 'WordPress answered HTTP ' + res.status + ' with something that is not JSON.' } };
            });
        });
    }

    function say(el, text, ok) {
        el.textContent = text;
        el.classList.remove('notice', 'notice-success', 'notice-error');
        el.classList.add('notice', ok ? 'notice-success' : 'notice-error');
        el.hidden = false;
    }

    function init() {
        var panel = document.querySelector('.uc-everyaction-panel');
        if (!panel) { return; }

        var testBtn  = panel.querySelector('.uc-everyaction-connect');
        var pill     = panel.querySelector('.uc-everyaction-pill');
        var badge    = panel.querySelector('.uc-everyaction-panel-status');
        var testMsg  = panel.querySelector('.uc-everyaction-conn-msg');
        var probeBtn = panel.querySelector('.uc-everyaction-probe');
        var probeMsg = panel.querySelector('.uc-everyaction-probe-msg');
        var probeOut = panel.querySelector('.uc-everyaction-probe-out');

        if (testBtn) {
            testBtn.addEventListener('click', function (e) {
                e.preventDefault();
                var label = testBtn.textContent;
                testBtn.disabled = true;
                testBtn.textContent = 'Testing…';
                pill.classList.remove('is-connected');
                pill.textContent = 'Testing…';
                post(panel, 'sfaf_everyaction_test').then(function (res) {
                    var ok = !!(res && res.success);
                    var data = (res && res.data) || {};
                    pill.classList.toggle('is-connected', ok);
                    pill.textContent = ok ? 'Connected' : 'Not connected';
                    if (badge) {
                        badge.textContent = ok ? 'Connected' : 'Not connected';
                        badge.classList.toggle('uc-status-connected', ok);
                        badge.classList.toggle('uc-status-pending', !ok);
                    }
                    say(testMsg, data.message || (ok ? 'Connected.' : 'The test failed and gave no reason.'), ok);
                }).catch(function () {
                    pill.textContent = 'Not connected';
                    say(testMsg, 'The request to WordPress itself failed.', false);
                }).then(function () {
                    testBtn.disabled = false;
                    testBtn.textContent = label;
                });
            });
        }

        if (probeBtn) {
            probeBtn.addEventListener('click', function (e) {
                e.preventDefault();
                var label = probeBtn.textContent;
                probeBtn.disabled = true;
                probeBtn.textContent = 'Probing…';
                probeOut.hidden = true;
                probeOut.textContent = '';
                post(panel, 'sfaf_everyaction_probe').then(function (res) {
                    var d = (res && res.data) || {};
                    if (!res || !res.success) {
                        say(probeMsg, d.message || 'The probe failed and gave no reason.', false);
                        return;
                    }
                    if (d.error) {
                        say(probeMsg, d.error + (d.logout ? ' ' + d.logout : ''), false);
                        return;
                    }
                    var rows = (d.total !== null && d.total !== undefined)
                        ? d.total + ' rows by the hub\'s count'
                        : (d.rows !== null && d.rows !== undefined)
                            ? d.rows + ' rows in this page, counted at ' + d.where
                            : 'no list of rows found in the reply';
                    say(probeMsg,
                        'GET ' + d.url + ': HTTP ' + d.status + ', ' + (d.type || 'no content type') + ', ' + d.length + ' bytes, '
                        + rows + '.' + (d.truncated ? ' Shown up to ' + d.max_body + ' bytes; the rest is cut.' : '')
                        + ' ' + d.logout + ' Nothing was imported or changed.',
                        !!d.ok);
                    probeOut.textContent = d.body === '' ? '(empty body)' : d.body;
                    probeOut.hidden = false;
                }).catch(function () {
                    say(probeMsg, 'The request to WordPress itself failed.', false);
                }).then(function () {
                    probeBtn.disabled = false;
                    probeBtn.textContent = label;
                });
            });
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
