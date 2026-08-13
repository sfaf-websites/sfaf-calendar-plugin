/**
 * SFAF Calendar — /caladmin portal (standalone, vanilla JS).
 */
(function () {
    'use strict';

    /**
     * Run one initialiser without letting it take the others down.
     *
     * WHY THIS EXISTS. These ran as a bare list, so a single exception in any
     * one of them silently killed every one below it: no error visible on the
     * page, no clue which screen was affected, and the features nearest the
     * bottom of the list the most likely to be missing. That is a whole class
     * of bug that reports as "X is not working" and points nowhere.
     *
     * A failure is now contained to its own feature and named in the console.
     * Never swallowed silently: a caught exception nobody can see is the same
     * bug wearing a different hat.
     */
    function run(name, fn) {
        try {
            fn();
        } catch (err) {
            if (window.console && window.console.error) {
                window.console.error('SFAF caladmin: ' + name + ' failed and was skipped.', err);
            }
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        run('sidebar', initSidebar);
        run('repeaters', initRepeaters);
        run('faqSetPicker', initFaqSetPicker);
        run('notifyPicker', initNotifyPicker);
        run('imagePicker', initImagePicker);
        // ORDER MATTERS between these three. Both the scope confirmation and
        // the completeness confirmation bind a click listener to the same save
        // buttons, and listeners on one element fire in the order they were
        // registered. The scope one has to go first: a manager who decides not
        // to update twelve events should never then be asked about a missing
        // image on an event they have just decided not to save.
        run('helpToggles', initHelpToggles);
        run('categoryChips', initCategoryChips);
        run('recurrence', initRecurrence);
        run('emailPills', initEmailPills);
        run('locationPicker', initLocationPicker);
        run('faqSaveAsSet', initFaqSaveAsSet);
        run('liveSearch', initLiveSearch);
        run('editScope', initEditScope);
        run('confirmButtons', initConfirmButtons);
        run('completeness', initCompleteness);
        run('asyncActions', initAsyncActions);
        run('disclosures', initDisclosures);
        run('filterLists', initFilterLists);
    });

    /* ---------------------------------------------------------------------
     * Disclosures: keep aria-expanded in step with the <details> state.
     *
     * The disclosure works with no script at all, <details> is what opens and
     * closes it, and the chevron is rotated by CSS off the [open] attribute.
     * What a <summary> does NOT reliably expose is aria-expanded: browsers vary
     * on whether the details state reaches the accessibility tree as one, and
     * the attribute is what a screen reader announces. So it is written into
     * the markup closed, and corrected here on every toggle.
     * ------------------------------------------------------------------ */
    function initDisclosures() {
        document.querySelectorAll('details[data-uc-disclosure]').forEach(function (d) {
            var summary = d.querySelector('summary');
            if (!summary) { return; }
            function sync() { summary.setAttribute('aria-expanded', d.open ? 'true' : 'false'); }
            sync();
            d.addEventListener('toggle', sync);
        });
    }

    /* ---------------------------------------------------------------------
     * Type-to-filter over any list of choices.
     *
     * THE NOTIFY PICKER HAD THIS AND NOTHING ELSE COULD USE IT. Its filter is
     * bound inside initNotifyPicker(), scoped to that picker's root and keyed
     * on data-uc-picker-filter="people", so the team member picker would have
     * meant a second copy of the same twenty lines. This is the same behaviour
     * with the container found from the input rather than named by it: an
     * <input data-uc-filter> filters the [data-uc-filter-text] elements inside
     * the nearest [data-uc-filter-list] after it, and toggles the
     * [data-uc-filter-empty] note.
     *
     * A TICKED CHOICE IS NEVER FILTERED OUT OF SIGHT, which is the one rule
     * that matters here and the reason this is not a plain string match.
     * Losing track of somebody already chosen is how a filter turns into an
     * accidental deselection, and on this screen the choices are staged and
     * not yet saved.
     *
     * THE EMPTY NOTE RUNS ONCE ON LOAD, so the message on screen always
     * describes the list on screen, and it only ever answers a query: with the
     * box empty there is nothing to fail to match.
     * ------------------------------------------------------------------ */
    function initFilterLists() {
        document.querySelectorAll('input[data-uc-filter]').forEach(function (input) {
            // The list is the closest following one within a shared ancestor,
            // so a screen may carry several without them reaching each other.
            var scope = input.closest('[data-uc-filter-scope]') || input.parentNode.parentNode;
            var list = scope ? scope.querySelector('[data-uc-filter-list]') : null;
            if (!list) { return; }
            var note = scope.querySelector('[data-uc-filter-empty]');

            function apply() {
                var q = input.value.replace(/\s+/g, ' ').trim().toLowerCase();
                var shown = 0;
                Array.prototype.forEach.call(
                    list.querySelectorAll('[data-uc-filter-text]'),
                    function (opt) {
                        var hay = opt.getAttribute('data-uc-filter-text') || '';
                        var box = opt.querySelector('input[type="checkbox"]');
                        var keep = !q || hay.indexOf(q) !== -1 || (box && box.checked);
                        opt.hidden = !keep;
                        if (keep) { shown++; }
                    }
                );
                if (note) { note.hidden = (shown !== 0 || q === ''); }
            }

            input.addEventListener('input', apply);
            // Escape clears the filter before the browser closes the <details>
            // out from under somebody mid-search.
            input.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && input.value !== '') {
                    e.preventDefault();
                    e.stopPropagation();
                    input.value = '';
                    apply();
                }
            });
            apply();
        });
    }

    /* ---------------------------------------------------------------------
     * NO ENTRANCE CODE IN THIS FILE. It moved to an inline script in
     * SFAF_Portal::head() in 3.17.0, and the move is the fix rather than a
     * tidy-up: from here it could only ever run on DOMContentLoaded, which is
     * after the first paint, so the page drew itself complete and only then
     * dropped to opacity 0 to fade back in. It was also the last call in this
     * list, where any earlier initialiser throwing would have removed it in
     * silence. In the head it lands before anything is painted, and the
     * animation itself is pure CSS afterwards.
     * ------------------------------------------------------------------ */

    /* ---------------------------------------------------------------------
     * Help disclosures: a "?" beside a label opening a paragraph.
     *
     * THE REGION IS VISIBLE UNTIL THIS RUNS, and that is the whole no-script
     * story: with the file missing, every explanation is simply on the page as
     * it used to be. Nothing is ever hidden behind a control that cannot open.
     *
     * A BUTTON, NOT A HOVER. Hover cannot be produced by a touch screen or by a
     * keyboard, and `title` is announced inconsistently and cannot hold a
     * sentence worth reading. This is the plain disclosure pattern: click, tap,
     * Enter and Space all work because they are what a <button> already does,
     * and aria-expanded says which way it is.
     * ------------------------------------------------------------------- */
    function initHelpToggles() {
        document.querySelectorAll('[data-uc-help-toggle]').forEach(function (btn) {
            var id = btn.getAttribute('aria-controls');
            var body = id ? document.getElementById(id) : null;
            if (!body) {
                return; // leave the paragraph where it is rather than orphan it
            }

            body.hidden = true;
            btn.setAttribute('aria-expanded', 'false');

            btn.addEventListener('click', function () {
                var open = btn.getAttribute('aria-expanded') === 'true';
                btn.setAttribute('aria-expanded', open ? 'false' : 'true');
                body.hidden = open;
            });

            // Escape closes it without moving focus somewhere unexpected, and
            // stops there rather than reaching whatever else listens for it.
            btn.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && btn.getAttribute('aria-expanded') === 'true') {
                    e.stopPropagation();
                    btn.setAttribute('aria-expanded', 'false');
                    body.hidden = true;
                }
            });
        });
    }

    /* ---------------------------------------------------------------------
     * Categories as chips.
     *
     * THE CHECKBOXES ARE STILL THE FORM. Every one of them is rendered by the
     * server, every one of them posts, and with this function deleted the
     * control is the checkbox list it has always been. What happens here is
     * that the list is folded away behind an "Add category" button and the
     * ticked ones are mirrored as removable chips, because that is what a
     * category looks like on a card, on the event page and in the filter bar,
     * and the editor was the one place it did not.
     *
     * NOTHING IS STORED TWICE. A chip has no state: it is drawn from the
     * checkbox and its × unticks the checkbox. There is no second list to fall
     * out of step with the first.
     * ------------------------------------------------------------------- */
    function initCategoryChips() {
        document.querySelectorAll('[data-uc-chips]').forEach(function (root) {
            var source = root.querySelector('[data-uc-chips-source]');
            var list = root.querySelector('[data-uc-chips-list]');
            var empty = root.querySelector('[data-uc-chips-empty]');
            var addWrap = root.querySelector('[data-uc-chips-add]');
            var toggle = root.querySelector('[data-uc-chips-toggle]');
            if (!source || !list || !addWrap || !toggle) {
                return;
            }

            var boxes = Array.prototype.slice.call(source.querySelectorAll('input[type="checkbox"]'));
            if (!boxes.length) {
                return;
            }

            function labelOf(box) {
                var label = box.closest ? box.closest('label') : null;
                return (label && label.getAttribute('data-uc-chip-label')) || (label ? label.textContent.trim() : '');
            }

            function draw() {
                list.innerHTML = '';
                var chosen = 0;

                boxes.forEach(function (box) {
                    if (!box.checked) {
                        return;
                    }
                    chosen++;
                    var chip = document.createElement('span');
                    chip.className = 'uc-chip';
                    var color = box.getAttribute('data-uc-chip-color');
                    if (color) {
                        chip.style.setProperty('--chip-color', color);
                    }

                    var text = document.createElement('span');
                    text.className = 'uc-chip-text';
                    text.textContent = labelOf(box);
                    chip.appendChild(text);

                    // A chip is only removable while the field is editable. On
                    // an imported event whose categories a platform owns, the
                    // checkbox is disabled and the chip is a label.
                    //
                    // The `uc-inert` half of this test went with the pencils in
                    // 3.23.0: that class was how the scope lock made a checkbox
                    // unusable, since a checkbox has no readonly. Nothing sets
                    // it now, so `disabled` is the whole question again.
                    if (!box.disabled) {
                        var kill = document.createElement('button');
                        kill.type = 'button';
                        kill.className = 'uc-chip-remove';
                        kill.setAttribute('aria-label', 'Remove ' + labelOf(box));
                        kill.innerHTML = '&times;';
                        kill.addEventListener('click', function () {
                            box.checked = false;
                            draw();
                            toggle.focus();
                        });
                        chip.appendChild(kill);
                    }

                    list.appendChild(chip);
                });

                list.hidden = (chosen === 0);
                if (empty) { empty.hidden = (chosen !== 0); }
            }

            boxes.forEach(function (box) {
                box.addEventListener('change', draw);
            });

            toggle.addEventListener('click', function () {
                var open = source.hasAttribute('hidden');
                if (open) {
                    source.removeAttribute('hidden');
                } else {
                    source.setAttribute('hidden', 'hidden');
                }
                toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            });

            // Only now that the button works is the list allowed to fold away.
            source.setAttribute('hidden', 'hidden');
            source.classList.add('uc-chips-source');
            addWrap.removeAttribute('hidden');
            root.classList.add('uc-chips-on');
            draw();
        });
    }

    /* ---------------------------------------------------------------------
     * Location: a venue, or somewhere one-off.
     *
     * Both panels are server-rendered and both submit; the radio decides which
     * one the save believes. All this does is hide the one that is not chosen,
     * so with scripting off the screen shows two controls and a choice rather
     * than nothing.
     * ------------------------------------------------------------------- */
    function initLocationPicker() {
        document.querySelectorAll('[data-uc-location]').forEach(function (root) {
            var modes = Array.prototype.slice.call(root.querySelectorAll('[data-uc-location-mode]'));
            if (!modes.length) {
                return;
            }

            function apply() {
                var chosen = 'custom';
                modes.forEach(function (m) { if (m.checked) { chosen = m.value; } });
                Array.prototype.forEach.call(root.querySelectorAll('[data-uc-location-panel]'), function (panel) {
                    panel.hidden = (panel.getAttribute('data-uc-location-panel') !== chosen);
                });
            }

            modes.forEach(function (m) { m.addEventListener('change', apply); });
            root.classList.add('uc-location-on');
            apply();
        });
    }

    /* ---------------------------------------------------------------------
     * "Save these as a set", from inside the FAQ card.
     *
     * The control belongs to a form declared outside the editor's form, by the
     * `form` attribute, because HTML forms cannot nest. That association is
     * plain HTML and works with scripting off, in which case the server saves
     * the questions as they are STORED on the event.
     *
     * What this adds is copying the rows currently ON SCREEN into that form
     * first, so a set can be saved from questions just typed without saving the
     * event. Same server action either way.
     * ------------------------------------------------------------------- */
    function initFaqSaveAsSet() {
        var form = document.getElementById('uc-faq-set-create');
        var holder = form ? form.querySelector('[data-uc-faq-set-rows]') : null;
        if (!form || !holder) {
            return;
        }

        var name = document.querySelector('[data-uc-faq-saveset] input[name="faq_set_name"]');

        form.addEventListener('submit', function (e) {
            if (name && !name.value.trim()) {
                e.preventDefault();
                name.focus();
                return;
            }

            holder.innerHTML = '';
            var i = 0;
            document.querySelectorAll('.uc-faq-card .uc-repeater-row.uc-faq-row').forEach(function (row) {
                var q = row.querySelector('input[type="text"]');
                var a = row.querySelector('textarea');
                if (!q || !a || q.disabled) {
                    return; // an imported row: not ours to copy into a set
                }
                if (!q.value.trim() && !a.value.trim()) {
                    return;
                }
                holder.appendChild(hidden('faq_set_rows[' + i + '][question]', q.value));
                holder.appendChild(hidden('faq_set_rows[' + i + '][answer]', a.value));
                i++;
            });
        });

        function hidden(n, v) {
            var el = document.createElement('input');
            el.type = 'hidden';
            el.name = n;
            el.value = v;
            return el;
        }
    }

    /* ---------------------------------------------------------------------
     * The events list search, as you type.
     *
     * SUBMITS THE REAL FORM, DEBOUNCED. The search is a server query, so this
     * cannot filter rows already on screen; and it should not fire a request
     * per keystroke either. 300ms after typing stops, the form goes, which
     * means the answer lands at a real URL with the term in it: shareable,
     * back-buttonable, and carrying the sort and filters that were already in
     * the form's own fields.
     *
     * THE CARET COMES BACK. A submit is a page load, so the server marks the
     * box for refocus when a term is present and the caret is put at the end of
     * it. Without that, every pause mid-word would drop focus and the next
     * letter would go nowhere.
     * ------------------------------------------------------------------- */
    function initLiveSearch() {
        var refocus = document.querySelector('[data-uc-refocus]');
        if (refocus) {
            refocus.focus();
            var end = refocus.value.length;
            try { refocus.setSelectionRange(end, end); } catch (err) { /* not all inputs allow it */ }
        }

        document.querySelectorAll('[data-uc-live-search]').forEach(function (form) {
            var input = form.querySelector('[data-uc-live-search-input]');
            if (!input) {
                return;
            }
            var timer = null;
            var last = input.value;

            function go() {
                if (input.value === last) {
                    return; // nothing changed: a stray keyup, or arrow keys
                }
                last = input.value;
                form.classList.add('uc-searching');
                if (typeof form.requestSubmit === 'function') {
                    form.requestSubmit();
                } else {
                    form.submit();
                }
            }

            input.addEventListener('input', function () {
                clearTimeout(timer);
                timer = setTimeout(go, 300);
            });
            // Enter should search now rather than wait out the debounce, and
            // should not double-submit afterwards.
            input.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' || e.keyCode === 13) {
                    clearTimeout(timer);
                }
            });
        });
    }

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

    /* ---------------------------------------------------------------------
     * Featured image picker (wp.media) + URL fallback.
     *
     * ONE PICKER PER FIELD, NOT ONE PER PAGE. This used to bind to
     * document.getElementById('uc-featured-image-id') and friends, which was
     * fine while the event editor was the only screen that carried the
     * control. The pending queue now renders the same manager-owned panel, one
     * per queued event, so those ids appear several times on a page and
     * getElementById would have wired every Choose Image button on the screen
     * to the first event's hidden input: choosing a picture for the third
     * campaign would have set it on the first.
     *
     * So the fields are found by data attribute, WITHIN each .uc-image-field,
     * and each one gets its own media frame. The ids are still unique per
     * event in the markup; nothing here depends on them.
     * ------------------------------------------------------------------ */
    function initImagePicker() {
        var fields = document.querySelectorAll('.uc-image-field');
        for (var i = 0; i < fields.length; i++) {
            bindImageField(fields[i]);
        }
    }

    function bindImageField(field) {
        var chooseBtn = field.querySelector('.uc-choose-image');
        if (!chooseBtn) {
            return;
        }
        var idInput    = field.querySelector('[data-uc-image-id]');
        var urlInput   = field.querySelector('[data-uc-image-url]');
        var preview    = field.querySelector('[data-uc-image-preview]');
        var previewImg = field.querySelector('[data-uc-image-preview-img]');
        var removeBtn  = field.querySelector('.uc-remove-image');
        var frame;

        if (!idInput || !preview || !previewImg) {
            return;
        }

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

    /* ---------------------------------------------------------------------
     * Apply a saved FAQ set, into the repeater, without leaving the page.
     *
     * COPY, IN THE BROWSER. The rows are written straight into the FAQ
     * repeater as ordinary new rows and are saved when the event is saved.
     * Nothing is posted here, so nothing typed into the form is lost, and the
     * manager can edit or delete any of the new rows before saving. That is
     * the whole difference from the server-side apply this replaces, which
     * posted and redirected and therefore threw the form away.
     *
     * APPEND ONLY. There is no replace mode. Existing rows are never rewritten
     * and never reordered, and a question that is already on the event is
     * skipped rather than added twice, matching SFAF_FAQ_Sets::apply().
     *
     * The imported rows a platform owns are not in this repeater at all: they
     * are rendered above it with no form fields, so there is nothing here that
     * could reach them. They are still counted when checking for duplicates,
     * because a set that repeats a question the campaign already answers
     * should not add it again.
     * ------------------------------------------------------------------- */
    function initFaqSetPicker() {
        var pickers = document.querySelectorAll('[data-uc-faq-picker]');
        if (!pickers.length) {
            return;
        }

        var applied = 0; // keeps the generated field indexes unique per page

        Array.prototype.forEach.call(pickers, function (picker) {
            var dataNode = picker.querySelector('[data-uc-faq-sets]');
            var select = picker.querySelector('[data-uc-faq-set]');
            var button = picker.querySelector('[data-uc-faq-apply]');
            var said = picker.querySelector('[data-uc-faq-said]');
            if (!dataNode || !select || !button) {
                return;
            }

            var sets;
            try {
                sets = JSON.parse(dataNode.textContent || '{}');
            } catch (err) {
                return; // leave the server-side form in place rather than half a control
            }

            /* The repeater this picker belongs to. Nearest one after the
             * picker inside the same block, so a page that grows a second
             * repeater later cannot silently start filling the wrong one. */
            var block = picker.parentElement;
            var rep = block ? block.querySelector('.uc-repeater') : null;
            var rows = rep ? rep.querySelector('.uc-repeater-rows') : null;
            var tpl = rep ? rep.querySelector('.uc-repeater-tpl') : null;
            if (!rows || !tpl) {
                return;
            }

            /* Case- and space-insensitive question identity. Deliberately the
             * same rule as SFAF_FAQ_Sets::fingerprint(), so applying here and
             * applying on the server skip the same rows. */
            function fingerprint(text) {
                return String(text == null ? '' : text).replace(/\s+/g, ' ').trim().toLowerCase();
            }

            /* Every question already on this event: the manager's own rows in
             * the repeater, and the platform's read-only rows above it. */
            function present() {
                var seen = {};
                Array.prototype.forEach.call(
                    block.querySelectorAll('.uc-faq-row input[type="text"]'),
                    function (input) {
                        var print = fingerprint(input.value);
                        if (print) { seen[print] = true; }
                    }
                );
                return seen;
            }

            function addRow(question, answer) {
                var html = tpl.innerHTML.replace(/__I__/g, 'set-' + applied);
                applied++;
                var wrap = document.createElement('div');
                wrap.innerHTML = html.trim();
                var node = wrap.firstChild;
                if (!node) {
                    return false;
                }
                var q = node.querySelector('input[type="text"]');
                var a = node.querySelector('textarea');
                if (q) { q.value = question; }
                if (a) { a.value = answer; }
                rows.appendChild(node);
                return true;
            }

            function report(message) {
                if (!said) {
                    return;
                }
                said.textContent = message;
                said.removeAttribute('hidden');
            }

            button.addEventListener('click', function () {
                var set = sets[select.value];
                if (!set || !set.rows || !set.rows.length) {
                    report('That set has no questions in it.');
                    return;
                }

                var seen = present();
                var added = 0;
                var skipped = 0;

                set.rows.forEach(function (row) {
                    var print = fingerprint(row.question);
                    if (print && seen[print]) {
                        skipped++;
                        return;
                    }
                    if (addRow(row.question || '', row.answer || '')) {
                        seen[print] = true;
                        added++;
                    }
                });

                var name = set.name || 'that set';
                if (!added) {
                    report('Every question in "' + name + '" is already on this event, so nothing was added.');
                } else {
                    report('Added ' + added + ' question' + (added === 1 ? '' : 's') + ' from "' + name + '"'
                        + (skipped ? ', and skipped ' + skipped + ' already here' : '')
                        + '. Save the event to keep them.');
                }

                /* The completeness check watches the form for changes and
                 * these rows were added by script, which fires no change
                 * event. Nudge it so the pre-publish warning stays honest. */
                if (added && typeof window.sfafRefreshCompleteness === 'function') {
                    window.sfafRefreshCompleteness();
                }
            });

            picker.removeAttribute('hidden');
        });

        /* The post-and-redirect version, now that the in-place one is live.
         * Only hidden once a picker above has actually initialised, so a
         * browser that fell out of any of the guards above keeps a control
         * that works. */
        if (document.querySelector('[data-uc-faq-picker]:not([hidden])')) {
            Array.prototype.forEach.call(
                document.querySelectorAll('[data-uc-faq-apply-fallback]'),
                function (form) { form.setAttribute('hidden', 'hidden'); }
            );
        }
    }

    /* ---------------------------------------------------------------------
     * The notification picker: two tabs, a filter, and a live count.
     *
     * ENHANCEMENT ONLY. The server sends a <details> holding both panels and
     * every checkbox, so with this function deleted the control still opens,
     * still shows individuals and teams, and still saves. What is added here
     * is switching between the panels instead of stacking them, filtering the
     * people list, and recomputing the summary as boxes are ticked.
     *
     * FILTERING HIDES, IT NEVER UNCHECKS. A hidden checkbox still posts, so
     * typing a name to find one person cannot quietly drop the four chosen a
     * minute ago. That is the one rule this must not get wrong.
     *
     * THE COUNT IS A SET OF ADDRESSES, NOT A SUM. Two individuals plus a team
     * of six is not eight if one of them is in the team, and the number a
     * manager reads has to be the number of emails that will be sent. So the
     * addresses are unioned, exactly as SFAF_Reminders::notify_list() unions
     * them server-side. The creator and any typed addresses are in the total
     * too, because they are also going to get one.
     * ------------------------------------------------------------------- */
    function initNotifyPicker() {
        document.querySelectorAll('[data-uc-notify-picker]').forEach(function (root) {
            var dataNode = root.querySelector('[data-uc-notify-data]');
            var details = root.querySelector('[data-uc-picker]');
            var countEl = root.querySelector('[data-uc-picker-count]');
            if (!dataNode || !details) {
                return;
            }

            var data;
            try {
                data = JSON.parse(dataNode.textContent || '{}');
            } catch (err) {
                return; // leave the plain list alone rather than half a control
            }
            data.users = data.users || {};
            data.teams = data.teams || {};

            /* ---- Tabs ---------------------------------------------------- */
            var tabs = Array.prototype.slice.call(root.querySelectorAll('[data-uc-picker-tab]'));
            var panels = {};
            Array.prototype.forEach.call(root.querySelectorAll('[data-uc-picker-panel]'), function (p) {
                panels[p.getAttribute('data-uc-picker-panel')] = p;
            });

            function selectTab(name, focus) {
                tabs.forEach(function (tab) {
                    var mine = tab.getAttribute('data-uc-picker-tab') === name;
                    tab.setAttribute('aria-selected', mine ? 'true' : 'false');
                    // Roving tabindex: one stop for the whole tablist, and the
                    // arrow keys move between the tabs inside it.
                    tab.setAttribute('tabindex', mine ? '0' : '-1');
                    if (mine && focus) { tab.focus(); }
                });
                Object.keys(panels).forEach(function (key) {
                    if (key === name) {
                        panels[key].removeAttribute('hidden');
                    } else {
                        panels[key].setAttribute('hidden', 'hidden');
                    }
                });
            }

            tabs.forEach(function (tab, i) {
                tab.addEventListener('click', function () {
                    selectTab(tab.getAttribute('data-uc-picker-tab'), false);
                });
                tab.addEventListener('keydown', function (e) {
                    var next = null;
                    if (e.key === 'ArrowRight' || e.key === 'ArrowDown') { next = (i + 1) % tabs.length; }
                    else if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') { next = (i - 1 + tabs.length) % tabs.length; }
                    else if (e.key === 'Home') { next = 0; }
                    else if (e.key === 'End') { next = tabs.length - 1; }
                    if (next !== null) {
                        e.preventDefault();
                        selectTab(tabs[next].getAttribute('data-uc-picker-tab'), true);
                    }
                });
            });

            // Only now that switching works are the panels allowed to hide.
            // Doing this before the listeners were bound would have left a
            // panel unreachable if anything above had thrown.
            root.classList.add('uc-picker-tabbed');
            selectTab('people', false);

            /* ---- Filter -------------------------------------------------- */
            var filter = root.querySelector('[data-uc-picker-filter="people"]');
            var emptyNote = root.querySelector('[data-uc-picker-empty="people"]');
            if (filter) {
                /*
                 * THE EMPTY NOTE IS DRIVEN FROM ONE PLACE, AND IT RUNS ONCE ON
                 * LOAD. It used to be updated only inside the input handler, so
                 * its state was whatever the markup said until somebody typed.
                 * Calling the same function at the end means the message on
                 * screen always describes the list on screen, including before
                 * anything has been typed at all.
                 */
                var applyFilter = function () {
                    var q = filter.value.replace(/\s+/g, ' ').trim().toLowerCase();
                    var shown = 0;
                    Array.prototype.forEach.call(
                        root.querySelectorAll('[data-uc-picker-search]'),
                        function (opt) {
                            var hay = opt.getAttribute('data-uc-picker-search') || '';
                            var box = opt.querySelector('input[type="checkbox"]');
                            // A chosen person is never filtered out of sight.
                            // Losing track of somebody already selected is how
                            // a filter turns into an accidental deselection.
                            var keep = !q || hay.indexOf(q) !== -1 || (box && box.checked);
                            opt.hidden = !keep;
                            if (keep) { shown++; }
                        }
                    );
                    if (emptyNote) {
                        // Only ever shown in answer to a query. With the box
                        // empty there is nothing to fail to match, so "Nobody
                        // matches that" would be answering a question nobody
                        // asked, which is how it came to be on screen before a
                        // single keystroke.
                        emptyNote.hidden = ( shown !== 0 || q === '' );
                    }
                };
                filter.addEventListener('input', applyFilter);
                applyFilter();
                // Escape clears the filter before the browser closes the
                // <details> out from under somebody mid-search.
                filter.addEventListener('keydown', function (e) {
                    if (e.key === 'Escape' && filter.value !== '') {
                        e.stopPropagation();
                        filter.value = '';
                        filter.dispatchEvent(new Event('input'));
                    }
                });
            }

            /* ---- The chosen, as chips ------------------------------------
             *
             * The picker list finds people; these hold the answer. Removing a
             * chip unticks the box it was drawn from, which is the single
             * source of truth: the boxes are what post, and nothing here keeps
             * a second copy of the selection to fall out of step with them.
             * ------------------------------------------------------------ */
            var chipWrap = root.querySelector('[data-uc-notify-chips]');
            var chipList = root.querySelector('[data-uc-notify-chips-list]');
            var chipEmpty = root.querySelector('[data-uc-notify-chips-empty]');

            function pickerBoxes() {
                return Array.prototype.slice.call(
                    root.querySelectorAll('[data-uc-picker-user], [data-uc-picker-team]')
                );
            }
            function chipNameOf(box) {
                var label = box.closest ? box.closest('label') : null;
                if (!label) { return box.value; }
                var span = label.querySelector('span');
                if (!span) { return label.textContent.trim(); }
                // The label carries the name and a muted qualifier (the address,
                // or the team's size). The chip wants the name only.
                var muted = span.querySelector('.uc-muted');
                var text = span.textContent;
                if (muted) { text = text.replace(muted.textContent, ''); }
                return text.replace(/\s+/g, ' ').trim();
            }

            function drawChips() {
                if (!chipWrap || !chipList) { return; }
                chipWrap.hidden = false;
                chipList.innerHTML = '';
                var n = 0;

                pickerBoxes().forEach(function (box) {
                    if (!box.checked) { return; }
                    n++;
                    var isTeam = box.hasAttribute('data-uc-picker-team');
                    var chip = document.createElement('span');
                    chip.className = 'uc-rchip' + (isTeam ? ' uc-rchip-team' : '');

                    var text = document.createElement('span');
                    text.className = 'uc-rchip-text';
                    text.textContent = chipNameOf(box);
                    chip.appendChild(text);

                    var kill = document.createElement('button');
                    kill.type = 'button';
                    kill.className = 'uc-rchip-x';
                    kill.setAttribute('aria-label', 'Remove ' + chipNameOf(box));
                    kill.innerHTML = '&times;';
                    kill.addEventListener('click', function () {
                        box.checked = false;
                        drawChips();
                        summarise();
                        // Focus has just been destroyed with the button it was
                        // on, so it goes somewhere deliberate rather than back
                        // to the top of the document.
                        if (details && details.querySelector('[data-uc-picker-toggle]')) {
                            details.querySelector('[data-uc-picker-toggle]').focus();
                        }
                    });
                    chip.appendChild(kill);
                    chipList.appendChild(chip);
                });

                if (chipEmpty) { chipEmpty.hidden = (n !== 0); }
            }

            /* ---- The live count ------------------------------------------ */
            function summarise() {
                if (!countEl) { return; }

                var picked = [];
                var addresses = {};

                // Everyone who is going to get one, by address.
                if (data.author) {
                    var authorBox = root.parentNode
                        ? root.parentNode.querySelector('input[name="notify_author"]')
                        : null;
                    if (!authorBox || authorBox.checked) { addresses[data.author] = true; }
                }

                var people = 0;
                Array.prototype.forEach.call(root.querySelectorAll('[data-uc-picker-user]'), function (box) {
                    if (!box.checked) { return; }
                    people++;
                    var email = data.users[box.getAttribute('data-uc-picker-user')];
                    if (email) { addresses[email] = true; }
                });
                if (people) {
                    picked.push(people + (people === 1 ? ' individual' : ' individuals'));
                }

                Array.prototype.forEach.call(root.querySelectorAll('[data-uc-picker-team]'), function (box) {
                    if (!box.checked) { return; }
                    var team = data.teams[box.getAttribute('data-uc-picker-team')];
                    if (!team) { return; }
                    var n = (team.emails || []).length;
                    picked.push(team.name + ' team (' + n + (n === 1 ? ' person' : ' people') + ')');
                    (team.emails || []).forEach(function (e) { addresses[e] = true; });
                });

                /*
                 * Typed addresses, counted the same way the save reads them.
                 *
                 * These are PILLS now. This read the removed textarea by id
                 * and therefore silently counted none of them, so an event
                 * with four typed addresses reported the wrong total from the
                 * moment 3.14.0 replaced the control. A ticked pill is on the
                 * list, an unticked one is being removed, which is exactly the
                 * test the server applies to the same field.
                 */
                Array.prototype.forEach.call(
                    document.querySelectorAll('[data-uc-email-pill]'),
                    function (box) {
                        if (!box.checked) { return; }
                        var addr = (box.value || '').trim().toLowerCase();
                        if (addr.indexOf('@') > 0) { addresses[addr] = true; }
                    }
                );

                if (!picked.length) {
                    countEl.textContent = 'Nobody chosen yet';
                    return;
                }
                var total = Object.keys(addresses).length;
                countEl.textContent = picked.join(', ') + '. '
                    + total + (total === 1 ? ' person' : ' people') + ' in total.';
            }

            function refreshBoth() { drawChips(); summarise(); }

            root.addEventListener('change', refreshBoth);
            var authorBox = root.parentNode ? root.parentNode.querySelector('input[name="notify_author"]') : null;
            if (authorBox) { authorBox.addEventListener('change', summarise); }

            // The address pills live outside this picker but are part of the
            // same total, so a pill added or removed has to recount.
            var pillField = document.querySelector('[data-uc-emails]');
            if (pillField) {
                pillField.addEventListener('change', summarise);
                pillField.addEventListener('click', function () {
                    // The Add button builds a pill without firing `change`, so
                    // the recount is deferred to after the click handler that
                    // creates it has run.
                    window.setTimeout( summarise, 0 );
                });
            }

            refreshBoth();
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
    /* A REAL DIALOG, NOT window.confirm.
     *
     * Every destructive action behind data-uc-confirm was going through the
     * browser's own confirm(), which cannot be styled, cannot carry the
     * portal's type, and, the reason it was reported, gives no clue which
     * row it belongs to. A native box reading "Remove calendar access for this
     * user?" over a list of six users names nobody.
     *
     * The message now arrives in a <dialog> opened with showModal(), which the
     * scope switcher already uses further down: the top layer makes the page
     * inert, traps Tab, and closes on Escape without any of that being written
     * here. Cancel is first in the source, so showModal() focuses it and the
     * safe answer is the default one.
     *
     * confirm() REMAINS THE FALLBACK, and deliberately: if <dialog> is missing
     * a destructive action must still ask, and asking badly beats not asking. */
    function ucConfirm(message, btn, onYes) {
        var supported = false;
        try {
            supported = typeof document.createElement('dialog').showModal === 'function';
        } catch (err) {
            supported = false;
        }

        if (!supported) {
            if (window.confirm(message)) {
                onYes();
            }
            return;
        }

        // The button's own label is the verb on the confirming button, so the
        // dialog answers with the same word the manager pressed.
        var verb = (btn.textContent || '').trim() || 'Continue';
        var danger = btn.classList.contains('uc-link-danger') || btn.classList.contains('uc-btn-danger');

        var dialog = document.createElement('dialog');
        dialog.className = 'uc-confirm-modal';

        var form = document.createElement('form');
        form.method = 'dialog';

        var text = document.createElement('p');
        text.className = 'uc-confirm-msg';
        text.textContent = message;

        var actions = document.createElement('div');
        actions.className = 'uc-confirm-actions';

        var cancel = document.createElement('button');
        cancel.type = 'submit';
        cancel.value = 'cancel';
        cancel.className = 'uc-btn uc-btn-sm';
        cancel.textContent = 'Cancel';

        var ok = document.createElement('button');
        ok.type = 'submit';
        ok.value = 'ok';
        ok.className = 'uc-btn uc-btn-sm ' + (danger ? 'uc-btn-danger' : 'uc-btn-primary');
        ok.textContent = verb;

        actions.appendChild(cancel);
        actions.appendChild(ok);
        form.appendChild(text);
        form.appendChild(actions);
        dialog.appendChild(form);
        document.body.appendChild(dialog);

        dialog.addEventListener('close', function () {
            document.body.classList.remove('uc-modal-open');
            var answer = dialog.returnValue;
            dialog.remove();
            if ('ok' === answer) {
                onYes();
            }
        });

        document.body.classList.add('uc-modal-open');
        dialog.showModal();
    }

    function initConfirmButtons() {
        var sel = '[data-uc-confirm], [data-uc-confirm-template]';
        document.querySelectorAll(sel).forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                // The replay after the manager said yes. Cleared immediately so
                // a second press asks again.
                if (btn.hasAttribute('data-uc-confirmed')) {
                    btn.removeAttribute('data-uc-confirmed');
                    return;
                }

                // Read at click time: initCompleteness() adds and removes this
                // attribute while the manager works.
                var message = btn.getAttribute('data-uc-confirm');
                if (!message) {
                    return;
                }

                e.preventDefault();
                ucConfirm(message, btn, function () {
                    // Replayed as a click rather than form.submit() so the
                    // button still acts as the submitter: these buttons carry
                    // `form=` attributes and named values that a bare submit()
                    // would drop.
                    btn.setAttribute('data-uc-confirmed', '1');
                    btn.click();
                });
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
                // The badge is what marks an individual field as waiting. The
                // sentence explaining why is said once per event now, not once
                // per field, so there is no per-field note left to toggle.
                toggle(wrap.querySelector('[data-uc-attention-badge]'), filled);
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

    /* ---------------------------------------------------------------------
     * Edit scope: choose before editing.
     *
     * THE MODAL IS THE ONLY GATE, AND 3.23.0 IS WHEN THAT BECAME TRUE.
     *
     * The lock is server-rendered — the fieldset arrives `disabled` — so
     * nothing is editable before this runs rather than merely after it. What
     * happens here is the unlocking:
     *
     *   1. a scope is chosen. The fieldset opens and everything that scope
     *      permits is directly editable.
     *   2. the two fields the scope FORBIDS are disabled, each with a line
     *      saying why.
     *   3. everything changed is saved once, at the end, under the scope from
     *      step 1 — not per field.
     *
     * WHAT WENT, AND WHY. Between steps 1 and 2 there used to be a per-field
     * pencil: every field arrived read-only and each one had to be unlocked by
     * pressing its own button. That made sense before the modal, when the scope
     * was two quiet buttons at the top of a long form that were easy to miss and
     * the pencil was the thing that made "this is a bulk edit" impossible to
     * walk past. The modal now asks that question once, in front of everything
     * else, and cannot be missed. Keeping the pencils meant asking it twice and
     * charging a click for every field of every recurring event.
     *
     * A FORBIDDEN FIELD IS NOT A LOCKED ONE, and it must not look like one. It
     * is disabled with the reason printed under it, because there is no gesture
     * that would open it: the scope is what forbids it, so the answer is to
     * change the scope, not to find a key. `disabled` is safe here precisely
     * because these two are the two: save_event_from_post() guards `date` with
     * isset() and save_rsvp_settings_from_post() guards `capacity` the same way,
     * so a control that posts nothing leaves its stored value alone. That is not
     * true of fields in general, which is why nothing else on this form is ever
     * disabled by script.
     *
     * The banner states the scope and the count and stays put while the form
     * scrolls, because the manager needs to know what the save will do at the
     * moment they press the button, not only at the moment they chose.
     *
     * THE QUESTION IS ASKED AS A MODAL. The server sends the choice as an
     * ordinary block at the top of the form; what happens here is that it is
     * lifted into a real <dialog> and opened with showModal(). That is the
     * whole implementation of "freezes the editor behind it": the browser's
     * top layer makes everything outside the dialog inert, traps Tab inside
     * it, and raises Escape as a cancel event. None of those are reimplemented
     * here, because a hand-rolled focus trap is a thing to get wrong.
     *
     * Escape and the Cancel button do the same thing: leave. An editor whose
     * scope was never chosen is an editor where nothing can be typed and no
     * save can happen, so staying on it with the question dismissed would be
     * a dead screen. Going back to where the manager came from is the answer
     * that matches what they did.
     *
     * If <dialog> is missing, everything below still runs and the block stays
     * in the page as the panel it already was. The lock does not depend on
     * this function: the fieldset arrives disabled from the server.
     * ------------------------------------------------------------------- */
    function initEditScope() {
        var choice = document.querySelector('[data-uc-scope-choice]');
        if (!choice) {
            return; // a one-off event: nothing to choose between
        }
        // Read BEFORE the node is moved into a dialog outside the form.
        var form = choice.closest ? choice.closest('form') : null;
        if (!form) {
            return;
        }

        var fields = form.querySelector('[data-uc-scope-fields]');
        var input = form.querySelector('[data-uc-scope-input]');
        var banner = document.querySelector('[data-uc-scope-banner]');
        var bannerText = document.querySelector('[data-uc-scope-banner-text]');
        var changeBtn = document.querySelector('[data-uc-scope-change]');
        var cancelRow = choice.querySelector('[data-uc-scope-dismiss-row]');
        var cancelBtn = choice.querySelector('[data-uc-scope-cancel]');
        var count = parseInt(choice.getAttribute('data-uc-scope-count'), 10) || 0;

        var locked = {};
        var lockedNode = document.getElementById('uc-scope-locked');
        if (lockedNode) {
            try { locked = JSON.parse(lockedNode.textContent || '{}'); } catch (err) { locked = {}; }
        }

        /* Which editor control carries which field name. Only the fields a
         * scope can forbid need naming: everything else is simply editable and
         * this function never looks at it. */
        var CONTROLS = {
            date: ['date'],
            capacity: ['capacity']
        };

        function controlsFor(names) {
            var out = [];
            names.forEach(function (n) {
                out = out.concat(Array.prototype.slice.call(form.querySelectorAll('[name="' + n + '"]')));
            });
            return out;
        }

        /* The wrapper a field's explanation belongs under, closest first. Only
         * used to place the note now that nothing is locked per field, so a
         * control with no wrapper simply gets its note as a sibling. */
        function fieldWrapOf(el) {
            var node = el.parentElement;
            while (node && node !== form) {
                if (node.classList && node.classList.contains('uc-field')) {
                    return node;
                }
                node = node.parentElement;
            }
            return el.parentElement;
        }

        /* The fields this scope forbids, disabled, each with its reason.
         *
         * "this event only" forbids nothing: an event edited on its own can
         * have its date and its capacity changed like anything else, so the
         * list the server sent is only consulted for a bulk edit. */
        function lockForbidden(scope) {
            if (scope !== 'all_upcoming') {
                return;
            }
            Object.keys(locked).forEach(function (field) {
                var els = controlsFor(CONTROLS[field] || [field]);
                if (!els.length) {
                    return;   // not on this form: nothing to say and nothing to lock
                }
                var wraps = [];
                els.forEach(function (el) {
                    el.disabled = true;
                    el.setAttribute('aria-disabled', 'true');
                    var wrap = fieldWrapOf(el);
                    if (wrap && wraps.indexOf(wrap) === -1) { wraps.push(wrap); }
                });
                wraps.forEach(function (wrap) {
                    wrap.classList.add('uc-field-nobulk');
                    var why = document.createElement('p');
                    why.className = 'uc-field-note uc-field-note-locked';
                    why.textContent = locked[field] || 'Not available when editing several occurrences.';
                    /* AFTER THE LABEL, NOT INSIDE IT. Every .uc-field on this
                     * form is a <label>, and anything inside a label is part of
                     * that label's accessible name: appended as a child, this
                     * sentence turned the field's name into "Capacity Some of
                     * these dates already have RSVPs against them, and capacity
                     * is counted per date." for anybody using a screen reader.
                     * It is a note about the control, not a name for it. */
                    if (wrap.tagName === 'LABEL' && wrap.parentNode) {
                        wrap.parentNode.insertBefore(why, wrap.nextSibling);
                    } else {
                        wrap.appendChild(why);
                    }
                });
            });
        }

        /* ---- The dialog ------------------------------------------------
         *
         * Built here rather than sent by the server, so a browser without
         * <dialog> is never handed markup it cannot open and the block stays
         * the working panel it already is. */
        var dialog = null;
        var answered = false;

        function supportsDialog() {
            var probe = document.createElement('dialog');
            return typeof window.HTMLDialogElement !== 'undefined'
                && typeof probe.showModal === 'function';
        }

        /* Back to where they came from. The referrer when it is a page on this
         * site and not this same page (a save redirects here, so the referrer
         * can be the editor itself), otherwise the events list the server
         * named. history.back() is the last resort: it is the only one that
         * can land on a page outside the portal. */
        function dismiss() {
            var back = choice.getAttribute('data-uc-scope-back') || '';
            var ref = document.referrer || '';
            if (ref && ref.indexOf(window.location.origin + '/') === 0 && ref !== window.location.href) {
                window.location.href = ref;
            } else if (back) {
                window.location.href = back;
            } else {
                window.history.back();
            }
        }

        function openModal() {
            if (!supportsDialog()) {
                return;
            }
            dialog = document.createElement('dialog');
            dialog.className = 'uc-scope-modal';
            dialog.setAttribute('aria-labelledby', 'uc-scope-title');
            dialog.setAttribute('aria-describedby', 'uc-scope-lead');
            document.body.appendChild(dialog);
            dialog.appendChild(choice); // moves it out of the form: see the note above
            if (cancelRow) {
                cancelRow.removeAttribute('hidden');
            }
            document.body.classList.add('uc-modal-open');

            // Escape. Prevented and re-handled so dismissing always leaves,
            // rather than closing the dialog over an editor nothing can be
            // done with.
            dialog.addEventListener('cancel', function (e) {
                e.preventDefault();
                dismiss();
            });

            // Closed any other way, without an answer: same outcome.
            dialog.addEventListener('close', function () {
                document.body.classList.remove('uc-modal-open');
                if (!answered) {
                    dismiss();
                }
            });

            dialog.showModal();

            var first = choice.querySelector('[data-uc-scope]');
            if (first) {
                first.focus();
            }
        }

        function choose(scope) {
            input.value = scope;
            answered = true;

            /* ONE INSTRUCTION UNLOCKS THE WHOLE FORM, and it is the same one
             * that locked it: the fieldset the server sent disabled. Every
             * control inside it becomes editable at once, and the individual
             * `disabled` attributes on fields a platform owns are untouched by
             * this, because they are their own attributes on their own
             * elements. Then the two the scope forbids are put back. */
            if (fields) {
                fields.removeAttribute('disabled');
            }
            lockForbidden(scope);

            if (dialog) {
                dialog.close();
                dialog.remove();
                dialog = null;
            } else {
                choice.setAttribute('hidden', 'hidden');
            }
            if (banner && bannerText) {
                bannerText.textContent = (scope === 'all_upcoming')
                    ? 'Editing all ' + count + ' upcoming occurrences.'
                    : 'Editing this event only.';
                banner.classList.toggle('uc-scope-banner-all', scope === 'all_upcoming');
                banner.removeAttribute('hidden');
                // Where focus goes when the dialog closes. The banner states
                // the answer, so it is both the sensible landing place for the
                // keyboard and the right thing to have read out next.
                banner.focus();
            }

            // The confirmation on the save buttons, naming the count. This is
            // the click that can rewrite a term's worth of programming.
            Array.prototype.forEach.call(form.querySelectorAll('[data-uc-scope-confirm]'), function (btn) {
                if (scope === 'all_upcoming') {
                    btn.setAttribute('data-uc-scope-confirm-text', 'Update ' + count + ' events?');
                } else {
                    btn.removeAttribute('data-uc-scope-confirm-text');
                }
            });
        }

        Array.prototype.forEach.call(choice.querySelectorAll('[data-uc-scope]'), function (btn) {
            btn.addEventListener('click', function () {
                choose(btn.getAttribute('data-uc-scope'));
            });
        });

        if (cancelBtn) {
            cancelBtn.addEventListener('click', function () {
                if (dialog) {
                    dialog.close(); // the close handler leaves, since nothing was answered
                } else {
                    dismiss();
                }
            });
        }

        if (changeBtn) {
            // Reloading is the honest way back: fields that have already been
            // unlocked and typed into cannot be un-typed, and re-locking them
            // while keeping the text would misrepresent what would be saved.
            changeBtn.addEventListener('click', function () {
                window.location.reload();
            });
        }

        /* Registered here, and initEditScope() is called before
         * initConfirmButtons() so this runs first. stopImmediatePropagation()
         * then keeps the completeness confirmation from asking a second
         * question about a save the manager has already called off. */
        Array.prototype.forEach.call(form.querySelectorAll('[data-uc-scope-confirm]'), function (btn) {
            btn.addEventListener('click', function (e) {
                var message = btn.getAttribute('data-uc-scope-confirm-text');
                if (message && !window.confirm(message)) {
                    e.preventDefault();
                    e.stopImmediatePropagation();
                }
            });
        });

        /* Last, so every listener above is bound before the question can be
         * answered. Everything up to this point works whether or not this
         * does anything. */
        openModal();
    }

    /* ---------------------------------------------------------------------
     * THE RECURRENCE CONTROL.
     *
     * ENHANCEMENT ONLY. The server sends every panel visible and every control
     * a real input, and reads repeat_mode on save. With this function deleted
     * the whole thing still works: you pick a mode, tick days, choose an end,
     * and save. What is added is folding away the panels that do not apply and
     * the live summary line.
     *
     * THE DATE ARITHMETIC IS DUPLICATED HERE, AND THAT IS A REAL RISK. The
     * count in the summary has to be the count that will actually be created,
     * so this mirrors SFAF_Recurrence::dates() step for step: the same week
     * blocks, the same "a month without a fifth Friday is skipped", the same
     * distinction between last and fifth, the same caps, the same merge of
     * hand-picked dates. Two implementations of one rule can drift, so they are
     * cross-checked against each other over a matrix of cases rather than
     * trusted to stay in step by inspection. If you change one, change the
     * other and re-run that check.
     *
     * THE MARKERS BELOW ARE LOAD-BEARING. .claude/recurrence-crosscheck.php
     * slices this file between them and evaluates what it finds, so the check
     * runs against the code that ships rather than against a copy of it that
     * somebody remembered to update. Everything between the markers must be
     * free of DOM access and of anything outside this block, because that is
     * all the harness gives it.
     * ------------------------------------------------------------------- */
    /* --8<-- recurrence engine start --8<-- */
    var UC_MAX_OCCURRENCES = 366;
    var UC_DAY_NAMES = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

    function ucParseYmd(s) {
        var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(s || ''));
        if (!m) { return null; }
        // UTC throughout, so nothing here can be moved a day by a timezone.
        // The stored dates are plain calendar days and are treated as such.
        return new Date(Date.UTC(+m[1], +m[2] - 1, +m[3]));
    }
    function ucYmd(d) {
        var mm = String(d.getUTCMonth() + 1);
        var dd = String(d.getUTCDate());
        return d.getUTCFullYear() + '-' + (mm.length < 2 ? '0' + mm : mm) + '-' + (dd.length < 2 ? '0' + dd : dd);
    }
    function ucAddDays(d, n) {
        var x = new Date(d.getTime());
        x.setUTCDate(x.getUTCDate() + n);
        return x;
    }
    function ucAddMonths(d, n) {
        var x = new Date(d.getTime());
        // Matches PHP's "+1 month": the 31st of January becomes the 3rd of
        // March rather than being clamped to the 28th. Same overflow, so the
        // two engines agree on the awkward dates as well as the easy ones.
        x.setUTCMonth(x.getUTCMonth() + n);
        return x;
    }
    function ucNthWeekday(year, month, nth, dow) {
        if (nth === -1) {
            var last = new Date(Date.UTC(year, month + 1, 0));
            return ucAddDays(last, -(((last.getUTCDay() - dow) + 7) % 7));
        }
        var first = new Date(Date.UTC(year, month, 1));
        var day = 1 + (((dow - first.getUTCDay()) + 7) % 7) + (nth - 1) * 7;
        var d = new Date(Date.UTC(year, month, day));
        return (d.getUTCMonth() === month) ? d : null;
    }

    /** The mirror of SFAF_Recurrence::clean_dates(). */
    function ucCleanDates(list, after) {
        var seen = {};
        var out = [];
        (list || []).forEach(function (raw) {
            var d = String(raw == null ? '' : raw).replace(/^\s+|\s+$/g, '');
            var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(d);
            if (!m) { return; }
            // A real calendar day, not merely the shape of one. Date.UTC rolls
            // 2026-02-31 forward to March, so the round trip is the check,
            // the same thing checkdate() does on the PHP side.
            var probe = new Date(Date.UTC(+m[1], +m[2] - 1, +m[3]));
            if (ucYmd(probe) !== d) { return; }
            if (after && d <= after) { return; }
            if (seen[d]) { return; }
            seen[d] = true;
            out.push(d);
        });
        out.sort();
        return out;
    }

    /** The mirror of SFAF_Recurrence::merge_dates(). */
    function ucMergeDates(patternDates, extra) {
        var seen = {};
        var out = [];
        (patternDates || []).concat(extra || []).forEach(function (d) {
            if (seen[d]) { return; }
            seen[d] = true;
            out.push(d);
        });
        out.sort();
        return out.slice(0, UC_MAX_OCCURRENCES);
    }

    /**
     * The mirror of SFAF_Recurrence::dates().
     *
     * `extra` is the hand-picked list and is merged in on the same terms the
     * PHP applies: cleaned against the start date, de-duplicated against the
     * pattern's own dates, sorted, and the whole set capped.
     */
    function ucRecurrenceDates(startYmd, endYmd, spec, limit, extra) {
        if (!startYmd) { return []; }
        return ucMergeDates(
            ucPatternDates(startYmd, endYmd, spec, limit),
            ucCleanDates(extra, startYmd)
        );
    }

    /** The mirror of SFAF_Recurrence::pattern_dates(): the cadence alone. */
    function ucPatternDates(startYmd, endYmd, spec, limit) {
        var from = ucParseYmd(startYmd);
        if (!from || !spec || !spec.type) { return []; }
        // A custom schedule has no cadence. Every date it has was chosen by
        // hand and arrives through `extra`.
        if (spec.type === 'custom') { return []; }
        limit = Math.max(0, limit | 0);
        if (!endYmd && limit <= 0) { return []; }

        var stop = endYmd ? ucParseYmd(endYmd) : ucAddMonths(from, 120);
        if (!stop || stop < from) { return []; }

        var cap = limit > 0 ? Math.min(limit, UC_MAX_OCCURRENCES) : UC_MAX_OCCURRENCES;
        var interval = Math.max(1, Math.min(spec.interval || 1, 52));
        var out = [];
        var cur, i;

        if (spec.type === 'daily') {
            cur = from;
            while (out.length < cap) {
                cur = ucAddDays(cur, interval);
                if (cur > stop) { break; }
                out.push(ucYmd(cur));
            }
        } else if (spec.type === 'weekly') {
            var days = (spec.days && spec.days.length) ? spec.days.slice() : [from.getUTCDay()];
            days.sort(function (a, b) { return a - b; });
            var weekStart = ucAddDays(from, -from.getUTCDay());
            var horizon = ucAddDays(stop, 7);
            for (var block = 0; out.length < cap; block++) {
                var base = ucAddDays(weekStart, block * interval * 7);
                if (base > horizon) { break; }
                for (i = 0; i < days.length; i++) {
                    var hit = ucAddDays(base, days[i]);
                    if (hit <= from || hit > stop) { continue; }
                    if (out.length >= cap) { break; }
                    out.push(ucYmd(hit));
                }
                if (block > UC_MAX_OCCURRENCES) { break; }
            }
        } else if (spec.type === 'monthly') {
            cur = from;
            while (out.length < cap) {
                cur = ucAddMonths(cur, interval);
                if (cur > stop) { break; }
                out.push(ucYmd(cur));
            }
        } else if (spec.type === 'monthly_nth') {
            var nth = spec.nth, dow = spec.dow;
            if (!nth || dow < 0) {
                nth = Math.ceil(from.getUTCDate() / 7);
                dow = from.getUTCDay();
            }
            var cursor = new Date(Date.UTC(from.getUTCFullYear(), from.getUTCMonth(), 1));
            for (i = 0; i < UC_MAX_OCCURRENCES && out.length < cap; i++) {
                cursor = ucAddMonths(cursor, 1);
                if (cursor > stop) { break; }
                var got = ucNthWeekday(cursor.getUTCFullYear(), cursor.getUTCMonth(), nth, dow);
                if (!got || got > stop || got <= from) { continue; }
                out.push(ucYmd(got));
            }
        }
        return out;
    }

    /** The mirror of SFAF_Recurrence::pattern_label(). */
    function ucRecurrenceLabel(spec, startYmd) {
        var from = ucParseYmd(startYmd);
        var interval = Math.max(1, spec.interval || 1);
        var i, list;

        if (spec.type === 'custom') { return 'On chosen dates'; }
        if (spec.type === 'daily') {
            return interval === 1 ? 'Every day' : 'Every ' + interval + ' days';
        }
        if (spec.type === 'weekly') {
            var days = (spec.days && spec.days.length) ? spec.days.slice() : (from ? [from.getUTCDay()] : []);
            days.sort(function (a, b) { return a - b; });
            list = [];
            for (i = 0; i < days.length; i++) { list.push(UC_DAY_NAMES[days[i]]); }
            var on = list.length ? ' on ' + ucJoinWords(list) : '';
            if (interval === 1) { return 'Every week' + on; }
            if (interval === 2 && days.length === 1) { return 'Every other ' + UC_DAY_NAMES[days[0]]; }
            return 'Every ' + interval + ' weeks' + on;
        }
        if (spec.type === 'monthly') {
            var every = interval === 1 ? 'Every month' : 'Every ' + interval + ' months';
            // "on day 4". No ordinal: see SFAF_Recurrence::pattern_label(), whose
            // words this has to match exactly or the cross-check fails.
            return from ? every + ' on day ' + from.getUTCDate() : every;
        }
        if (spec.type === 'monthly_nth') {
            var nth = spec.nth, dow = spec.dow;
            if (!nth || dow < 0) {
                if (!from) { return 'Every month, on the same weekday'; }
                nth = Math.ceil(from.getUTCDate() / 7);
                dow = from.getUTCDay();
            }
            var words = { '1': 'first', '2': 'second', '3': 'third', '4': 'fourth', '5': 'fifth', '-1': 'last' };
            return 'Every ' + (words[String(nth)] || 'first') + ' ' + UC_DAY_NAMES[dow] + ' of the month';
        }
        return '';
    }
    function ucJoinWords(list) {
        if (!list.length) { return ''; }
        if (list.length === 1) { return list[0]; }
        return list.slice(0, -1).join(', ') + ' and ' + list[list.length - 1];
    }
    function ucPrettyDate(ymd) {
        var d = ucParseYmd(ymd);
        if (!d) { return ymd; }
        var months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        // "Aug 4, 2026". The comma was missing, and this is the same 'M j, Y'
        // that sfaf_ap_date( ..., 'short_year' ) renders on the PHP side: these
        // dates sit in the same schedule editor as the ones the server writes.
        return months[d.getUTCMonth()] + ' ' + d.getUTCDate() + ', ' + d.getUTCFullYear();
    }
    function ucPlural(n, one, many) { return n === 1 ? one : many; }

    /**
     * The mirror of SFAF_Recurrence::summary().
     *
     * The sentence AND the number, from one function, because the number is a
     * promise about how many posts a save is going to create and the sentence
     * is what somebody reads instead of counting. Splitting them is how a
     * summary comes to say 21 while the generator makes 23.
     */
    function ucRecurrenceSummary(startYmd, endYmd, spec, limit, extra, tail) {
        if (!spec || !spec.type) {
            return 'Does not repeat. One event will be created.';
        }
        if (!startYmd) {
            return 'Set the event date first, since every repeat is counted from it.';
        }

        var clean = ucCleanDates(extra, startYmd);
        var made = ucRecurrenceDates(startYmd, endYmd, spec, limit, clean);
        var total = made.length + 1;   // the event itself is the first occurrence

        if (spec.type === 'custom') {
            if (!clean.length) {
                return 'Add the dates this happens on.';
            }
            return total + ' ' + ucPlural(total, 'date', 'dates') + '. '
                + total + ' ' + ucPlural(total, 'event', 'events') + ' will be created.';
        }

        // The extras that ADD a date, not the ones in the box: a date the
        // pattern already produces is one event, so counting it here would
        // announce an event that is not going to exist. See the PHP note.
        var fromPattern = ucPatternDates(startYmd, endYmd, spec, limit);
        var net = clean.filter(function (d) { return fromPattern.indexOf(d) === -1; });
        var plus = '';
        if (net.length) {
            plus = ', plus ' + net.length + ' extra ' + ucPlural(net.length, 'date', 'dates');
        }

        return ucRecurrenceLabel(spec, startYmd) + (tail || '') + plus + '. '
            + total + ' ' + ucPlural(total, 'event', 'events') + ' will be created.';
    }
    /* --8<-- recurrence engine end --8<-- */

    function initRecurrence() {
        Array.prototype.forEach.call(document.querySelectorAll('[data-uc-repeat]'), function (root) {
            var summary = root.querySelector('[data-uc-repeat-summary]');
            var panels = {};
            Array.prototype.forEach.call(root.querySelectorAll('[data-uc-repeat-panel]'), function (p) {
                panels[p.getAttribute('data-uc-repeat-panel')] = p;
            });
            // The date the event itself is on. Every pattern is anchored to it,
            // and it can change while this control is open, so it is read from
            // the form each time rather than captured once.
            var dateInput = document.querySelector('input[name="date"]');

            function startDate() {
                if (dateInput && dateInput.value) { return dateInput.value; }
                return root.getAttribute('data-uc-repeat-date') || '';
            }
            function mode() {
                var on = root.querySelector('[data-uc-repeat-mode]:checked');
                return on ? on.value : '';
            }
            function chosenDays() {
                var out = [];
                Array.prototype.forEach.call(root.querySelectorAll('[data-uc-repeat-day]:checked'), function (b) {
                    out.push(parseInt(b.value, 10));
                });
                return out;
            }
            function currentSpec() {
                var m = mode();
                if (m === 'custom') { return { type: 'custom', interval: 1, days: [] }; }
                if (m === 'daily') { return { type: 'daily', interval: 1 }; }
                if (m === 'weekly') {
                    var n = parseInt((root.querySelector('[name="repeat_weekly_interval"]') || {}).value, 10);
                    return { type: 'weekly', interval: (n > 0 ? n : 1), days: chosenDays() };
                }
                if (m === 'monthly') {
                    var sub = root.querySelector('[data-uc-repeat-monthly]:checked');
                    if (sub && sub.value === 'nth') {
                        return {
                            type: 'monthly_nth',
                            nth: parseInt((root.querySelector('[name="repeat_nth"]') || {}).value, 10) || 1,
                            dow: parseInt((root.querySelector('[name="repeat_nth_dow"]') || {}).value, 10)
                        };
                    }
                    return { type: 'monthly', interval: 1 };
                }
                return null;
            }
            function ends() {
                var on = root.querySelector('[data-uc-repeat-ends]:checked');
                return on ? on.value : 'never';
            }

            /* ---- The dates picker -----------------------------------------
             *
             * ONE LIST, TWO MEANINGS, AND THE MEANING IS THE MODE. Under Custom
             * it is the schedule; beside a pattern it is the dates the pattern
             * does not cover. Same markup, same repeat_dates[] field name, same
             * parser on the server, so there is no second idea of what a chosen
             * date is.
             *
             * EACH ROW CARRIES A HIDDEN INPUT rather than the list being
             * serialised on submit. A hidden input is a real form field: it
             * survives a browser restoring the form, it needs no submit
             * handler, and if this script throws after the rows are built the
             * dates still post. Removing a row removes its field, which is the
             * whole of what "remove" has to mean.
             *
             * THE FOUR SERVER-RENDERED SLOTS ARE THE NO-SCRIPT PATH and are
             * hidden here rather than emptied: a hidden input with no value
             * posts an empty string, which clean_dates() drops, so leaving them
             * in place costs nothing and keeps the markup honest for anybody
             * reading it with script switched off.
             */
            var datesPanel = panels.dates;
            var addRow = root.querySelector('[data-uc-dates-add]');
            var addInput = root.querySelector('[data-uc-dates-input]');
            var addBtn = root.querySelector('[data-uc-dates-addbtn]');
            var list = root.querySelector('[data-uc-dates-list]');
            var slots = root.querySelector('[data-uc-dates-slots]');
            var datesLabel = root.querySelector('[data-uc-dates-label]');
            var chosen = [];

            if (addRow) { addRow.removeAttribute('hidden'); }
            if (slots) { slots.hidden = true; }

            function renderDates() {
                if (!list) { return; }
                list.textContent = '';

                /* THE EVENT'S OWN DATE IS SHOWN AS THE FIRST ROW IN CUSTOM
                 * MODE, and it is not removable. The summary says "5 dates" and
                 * counts the event itself as one of them, so five rows have to
                 * be on screen or the sentence is describing something the
                 * manager cannot see. Beside a pattern it is not shown, because
                 * there the list means "extra" and the event's date is not one
                 * of the extras. */
                var start = startDate();
                if (mode() === 'custom' && start) {
                    var own = document.createElement('li');
                    own.className = 'uc-dates-row uc-dates-row-own';
                    var ownText = document.createElement('span');
                    ownText.className = 'uc-dates-when';
                    ownText.textContent = ucPrettyDate(start);
                    var ownTag = document.createElement('span');
                    ownTag.className = 'uc-dates-own-tag';
                    ownTag.textContent = "the event's own date";
                    own.appendChild(ownText);
                    own.appendChild(ownTag);
                    list.appendChild(own);
                }

                chosen.forEach(function (ymd) {
                    var li = document.createElement('li');
                    li.className = 'uc-dates-row';

                    var hidden = document.createElement('input');
                    hidden.type = 'hidden';
                    hidden.name = 'repeat_dates[]';
                    hidden.value = ymd;

                    var when = document.createElement('span');
                    when.className = 'uc-dates-when';
                    when.textContent = ucPrettyDate(ymd);

                    var remove = document.createElement('button');
                    remove.type = 'button';
                    remove.className = 'uc-link-danger uc-btn-sm';
                    remove.textContent = 'Remove';
                    remove.setAttribute('aria-label', 'Remove ' + ucPrettyDate(ymd));
                    remove.addEventListener('click', function () {
                        chosen = chosen.filter(function (d) { return d !== ymd; });
                        renderDates();
                        refresh();
                        if (addInput) { addInput.focus(); }
                    });

                    li.appendChild(hidden);
                    li.appendChild(when);
                    li.appendChild(remove);
                    list.appendChild(li);
                });
            }

            function addDate() {
                if (!addInput) { return; }
                // Cleaned by the same rules the server uses, against the event's
                // own date, so a date the save would silently drop is refused
                // here instead of appearing in a list and then not existing.
                var got = ucCleanDates([addInput.value], startDate());
                if (!got.length) { return; }
                if (chosen.indexOf(got[0]) === -1) {
                    chosen.push(got[0]);
                    chosen.sort();
                }
                addInput.value = '';
                renderDates();
                refresh();
            }

            if (addBtn) { addBtn.addEventListener('click', addDate); }
            if (addInput) {
                // Enter in a date field would otherwise submit the whole form,
                // which on this screen creates the event.
                addInput.addEventListener('keydown', function (e) {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        addDate();
                    }
                });
            }

            function refresh() {
                var m = mode();
                if (panels.weekly) { panels.weekly.hidden = (m !== 'weekly'); }
                if (panels.monthly) { panels.monthly.hidden = (m !== 'monthly'); }
                // No cadence under Custom, so nothing for "Ends" to bound.
                if (panels.ends) { panels.ends.hidden = (m === '' || m === 'custom'); }
                if (datesPanel) { datesPanel.hidden = (m === ''); }
                if (datesLabel) {
                    datesLabel.textContent = (m === 'custom') ? 'Dates' : 'Extra dates';
                }

                if (!summary) { return; }
                var spec = currentSpec();
                var start = startDate();

                // The two prompts that are about a control rather than about a
                // count, so they are answered before the engine is asked for a
                // number it cannot produce.
                if (spec && spec.type === 'weekly' && !spec.days.length) {
                    summary.textContent = 'Pick at least one day of the week.';
                    return;
                }

                var how = ends(), until = '', limit = 0, tail = '';
                if (spec && spec.type !== 'custom' && start) {
                    if (how === 'on') {
                        until = (root.querySelector('[name="repeat_until"]') || {}).value || '';
                        if (!until) {
                            summary.textContent = 'Choose the date it runs until.';
                            return;
                        }
                        tail = ', until ' + ucPrettyDate(until);
                    } else if (how === 'after') {
                        var total = parseInt((root.querySelector('[name="repeat_count"]') || {}).value, 10);
                        if (!(total > 1)) {
                            summary.textContent = 'Choose how many occurrences there should be.';
                            return;
                        }
                        limit = total - 1;
                    } else {
                        limit = 52;      // must equal SFAF_Portal::REPEAT_OPEN_ENDED_LIMIT
                        tail = ', for a year';
                    }
                }

                summary.textContent = ucRecurrenceSummary(start, until, spec, limit, chosen, tail);
            }

            root.addEventListener('change', refresh);
            root.addEventListener('input', refresh);
            if (dateInput) {
                dateInput.addEventListener('change', function () {
                    // The event's own date anchors everything, and moving it
                    // can make a chosen date invalid (on or before it). Re-clean
                    // rather than leaving a row that will be dropped on save.
                    chosen = ucCleanDates(chosen, startDate());
                    renderDates();
                    refresh();
                });
            }
            renderDates();
            refresh();
        });
    }

    /* ---------------------------------------------------------------------
     * "Anyone else": one address at a time, as removable pills.
     *
     * ENHANCEMENT ONLY, AGAIN. Each pill is a ticked checkbox, so unticking one
     * takes the address off the list with scripting switched off, and the Add
     * field simply posts and is appended by the server. What this adds is
     * refusing a bad address while the person is still looking at it, rather
     * than after a save and a page load, and taking a removed pill off the
     * screen instead of leaving it there greyed out.
     * ------------------------------------------------------------------- */
    function initEmailPills() {
        Array.prototype.forEach.call(document.querySelectorAll('[data-uc-emails]'), function (root) {
            var list = root.querySelector('[data-uc-email-pills]');
            var input = root.querySelector('[data-uc-email-input]');
            var addBtn = root.querySelector('[data-uc-email-add]');
            var error = root.querySelector('[data-uc-email-error]');
            var empty = root.querySelector('[data-uc-emails-empty]');
            if (!list || !input || !addBtn) { return; }

            function known() {
                var out = [];
                Array.prototype.forEach.call(list.querySelectorAll('[data-uc-email-pill]'), function (b) {
                    if (b.checked) { out.push(b.value.toLowerCase()); }
                });
                return out;
            }
            function syncEmpty() {
                if (!empty) { return; }
                empty.hidden = known().length !== 0;
            }
            function say(msg) {
                if (!error) { return; }
                error.textContent = msg || '';
                error.hidden = !msg;
            }
            function valid(addr) {
                // Deliberately the same shape of test the server applies: an
                // address with one @, something either side, and a dot in the
                // domain. Anything stricter here would reject addresses the
                // server would have accepted, which is worse than the reverse.
                return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(addr);
            }

            function add() {
                var addr = (input.value || '').trim();
                if (!addr) { say('Type an address first.'); input.focus(); return; }
                if (!valid(addr)) { say('"' + addr + '" is not an email address.'); input.focus(); return; }
                if (known().indexOf(addr.toLowerCase()) !== -1) {
                    say('That address is already on the list.');
                    input.value = '';
                    return;
                }

                var label = document.createElement('label');
                label.className = 'uc-email-pill';
                var box = document.createElement('input');
                box.type = 'checkbox';
                box.name = 'notify_emails[]';
                box.value = addr;
                box.checked = true;
                box.setAttribute('data-uc-email-pill', '');
                var text = document.createElement('span');
                text.className = 'uc-email-pill-text';
                text.textContent = addr;
                var x = document.createElement('span');
                x.className = 'uc-email-pill-x';
                x.setAttribute('aria-hidden', 'true');
                x.textContent = '×';
                label.appendChild(box);
                label.appendChild(text);
                label.appendChild(x);
                list.appendChild(label);

                // The add field must not also post, or the address would be
                // counted twice: once as a pill and once as a new one.
                input.value = '';
                say('');
                syncEmpty();
                input.focus();
            }

            addBtn.addEventListener('click', add);
            input.addEventListener('keydown', function (e) {
                // Enter adds the address rather than submitting the whole form,
                // which is what it would otherwise do in a single-field row.
                if (e.key === 'Enter') { e.preventDefault(); add(); }
            });
            input.addEventListener('input', function () { say(''); });

            list.addEventListener('change', function (e) {
                var box = e.target;
                if (!box || !box.hasAttribute || !box.hasAttribute('data-uc-email-pill')) { return; }
                if (!box.checked) {
                    var pill = box.closest('.uc-email-pill');
                    if (pill) { pill.parentNode.removeChild(pill); }
                }
                syncEmpty();
            });

            syncEmpty();
        });
    }
})();
