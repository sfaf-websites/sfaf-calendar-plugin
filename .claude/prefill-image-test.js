/**
 * WHAT "Fill these in" LEAVES ON THE SCREEN (3.64.2).
 *
 *     node .claude/prefill-image-test.js
 *     node .claude/prefill-image-test.js --self-test
 *
 * THE DEFECT. The Image option wrote the series picture into two hidden fields
 * and stopped there. The preview stayed empty and the tag beside "Featured
 * Image" went on saying "Placeholder", so the button reported filling six
 * things in and the one thing that is a picture was the one thing that could
 * not be seen. Every check that could be written about the WRITE passed the
 * whole time: the values were set, on the right elements, from the right keys.
 *
 * So this asserts what the screen shows. It runs the real initSeriesPrefill()
 * out of public/js/portal.js over a document, presses the button, and then
 * reads the preview, the Remove button and the tag.
 *
 * THE FUNCTIONS ARE SLICED OUT OF portal.js, NOT COPIED, by name and brace
 * depth, and the slice throws rather than returning something plausible when a
 * name is gone. A copy would keep passing after the original changed, which is
 * the failure mode of every test that restates its subject.
 *
 * WHAT THIS FILE CANNOT PROVE, AND WHERE THAT IS PROVED. The document below is
 * built by hand, so it could hold a shape the event form does not render, and
 * this test would pass over markup that does not exist.
 * .claude/series-control-test.php is the other half: it RENDERS New Event and
 * asserts that `.uc-image-field` carries `[data-uc-image-id]`,
 * `[data-uc-image-url]`, `[data-uc-image-preview]`,
 * `[data-uc-image-preview-img]` and `[data-uc-img-source-tag]` with its
 * `data-uc-img-source-own` label. Rename one of those and that file fails.
 * Neither test is worth much alone; the pair is the assertion.
 *
 * THE STUB IS DELIBERATELY SMALL: elements, attributes, class lists, a
 * selector matcher for the forms these functions actually use, text, listeners
 * and `style.display`. A stub that does more is a stub that can quietly answer
 * a question the browser would have answered differently, and --self-test
 * feeds the matcher things it must and must not match before any of it is
 * trusted.
 */

'use strict';

const fs = require('fs');
const path = require('path');

const SRC = fs.readFileSync(path.join(__dirname, '..', 'public', 'js', 'portal.js'), 'utf8');

const fails = [];
function expect(label, got, want) {
    const ok = JSON.stringify(got) === JSON.stringify(want);
    if (!ok) {
        fails.push(label + ': got ' + JSON.stringify(got) + ', expected ' + JSON.stringify(want));
    }
    return ok;
}

/* =========================================================================
 * Slicing the real functions out of the real file
 * ====================================================================== */

function slice(name) {
    const at = SRC.indexOf('function ' + name + '(');
    if (at < 0) {
        throw new Error('portal.js has no function ' + name + '(); this test is asking about code that is gone');
    }
    let depth = 0;
    for (let j = SRC.indexOf('{', at); j < SRC.length; j++) {
        if (SRC[j] === '{') { depth++; }
        else if (SRC[j] === '}') {
            depth--;
            if (depth === 0) { return SRC.slice(at, j + 1); }
        }
    }
    throw new Error('unbalanced braces reading ' + name + '() out of portal.js');
}

const NEEDED = ['showImagePreview', 'hideImagePreview', 'markImageAsOwn', 'initSeriesPrefill'];

/* =========================================================================
 * THE STUB
 * ====================================================================== */

/**
 * One compound selector, as a list of predicates. Tag, .class, [attr],
 * [attr="value"] and :checked, which is everything the sliced code asks for.
 */
function compile(sel) {
    const tests = [];
    const re = /([a-zA-Z][\w-]*)|\.([\w-]+)|\[([\w-]+)(?:=["']([^"']*)["'])?\]|(:checked)/g;
    let m;
    let consumed = 0;
    while ((m = re.exec(sel)) !== null) {
        consumed += m[0].length;
        if (m[1]) {
            const tag = m[1].toUpperCase();
            tests.push(el => el.tagName === tag);
        } else if (m[2]) {
            const cls = m[2];
            tests.push(el => el.classes().indexOf(cls) > -1);
        } else if (m[3]) {
            const name = m[3];
            const val = m[4];
            tests.push(el => (val === undefined
                ? el.attrs[name] !== undefined
                : String(el.attrs[name]) === val));
        } else if (m[5]) {
            tests.push(el => el.checked === true);
        }
    }
    if (!tests.length || consumed !== sel.trim().length) {
        throw new Error('the stub cannot read the selector "' + sel + '"; it is not asserting what it thinks it is');
    }
    return el => tests.every(t => t(el));
}

class El {
    constructor(tag) {
        this.tagName = String(tag).toUpperCase();
        this.attrs = {};
        this.kids = [];
        this.parentNode = null;
        this.listeners = {};
        this.style = { display: '' };
        this.own = '';          // this element's own text
        this.value = '';
        this.checked = false;
        this.disabled = false;
        this.src = '';
        this.alt = '';
        const self = this;
        this.classList = {
            contains(c) { return self.classes().indexOf(c) > -1; },
            add(c) { if (!this.contains(c)) { self.className = (self.className + ' ' + c).trim(); } },
            remove(c) { self.className = self.classes().filter(x => x !== c).join(' '); },
            toggle(c, on) { if (on) { this.add(c); } else { this.remove(c); } }
        };
    }

    get className() { return this.attrs.class || ''; }
    set className(v) { this.attrs.class = String(v); }
    classes() { return this.className.split(/\s+/).filter(Boolean); }

    get hidden() { return this.attrs.hidden !== undefined; }
    set hidden(v) { if (v) { this.attrs.hidden = 'hidden'; } else { delete this.attrs.hidden; } }

    getAttribute(n) { return this.attrs[n] === undefined ? null : this.attrs[n]; }
    setAttribute(n, v) { this.attrs[n] = String(v); }
    removeAttribute(n) { delete this.attrs[n]; }
    hasAttribute(n) { return this.attrs[n] !== undefined; }

    get textContent() {
        return this.own + this.kids.map(k => k.textContent).join('');
    }
    set textContent(v) { this.kids = []; this.own = String(v); }

    /*
     * THE ONE PLACE THE STUB DOES A BROWSER'S WORK, and it is worth naming.
     * initSeriesPrefill() clears the options box with innerHTML = '', which is
     * the case that matters here, and stripTags() sets markup on a detached
     * <div> and reads the text back out. Nothing about the image rows depends
     * on this; the description row's preview does, and that assertion is the
     * one to distrust if this ever disagrees with a browser.
     */
    set innerHTML(v) {
        this.kids = [];
        this.own = String(v)
            .replace(/<[^>]*>/g, '')
            .replace(/&nbsp;/g, ' ')
            .replace(/&lt;/g, '<').replace(/&gt;/g, '>')
            .replace(/&quot;/g, '"').replace(/&#0?39;/g, "'")
            .replace(/&amp;/g, '&');
    }

    appendChild(node) { node.parentNode = this; this.kids.push(node); return node; }
    replaceChild(fresh, old) {
        const at = this.kids.indexOf(old);
        if (at < 0) { return old; }
        fresh.parentNode = this;
        this.kids[at] = fresh;
        old.parentNode = null;
        return old;
    }

    descendants(out) {
        out = out || [];
        for (const k of this.kids) { out.push(k); k.descendants(out); }
        return out;
    }
    querySelector(sel) { const m = this.querySelectorAll(sel); return m.length ? m[0] : null; }
    querySelectorAll(sel) { const t = compile(sel); return this.descendants().filter(t); }
    closest(sel) {
        const t = compile(sel);
        let node = this;
        while (node) { if (t(node)) { return node; } node = node.parentNode; }
        return null;
    }

    addEventListener(type, fn) { (this.listeners[type] = this.listeners[type] || []).push(fn); }
    dispatchEvent(ev) {
        (this.listeners[ev.type] || []).forEach(fn => fn.call(this, ev));
        return true;
    }
    click() { this.dispatchEvent({ type: 'click', preventDefault() {} }); }
}

/** A <select>, which the sliced code reads through .options and .selectedIndex. */
class Select extends El {
    constructor() { super('select'); this.options = []; this.value = ''; }
    option(value, text) {
        const o = new El('option');
        o.attrs.value = value;
        o.own = text;
        o.text = text;
        this.options.push(o);
        this.appendChild(o);
        return o;
    }
    get selectedIndex() {
        for (let i = 0; i < this.options.length; i++) {
            if (this.options[i].attrs.value === String(this.value)) { return i; }
        }
        return -1;
    }
    choose(value) {
        this.value = String(value);
        this.dispatchEvent({ type: 'change' });
    }
}

function el(tag, attrs, kids) {
    const node = new El(tag);
    Object.keys(attrs || {}).forEach(k => {
        if (k === 'text') { node.own = attrs[k]; }
        else if (k === 'value') { node.value = attrs[k]; node.attrs.value = attrs[k]; }
        else { node.attrs[k] = String(attrs[k]); }
    });
    (kids || []).forEach(k => node.appendChild(k));
    return node;
}

/* =========================================================================
 * THE DOCUMENT: the two cards this touches, in the shape New Event renders
 * them. series-control-test.php is what holds that claim to the real render.
 * ====================================================================== */

const SERIES_IMAGE = 'https://example.org/wp-content/uploads/sfaf-calendar/prop-harm-reduction-1024x576.jpg';

function build(payload) {
    const select = new Select();
    select.attrs.name = 'series';
    select.attrs['data-uc-series-select'] = '';
    select.option('0', 'Not part of a series');
    select.option('11', 'Monday Support Group');

    const optsBox = el('div', { class: 'uc-prefill-opts', 'data-uc-prefill-opts': '' });
    const nameOut = el('strong', { 'data-uc-prefill-name': '' });
    const applyBtn = el('button', { class: 'uc-btn', 'data-uc-prefill-apply': '', text: 'Fill these in' });
    const noneBtn = el('button', { class: 'uc-btn', 'data-uc-prefill-none': '', text: 'Start from scratch' });
    const said = el('p', { class: 'uc-flash uc-prefill-said', 'data-uc-prefill-said': '', hidden: 'hidden' });
    const panel = el('div', { class: 'uc-prefill', 'data-uc-prefill-panel': '', hidden: 'hidden' }, [
        el('p', { class: 'uc-prefill-head' }, [nameOut]),
        optsBox,
        el('div', { class: 'uc-prefill-actions' }, [applyBtn, noneBtn]),
        said
    ]);
    const dataNode = el('script', { 'data-uc-prefill-data': '', text: JSON.stringify(payload) });

    const card = el('section', { class: 'uc-bento-card uc-series-first', 'data-uc-series-prefill': '' }, [
        el('label', { class: 'uc-field' }, [select]),
        panel,
        dataNode
    ]);

    /* The featured image field, exactly as render_image_picker() emits it. */
    const tag = el('span', {
        class: 'uc-img-source-tag',
        'data-uc-img-source-tag': '',
        'data-uc-img-source-own': 'Event-specific',
        text: 'Placeholder'
    });
    const idInput = el('input', { type: 'hidden', name: 'featured_image_id', 'data-uc-image-id': '', value: '0' });
    const urlInput = el('input', { type: 'url', name: 'image_url', 'data-uc-image-url': '', value: '' });
    const previewImg = el('img', { 'data-uc-image-preview-img': '' });
    const preview = el('div', { class: 'uc-image-preview', 'data-uc-image-preview': '' }, [previewImg]);
    preview.style.display = 'none';
    const removeBtn = el('button', { class: 'uc-btn uc-remove-image', text: 'Remove' });
    removeBtn.style.display = 'none';

    const imageField = el('div', { class: 'uc-field uc-image-field' }, [
        el('span', { class: 'uc-field-label', text: 'Featured Image' }, [tag]),
        idInput,
        preview,
        el('div', { class: 'uc-image-buttons' }, [
            el('button', { class: 'uc-btn uc-choose-image', text: 'Choose Image' }),
            removeBtn
        ]),
        el('label', { class: 'uc-field uc-image-url-field' }, [urlInput])
    ]);

    /* The times and a description, so a payload carrying them proves the other
       rows are still written. A time is FOUR selects since 3.98.0, <name>_h and
       <name>_m, which is what the editor renders; until 3.104.0 this test gave
       the prefill a field named start_time that no longer exists anywhere, and
       so passed while the real Fill these in wrote the times to nothing. */
    const startH = el('select', { name: 'start_time_h', value: '' });
    const startM = el('select', { name: 'start_time_m', value: '' });
    const endH = el('select', { name: 'end_time_h', value: '' });
    const endM = el('select', { name: 'end_time_m', value: '' });
    const startInput = { get value() { return startH.value + ':' + startM.value; } };
    const endInput = { get value() { return endH.value + ':' + endM.value; } };
    const descInput = el('textarea', { name: 'description', value: '' });

    const form = el('form', { class: 'uc-form' }, [card, imageField, startH, startM, endH, endM, descInput]);
    const root = el('div', {}, [form]);
    select.form = form;

    return {
        root, form, select, panel, optsBox, applyBtn, noneBtn, said,
        tag, idInput, urlInput, preview, previewImg, removeBtn,
        startInput, endInput, descInput
    };
}

function runOver(dom, confirmAnswer) {
    const document = {
        createElement: tag => new El(tag),
        querySelector: sel => dom.root.querySelector(sel),
        querySelectorAll: sel => dom.root.querySelectorAll(sel)
    };
    const window = { confirm: () => (confirmAnswer === undefined ? true : confirmAnswer) };
    const EventStub = function (type) { this.type = type; };

    const make = new Function('document', 'window', 'Event',
        NEEDED.map(slice).join('\n\n') + '\nreturn { initSeriesPrefill: initSeriesPrefill };');
    make(document, window, EventStub).initSeriesPrefill();
}

/* Every option row's label and what is shown beside it, read off the card. */
function rows(dom) {
    return dom.optsBox.kids.map(label => {
        const strong = label.querySelector('strong');
        const quiet = label.querySelector('.uc-muted');
        const img = quiet ? quiet.querySelector('img') : null;
        return {
            key: (label.querySelector('[data-uc-prefill-opt]') || { attrs: {} }).attrs['data-uc-prefill-opt'],
            label: strong ? strong.textContent : '',
            text: quiet ? quiet.textContent : '',
            img: img ? { src: img.src, alt: img.alt, class: img.className } : null
        };
    });
}

/* =========================================================================
 * --self-test: the matcher and the reader, before anything rests on them.
 * ====================================================================== */

if (process.argv.indexOf('--self-test') > -1) {
    let ok = true;
    const probe = (label, got, want) => {
        const good = JSON.stringify(got) === JSON.stringify(want);
        console.log('  ' + (good ? 'ok  ' : 'FAIL') + '  ' + label.padEnd(58)
            + ' got ' + JSON.stringify(got) + ' want ' + JSON.stringify(want));
        ok = ok && good;
    };

    const a = el('div', { class: 'uc-image-field one' });
    const b = el('input', { 'data-uc-image-url': '', value: '' });
    const c = el('input', { type: 'checkbox' });
    c.checked = true;
    a.appendChild(b);
    a.appendChild(c);

    probe('matches on a class', !!a.querySelector('[data-uc-image-url]'), true);
    probe('matches a bare attribute', a.querySelectorAll('[data-uc-image-url]').length, 1);
    probe('does not match an attribute that is absent', a.querySelectorAll('[data-uc-image-id]').length, 0);
    probe('matches an attribute value', compile('[type="checkbox"]')(c), true);
    probe('refuses a different attribute value', compile('[type="radio"]')(c), false);
    probe('reads :checked', a.querySelectorAll('input:checked').length, 1);
    probe('closest walks up to the field', b.closest('.uc-image-field') === a, true);
    probe('closest returns null when nothing matches', b.closest('.uc-nothing-here'), null);
    probe('a compound selector needs both halves',
        compile('[type="checkbox"]:checked')(c) && !compile('[type="radio"]:checked')(c), true);

    let threw = false;
    try { compile('div > span'); } catch (e) { threw = true; }
    probe('an unreadable selector throws rather than matching nothing', threw, true);

    threw = false;
    try { slice('aFunctionThatIsNotThere'); } catch (e) { threw = true; }
    probe('slicing a name that is gone throws', threw, true);

    /* The one place the stub does a browser's work. */
    const scratch = new El('div');
    scratch.innerHTML = '<p>Peer support &amp; coffee</p>';
    probe('innerHTML gives the text back without the tags', scratch.textContent, 'Peer support & coffee');
    scratch.appendChild(new El('span'));
    scratch.innerHTML = '';
    probe('innerHTML = "" empties the element', scratch.kids.length + scratch.textContent.length, 0);

    /* The reader must see a picture as a picture and text as text. */
    const q = el('span', { class: 'uc-muted', text: 'some words' });
    probe('reads a text preview', q.textContent, 'some words');
    q.textContent = '';
    q.appendChild(el('img', { class: 'uc-prefill-thumb' }));
    probe('sees an <img> in a preview', !!q.querySelector('img'), true);

    console.log('\n' + (ok ? 'the reader can see what it is looking for.' : 'THE READER IS BROKEN.'));
    process.exit(ok ? 0 : 1);
}

/* =========================================================================
 * 1. THE IMAGE ROW SHOWS THE PICTURE, NOT THE FILE NAME.
 * ====================================================================== */

const only_image = build({
    '11': {
        location_mode: '', venue: 0, venue_name: '', location: '',
        start_time: '', end_time: '', description: '',
        image_url: SERIES_IMAGE, image_id: 0, image_preview: SERIES_IMAGE,
        categories: [], category_names: [], organizers: [], organizer_name: '',
        faq_set: '', faq_set_name: '', from_event: 0
    }
});
runOver(only_image);
only_image.select.choose('11');

expect('choosing a series opens the panel', only_image.panel.hidden, false);

const image_rows = rows(only_image);
expect('a series lending only a picture offers one row', image_rows.length, 1);
expect('and it is the image row', image_rows[0] && image_rows[0].key, 'image');
expect('the row shows the picture', image_rows[0] && !!image_rows[0].img, true);
expect('at the size the stylesheet gives it',
    image_rows[0] && image_rows[0].img && image_rows[0].img.class, 'uc-prefill-thumb');
expect('pointed at the series image',
    image_rows[0] && image_rows[0].img && image_rows[0].img.src, SERIES_IMAGE);
expect('the file name is not the visible value any more',
    image_rows[0] && image_rows[0].text.indexOf('prop-harm-reduction') > -1, false);
expect('but it is still what a screen reader is given',
    image_rows[0] && image_rows[0].img && image_rows[0].img.alt, 'prop-harm-reduction-1024x576.jpg');

/* A URL that will not load falls back to the name rather than to a broken
   image icon in the middle of the card. */
const broken = only_image.optsBox.querySelector('img');
if (broken) {
    broken.dispatchEvent({ type: 'error' });
    expect('a picture that will not load falls back to its name',
        rows(only_image)[0].text, 'prop-harm-reduction-1024x576.jpg');
    expect('and the broken picture is gone from the row',
        !!only_image.optsBox.querySelector('img'), false);
} else {
    fails.push('there is no picture in the row to fail to load; the rows above say why');
}

/* =========================================================================
 * 2. PRESSING THE BUTTON PUTS THE PICTURE ON THE SCREEN.
 *
 * The assertions the defect needed. Not "the hidden field was written": what
 * a person looking at the form afterwards can see.
 * ====================================================================== */

const applied = build({
    '11': {
        location_mode: '', venue: 0, venue_name: '', location: '',
        start_time: '', end_time: '', description: '',
        image_url: SERIES_IMAGE, image_id: 0, image_preview: SERIES_IMAGE,
        categories: [], category_names: [], organizers: [], organizer_name: '',
        faq_set: '', faq_set_name: '', from_event: 0
    }
});
runOver(applied);
applied.select.choose('11');

expect('before the button, the preview is hidden', applied.preview.style.display, 'none');
expect('before the button, the tag says the event has no picture of its own',
    applied.tag.textContent, 'Placeholder');

applied.applyBtn.click();

expect('the preview is showing', applied.preview.style.display, '');
expect('and it is showing the picture that arrived', applied.previewImg.src, SERIES_IMAGE);
expect('Remove is offered, as it is after Choose Image', applied.removeBtn.style.display, '');
expect('the tag says the picture is the event\'s own', applied.tag.textContent, 'Event-specific');
expect('the URL field carries the value that will be saved', applied.urlInput.value, SERIES_IMAGE);
expect('and the card says what it did',
    applied.said.textContent.indexOf('Filled in 1 thing') > -1, true);

/* =========================================================================
 * 3. A CHOSEN ATTACHMENT HAS NO URL TO WRITE, AND STILL HAS ONE TO SHOW.
 *
 * image_url is the value copied into the URL box and is legitimately empty
 * when the picture is a library file; image_preview is the URL for the screen.
 * Reading one key for both jobs is what would leave this case blank.
 * ====================================================================== */

const attachment = build({
    '11': {
        location_mode: '', venue: 0, venue_name: '', location: '',
        start_time: '', end_time: '', description: '',
        image_url: '', image_id: 4021, image_preview: SERIES_IMAGE,
        categories: [], category_names: [], organizers: [], organizer_name: '',
        faq_set: '', faq_set_name: '', from_event: 0
    }
});
runOver(attachment);
attachment.select.choose('11');
expect('a library picture is offered', rows(attachment).length, 1);
expect('and it is shown as a picture', !!rows(attachment)[0].img, true);

attachment.applyBtn.click();
expect('the attachment id is what gets saved', attachment.idInput.value, '4021');
expect('the URL box is left empty, so it cannot fight the chosen file', attachment.urlInput.value, '');
expect('and the preview still shows something', attachment.previewImg.src, SERIES_IMAGE);
expect('with the tag corrected', attachment.tag.textContent, 'Event-specific');

/* =========================================================================
 * 4. THE OTHER ROWS ARE UNTOUCHED.
 *
 * previewNode() is optional, and this release must not have turned every row
 * into one. Times and a description are text, and they are still written.
 * ====================================================================== */

const mixed = build({
    '11': {
        location_mode: '', venue: 0, venue_name: '', location: '',
        start_time: '18:00', end_time: '19:30', description: '<p>Peer support, every Monday, at the Strut.</p>',
        image_url: SERIES_IMAGE, image_id: 0, image_preview: SERIES_IMAGE,
        categories: [], category_names: [], organizers: [], organizer_name: '',
        faq_set: '', faq_set_name: '', from_event: 0
    }
});
runOver(mixed);
mixed.select.choose('11');

const mixed_rows = rows(mixed);
expect('three things are offered', mixed_rows.map(r => r.key), ['times', 'description', 'image']);
expect('the times row is text', mixed_rows[0].text, '18:00 to 19:30');
expect('the times row is not a picture', !!mixed_rows[0].img, false);
expect('the description row is its opening words',
    mixed_rows[1].text, 'Peer support, every Monday, at the Strut.');
expect('the description row is not a picture', !!mixed_rows[1].img, false);
expect('only the image row is a picture', !!mixed_rows[2].img, true);

mixed.applyBtn.click();
expect('the times were still written', [mixed.startInput.value, mixed.endInput.value], ['18:00', '19:30']);
expect('the description was still written',
    mixed.descInput.value, '<p>Peer support, every Monday, at the Strut.</p>');
expect('and the preview came up with them', mixed.previewImg.src, SERIES_IMAGE);
expect('the card counted all three',
    mixed.said.textContent.indexOf('Filled in 3 things') > -1, true);

/* ===================================================================== */

if (fails.length) {
    console.log('PREFILL IMAGE: ' + fails.length + ' FAILURE' + (fails.length === 1 ? '' : 'S'));
    fails.forEach(f => console.log('  - ' + f));
    process.exit(1);
}

console.log('PREFILL IMAGE');
console.log('  the card       shows the picture, with the file name as its alt text');
console.log('  the button     shows the preview, offers Remove, and turns the tag to Event-specific');
console.log('  an attachment  writes the id, leaves the URL box empty, and still previews');
console.log('  the other rows are still text, and are still written');
console.log('  decided by running initSeriesPrefill() and reading the document afterwards.');
process.exit(0);
