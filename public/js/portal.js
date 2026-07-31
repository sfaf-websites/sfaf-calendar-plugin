/**
 * SFAF Calendar — /caladmin portal (standalone, vanilla JS).
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        initSidebar();
        initRepeaters();
        initImagePicker();
        initConfirmButtons();
        initCompleteness();
        initAsyncActions();
    });

    /**
     * Slow actions that need to say they are working.
     *
     * "Fetch updates" is several seconds of remote HTTP. As a plain form POST
     * it gave no sign it had started, so people pressed it again; and when it
     * failed the browser showed an error page or nothing at all, with no way
     * back to the screen they were on.
     *
     * The form is taken over here rather than replaced: the markup still posts
     * normally without JavaScript, and the server still stores its report in
     * the same transient the redirect target reads either way. What this adds
     * is a disabled button with a spinner while it runs, a real navigation on
     * success, and a visible message with the button restored on failure.
     */
    function initAsyncActions() {
        document.querySelectorAll('form[data-uc-async]').forEach(function (form) {
            var url = form.getAttribute('data-uc-ajax');
            var action = form.getAttribute('data-uc-action');
            var nonce = form.getAttribute('data-uc-ajax-nonce');
            if (!url || !action || !nonce || !window.fetch) {
                return; // leave the plain POST in place
            }
            var button = form.querySelector('button[type="submit"], button:not([type])');
            if (!button) {
                return;
            }
            var busyText = form.getAttribute('data-uc-busy') || 'Working…';

            form.addEventListener('submit', function (e) {
                e.preventDefault();
                if (button.disabled) {
                    return;
                }
                var restore = button.innerHTML;
                button.disabled = true;
                button.setAttribute('aria-busy', 'true');
                button.innerHTML = '<span class="uc-spinner" aria-hidden="true"></span>' + busyText;
                clearActionError(form);

                var body = new URLSearchParams();
                body.append('action', action);
                body.append('nonce', nonce);

                function fail(message) {
                    // Never silently put the button back: a restored button
                    // with nothing said reads as "nothing happened", which is
                    // exactly the wrong conclusion.
                    button.disabled = false;
                    button.removeAttribute('aria-busy');
                    button.innerHTML = restore;
                    showActionError(form, message);
                }

                fetch(url, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                    body: body.toString()
                }).then(function (res) {
                    return res.json().catch(function () {
                        throw new Error('The server replied with something unreadable (HTTP ' + res.status + ').');
                    });
                }).then(function (json) {
                    if (json && json.success && json.data && json.data.redirect) {
                        window.location.href = json.data.redirect;
                        return;
                    }
                    var msg = (json && json.data && json.data.message)
                        ? json.data.message
                        : 'That did not complete, and the server did not say why.';
                    fail(msg);
                }).catch(function (err) {
                    fail((err && err.message) ? err.message : 'The request could not be completed. Check your connection and try again.');
                });
            });
        });
    }

    function showActionError(form, message) {
        var node = form.parentNode.querySelector('.uc-action-error[data-uc-for-form="1"]');
        if (!node) {
            node = document.createElement('p');
            node.className = 'uc-action-error';
            node.setAttribute('data-uc-for-form', '1');
            node.setAttribute('role', 'alert');
            form.parentNode.appendChild(node);
        }
        node.textContent = message;
    }

    function clearActionError(form) {
        var node = form.parentNode.querySelector('.uc-action-error[data-uc-for-form="1"]');
        if (node) {
            node.remove();
        }
    }

    /* Event form: featured image picker (wp.media) + URL fallback */
    function initImagePicker() {
        var chooseBtn = document.querySelector('.uc-choose-image');
        if (!chooseBtn) {
            return;
        }
        var idInput    = document.getElementById('uc-featured-image-id');
        var urlInput   = document.getElementById('uc-image-url');
        var preview    = document.getElementById('uc-image-preview');
        var previewImg = document.getElementById('uc-image-preview-img');
        var removeBtn  = document.querySelector('.uc-remove-image');
        var frame;

        function show(src) {
            if (!src) { return; }
            previewImg.src = src;
            preview.style.display = '';
            if (removeBtn) { removeBtn.style.display = ''; }
        }
        function hide() {
            preview.style.display = 'none';
            previewImg.src = '';
            if (removeBtn) { removeBtn.style.display = 'none'; }
        }

        chooseBtn.addEventListener('click', function (e) {
            e.preventDefault();
            if (typeof wp === 'undefined' || !wp.media) {
                window.alert('Media library is unavailable here. Paste an image URL instead.');
                return;
            }
            if (frame) { frame.open(); return; }
            frame = wp.media({
                title: 'Select Featured Image',
                button: { text: 'Use this image' },
                multiple: false
            });
            frame.on('select', function () {
                var att = frame.state().get('selection').first().toJSON();
                idInput.value = att.id;
                var src = (att.sizes && att.sizes.medium) ? att.sizes.medium.url : att.url;
                show(src);
                // Setting .value in script fires neither input nor change, so
                // the live completeness check has to be told by hand. Without
                // this, choosing an image left the field amber.
                if (window.sfafRefreshCompleteness) { window.sfafRefreshCompleteness(); }
            });
            frame.open();
        });

        if (urlInput) {
            urlInput.addEventListener('input', function () {
                // Only drive the preview from the URL when no media image is chosen.
                if (idInput.value) { return; }
                if (urlInput.value) { show(urlInput.value); } else { hide(); }
            });
        }

        if (removeBtn) {
            removeBtn.addEventListener('click', function (e) {
                e.preventDefault();
                idInput.value = '';
                if (urlInput) { urlInput.value = ''; }
                hide();
                if (window.sfafRefreshCompleteness) { window.sfafRefreshCompleteness(); }
            });
        }
    }

    /* Mobile sidebar toggle */
    function initSidebar() {
        var btn = document.getElementById('uc-menu-btn');
        var sidebar = document.getElementById('uc-sidebar');
        if (!btn || !sidebar) {
            return;
        }
        btn.addEventListener('click', function () {
            sidebar.classList.toggle('open');
        });
        document.addEventListener('click', function (e) {
            if (window.innerWidth <= 720 && sidebar.classList.contains('open') &&
                !sidebar.contains(e.target) && e.target !== btn) {
                sidebar.classList.remove('open');
            }
        });
    }

    /* Settings repeater rows (GoFundMe / Pardot campaigns) */
    function initRepeaters() {
        document.querySelectorAll('.uc-repeater').forEach(function (rep) {
            var rows = rep.querySelector('.uc-repeater-rows');
            var tpl = rep.querySelector('.uc-repeater-tpl');
            var addBtn = rep.querySelector('.uc-repeater-add');
            // Start the index above any server-rendered rows.
            var counter = rows ? rows.querySelectorAll('.uc-repeater-row').length : 0;

            if (addBtn && tpl && rows) {
                addBtn.addEventListener('click', function () {
                    var html = tpl.innerHTML.replace(/__I__/g, 'new-' + counter);
                    counter++;
                    var wrap = document.createElement('div');
                    wrap.innerHTML = html.trim();
                    var node = wrap.firstChild;
                    rows.appendChild(node);
                });
            }

            rep.addEventListener('click', function (e) {
                if (e.target.classList.contains('uc-repeater-remove')) {
                    e.preventDefault();
                    var row = e.target.closest('.uc-repeater-row');
                    if (row) {
                        row.remove();
                    }
                }
            });
        });
    }

    /* Publish confirmation for events still missing manager-owned fields.
     *
     * A WARNING, NOT A BLOCK. There are real reasons to publish before the
     * image and description are written, so this names what is missing and
     * then gets out of the way.
     *
     * The attribute is read AT CLICK TIME and the listener is bound to any
     * button that could ever carry one, because initCompleteness() adds and
     * removes data-uc-confirm while the manager works. Binding only to the
     * buttons that already had the attribute would have frozen the warning at
     * page load — which is the bug this pair of functions exists to fix. */
    function initConfirmButtons() {
        var sel = '[data-uc-confirm], [data-uc-confirm-template]';
        document.querySelectorAll(sel).forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                var message = btn.getAttribute('data-uc-confirm');
                if (message && !window.confirm(message)) {
                    e.preventDefault();
                }
            });
        });
    }

    /* ---------------------------------------------------------------------
     * The live completeness check.
     *
     * ONE LIST, ASKED CONTINUOUSLY. The fields, their wording and the controls
     * that fill them all come from SFAF_Sources::completeness_fields() and are
     * handed over as JSON; nothing about which field is which is written down
     * again here. Everything this touches — the amber highlight on a field, the
     * banner at the top of the form, and the sentence on the Publish button —
     * is recomputed from that one answer, so the three cannot say different
     * things and none of them can go stale while somebody types.
     *
     * Before this existed all three were rendered once, server-side, from the
     * state at page load. Entering an image and a description therefore left
     * every one of them still asking for both.
     * ------------------------------------------------------------------- */
    function initCompleteness() {
        var node = document.getElementById('uc-completeness-data');
        if (!node) {
            return;
        }
        var fields;
        try {
            fields = JSON.parse(node.textContent || '[]');
        } catch (err) {
            return; // leave the server-rendered state exactly as it is
        }
        if (!fields.length) {
            return;
        }

        var form = node.closest ? node.closest('form') : null;
        if (!form) {
            return;
        }
        var banner = document.querySelector('[data-uc-missing-banner]');
        var text = document.querySelector('[data-uc-missing-text]');
        var publish = form.querySelector('[data-uc-confirm-template]');

        /* A control counts as filled when its trimmed value is neither empty
         * nor "0" — "0" is the None option on the category and organizer
         * selects and the no-attachment value of the featured-image field.
         * Same rule as SFAF_Sources::completeness_fields() documents. */
        function controlFilled(el) {
            if (!el || el.disabled) {
                return false;
            }
            var v = (el.value || '').trim();
            return v !== '' && v !== '0';
        }

        /* A field is filled when ANY of its controls is: the image is filled by
         * a chosen attachment OR a pasted URL, exactly as field_is_filled()
         * treats it server-side. */
        function fieldFilled(entry) {
            var i;
            var controls = [];
            for (i = 0; i < entry.inputs.length; i++) {
                controls = controls.concat(
                    Array.prototype.slice.call(
                        form.querySelectorAll('[name="' + entry.inputs[i] + '"]')
                    )
                );
            }
            // A field whose controls are not on this form at all keeps whatever
            // the server decided, rather than being declared empty by absence.
            if (!controls.length) {
                return !!entry.filled;
            }
            for (i = 0; i < controls.length; i++) {
                if (controlFilled(controls[i])) {
                    return true;
                }
            }
            return false;
        }

        /* "an image, a description and a category" — the same joining as
         * SFAF_Sources::field_phrase(). */
        function phrase(list) {
            if (!list.length) {
                return '';
            }
            if (list.length === 1) {
                return list[0];
            }
            return list.slice(0, -1).join(', ') + ' and ' + list[list.length - 1];
        }

        function toggle(el, hidden) {
            if (!el) {
                return;
            }
            if (hidden) {
                el.setAttribute('hidden', 'hidden');
            } else {
                el.removeAttribute('hidden');
            }
        }

        function refresh() {
            var missing = [];

            fields.forEach(function (entry) {
                var filled = fieldFilled(entry);
                if (!filled) {
                    missing.push(entry.phrase);
                }
                var wrap = form.querySelector('[data-uc-field="' + entry.field + '"]');
                if (!wrap) {
                    return;
                }
                wrap.classList.toggle('uc-field-attention', !filled);
                toggle(wrap.querySelector('[data-uc-attention-badge]'), filled);
                toggle(wrap.querySelector('[data-uc-attention-note]'), filled);
            });

            if (text) {
                text.textContent = phrase(missing);
            }
            toggle(banner, missing.length === 0);

            if (publish) {
                if (missing.length) {
                    publish.setAttribute(
                        'data-uc-confirm',
                        publish.getAttribute('data-uc-confirm-template').replace('%s', phrase(missing))
                    );
                } else {
                    publish.removeAttribute('data-uc-confirm');
                }
            }
        }

        /* input covers typing, change covers the selects and the media picker
         * (which sets the hidden field's value and fires nothing on its own —
         * hence the explicit refresh from initImagePicker). */
        form.addEventListener('input', refresh);
        form.addEventListener('change', refresh);
        window.sfafRefreshCompleteness = refresh;

        refresh();
    }
})();
