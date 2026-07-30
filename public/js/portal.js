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
    });

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
     * then gets out of the way. The message is rendered server-side from the
     * same list the pending queue's icon uses, so the two always agree. */
    function initConfirmButtons() {
        document.querySelectorAll('[data-uc-confirm]').forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                var message = btn.getAttribute('data-uc-confirm');
                if (message && !window.confirm(message)) {
                    e.preventDefault();
                }
            });
        });
    }
})();
