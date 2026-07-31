/**
 * Inline email validation, shared by every surface the plugin has.
 *
 * ONE FILE, THREE CONTEXTS: the public RSVP and reminder modals, the /caladmin
 * portal, and the WordPress admin. Deliberately dependency-free vanilla JS with
 * no build step, because the portal prints its own document and cannot rely on
 * jQuery or on anything wp-admin enqueues.
 *
 * WHAT WAS WRONG WITH WHAT IT REPLACES
 * ---------------------------------------------------------------------------
 * An invalid address produced the browser's own validation bubble: it appeared
 * on submit, floated over the page, and vanished the moment the pointer moved.
 * Nothing marked the field itself, so once the bubble had gone there was no way
 * to tell which of six inputs was the problem, and a screen reader user got
 * nothing at all. This replaces it with state that stays put:
 *
 *   - a red border on the offending field, until it is corrected
 *   - the message BELOW the field, in the document flow, not a tooltip and not
 *     a title attribute
 *   - aria-invalid on the input and aria-describedby pointing at the message,
 *     so it is announced rather than merely drawn
 *   - cleared the moment the value becomes valid, on every keystroke
 *
 * SPECIFIC MESSAGES, NOT "INVALID". "kgkg.ff.com" is a perfectly good domain
 * and a perfectly bad email address, and being told it is "invalid" tells you
 * nothing you did not know. Being told to include an @ tells you what to do.
 *
 * The native bubble is switched off (form.noValidate) ONLY on forms this script
 * has taken over, and only once it is running, so a browser with JavaScript
 * disabled still gets the native validation rather than none.
 */
(function () {
    'use strict';

    var ERROR_CLASS = 'uc-field-error';
    var INVALID_CLASS = 'uc-invalid';
    var idCounter = 0;

    /**
     * What is wrong with this address, in words the person can act on.
     *
     * Deliberately a sequence of specific checks rather than one regex: a regex
     * can only say yes or no, and "no" is exactly the answer that does not
     * help. Order matters, cheapest and most likely first.
     *
     * @param {string} raw
     * @param {boolean} required
     * @returns {string} '' when the value is acceptable.
     */
    function describeProblem(raw, required) {
        var value = (raw || '').trim();

        if (value === '') {
            return required ? 'Enter an email address.' : '';
        }
        if (/\s/.test(value)) {
            return 'An email address cannot contain spaces.';
        }

        var at = value.split('@');
        if (at.length === 1) {
            // The case that started this: a bare domain looks like an address
            // to a person and is not one.
            return 'Enter an email address, including an @.';
        }
        if (at.length > 2) {
            return 'An email address can only have one @.';
        }

        var local = at[0];
        var domain = at[1];

        if (local === '') {
            return 'Add the part before the @, like name@example.org.';
        }
        if (domain === '') {
            return 'Add the domain after the @, like example.org.';
        }
        if (domain.indexOf('.') === -1) {
            return 'The domain after the @ needs a dot, like example.org.';
        }
        if (/^\.|\.$/.test(domain) || domain.indexOf('..') !== -1) {
            return 'That domain is not quite right. Check the dots.';
        }
        if (!/^[^.]+(\.[^.]+)+$/.test(domain)) {
            return 'Add the domain after the @, like example.org.';
        }
        // The last label wants to look like a real suffix.
        if (!/\.[A-Za-z]{2,}$/.test(domain)) {
            return 'The domain should end in something like .org or .com.';
        }
        return '';
    }

    /** The message element that belongs to a field, created on demand. */
    function errorNodeFor(input) {
        var existing = input.parentNode
            ? input.parentNode.querySelector('.' + ERROR_CLASS + '[data-uc-for="' + input.id + '"]')
            : null;
        if (existing) {
            return existing;
        }
        if (!input.id) {
            idCounter++;
            input.id = 'uc-email-field-' + idCounter;
        }
        var node = document.createElement('p');
        node.className = ERROR_CLASS;
        node.id = input.id + '-error';
        node.setAttribute('data-uc-for', input.id);
        // Announced when it appears, without stealing focus from the field the
        // person is still typing in.
        node.setAttribute('role', 'alert');
        // Inserted directly after the input so it reads in the right order
        // whether or not the surrounding markup is a <label> wrapper.
        if (input.nextSibling) {
            input.parentNode.insertBefore(node, input.nextSibling);
        } else {
            input.parentNode.appendChild(node);
        }
        return node;
    }

    function showError(input, message) {
        var node = errorNodeFor(input);
        node.textContent = message;
        node.style.display = '';
        input.classList.add(INVALID_CLASS);
        input.setAttribute('aria-invalid', 'true');

        // Preserve any describedby the markup already had.
        var described = (input.getAttribute('aria-describedby') || '').split(/\s+/).filter(Boolean);
        if (described.indexOf(node.id) === -1) {
            described.push(node.id);
            input.setAttribute('aria-describedby', described.join(' '));
        }
    }

    function clearError(input) {
        var node = input.parentNode
            ? input.parentNode.querySelector('.' + ERROR_CLASS + '[data-uc-for="' + input.id + '"]')
            : null;
        input.classList.remove(INVALID_CLASS);
        input.removeAttribute('aria-invalid');
        if (!node) {
            return;
        }
        node.textContent = '';
        node.style.display = 'none';
        var described = (input.getAttribute('aria-describedby') || '')
            .split(/\s+/).filter(function (id) { return id && id !== node.id; });
        if (described.length) {
            input.setAttribute('aria-describedby', described.join(' '));
        } else {
            input.removeAttribute('aria-describedby');
        }
    }

    /**
     * A textarea holding one address per line (the per-event notification
     * list). Reported by line number, because "one of these eight is wrong" is
     * not a useful thing to tell somebody.
     */
    function checkList(field) {
        var lines = (field.value || '').split(/[\r\n,;]+/);
        var bad = [];
        for (var i = 0; i < lines.length; i++) {
            var line = lines[i].trim();
            if (line === '') {
                continue;
            }
            if (describeProblem(line, true) !== '') {
                bad.push(line);
            }
        }
        if (bad.length) {
            showError(field, (bad.length === 1 ? 'This is not an email address: ' : 'These are not email addresses: ') + bad.join(', '));
            return false;
        }
        clearError(field);
        return true;
    }

    function checkOne(field) {
        if (field.hasAttribute('data-uc-email-list')) {
            return checkList(field);
        }
        var required = field.required || field.hasAttribute('data-uc-email-required');
        var problem = describeProblem(field.value, required);
        if (problem) {
            showError(field, problem);
            return false;
        }
        clearError(field);
        return true;
    }

    /** Fields this script owns: every email input, plus the opted-in lists. */
    function fieldsIn(root) {
        return (root || document).querySelectorAll('input[type="email"], [data-uc-email], [data-uc-email-list]');
    }

    function attach(field) {
        if (field.getAttribute('data-uc-email-bound') === '1') {
            return;
        }
        field.setAttribute('data-uc-email-bound', '1');

        // On blur: first judgement, once they have finished typing.
        field.addEventListener('blur', function () {
            checkOne(field);
        });

        // On input: only ever CLEARS. Marking a half-typed address as wrong on
        // the second keystroke is technically accurate and genuinely horrible,
        // so an error appears on blur or on submit and disappears the instant
        // the value becomes acceptable.
        field.addEventListener('input', function () {
            if (field.classList.contains(INVALID_CLASS)) {
                checkOne(field);
            }
        });

        var form = field.form || field.closest('form');
        if (form && !form.hasAttribute('data-uc-email-form')) {
            form.setAttribute('data-uc-email-form', '1');
            // Our messages, not the browser's floating bubble. Set from
            // JavaScript so that without JavaScript the native validation is
            // still there.
            form.noValidate = true;
            form.addEventListener('submit', function (e) {
                var first = null;
                fieldsIn(form).forEach(function (f) {
                    if (!checkOne(f) && !first) {
                        first = f;
                    }
                });
                if (first) {
                    e.preventDefault();
                    e.stopPropagation();
                    first.focus();
                }
            });
        }
    }

    /** Bind everything under a root. Safe to call repeatedly. */
    function init(root) {
        fieldsIn(root).forEach(attach);
    }

    /**
     * Validate an ad-hoc field that is not inside a form we control (the RSVP
     * and reminder modals are built by JavaScript and submitted over AJAX).
     *
     * @returns {boolean}
     */
    function validate(field) {
        if (!field) {
            return true;
        }
        attach(field);
        return checkOne(field);
    }

    window.sfafEmail = {
        init: init,
        validate: validate,
        clear: clearError,
        describe: describeProblem
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { init(document); });
    } else {
        init(document);
    }
})();
