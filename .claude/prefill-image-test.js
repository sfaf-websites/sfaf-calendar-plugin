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
 * asserts that `.uc-image-field` carries `[data-uc-image-block]`,
 * `[data-uc-image-remove]`, `[data-uc-image-reset]`, `[data-uc-image-preview]`,
 * `[data-uc-image-preview-img]` and `[data-uc-img-source-tag]` with its
 * `data-uc-img-source-own` label, and no `[data-uc-image-url]` (3.108.1).
 * Rename one of those and that file fails.
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

/* 3.108.1: the prefill writes through the picker's radios, and initImageBlock()
   is what puts the result on the screen, so both run. */
const NEEDED = ['initSeriesPrefill', 'initImageBlock'];

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

    /* A RADIO GROUP, as a browser keeps one (3.108.1): checking a radio
       unchecks every other radio of the same name in the same document. */
    get checked() { return !!this._checked; }
    set checked(v) {
        this._checked = !!v;
        if (!v || this.attrs.type !== 'radio' || !this.attrs.name) { return; }
        let top = this;
        while (top.parentNode) { top = top.parentNode; }
        top.descendants().forEach(n => {
            if (n !== this && n.attrs.type === 'radio' && n.attrs.name === this.attrs.name) { n._checked = false; }
        });
    }

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
    /* An event that says it bubbles goes up the parents, with its target set,
       because initImageBlock() hears the picker's radios from the picker. */
    dispatchEvent(ev) {
        if (!ev.target) { ev.target = this; }
        for (let node = this; node; node = ev.bubbles ? node.parentNode : null) {
            (node.listeners[ev.type] || []).forEach(fn => fn.call(node, ev));
        }
        return true;
    }
    click() { this.dispatchEvent({ type: 'click', preventDefault() {} }); }
    focus() {}
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
const LIBRARY_IMAGE = 'https://example.org/wp-content/uploads/sfaf-calendar/library-4021.jpg';

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

    /* The featured image block, as render_image_picker() emits it on a new
       event since 3.108.1: no URL box, the pill and Remove on the preview, and
       the picker's radios, "The series picture" (0) and one library picture. */
    const tag = el('span', {
        class: 'uc-image-pill',
        'data-uc-img-source-tag': '',
        'data-uc-img-source-own': 'Event-specific',
        'data-uc-img-source-series': 'From series',
        'data-uc-img-source-none': 'Placeholder',
        text: 'Placeholder'
    });
    const previewImg = el('img', { 'data-uc-image-preview-img': '', hidden: 'hidden' });
    const removeBtn = el('button', { class: 'uc-image-remove', 'data-uc-image-remove': '', hidden: 'hidden', text: 'Remove' });
    const preview = el('div', { class: 'uc-image-preview uc-image-preview-empty', 'data-uc-image-preview': '' }, [previewImg, tag, removeBtn]);
    const resetInput = el('input', { type: 'hidden', name: 'reset_series_image', value: '1', 'data-uc-image-reset': '', disabled: 'disabled' });
    resetInput.disabled = true;
    const noneRadio = el('input', { type: 'radio', name: 'featured_image_id', value: '0', 'data-uc-image-option': '' });
    noneRadio.checked = true;
    const libRadio = el('input', { type: 'radio', name: 'featured_image_id', value: '4021', 'data-uc-image-option': '', 'data-uc-image-full': LIBRARY_IMAGE });
    const current = el('span', { 'data-uc-image-current': '' });
    const picker = el('details', { 'data-uc-image-picker': '' }, [el('summary', {}, [current]), noneRadio, libRadio]);
    const block = el('div', {
        'data-uc-image-block': '',
        'data-uc-series-pictures': JSON.stringify({ '11': { src: SERIES_IMAGE, name: 'prop-harm-reduction-1024x576.jpg' } }),
        'data-uc-image-start': 'none',
        'data-uc-image-start-name': ''
    }, [preview, resetInput, picker]);

    const imageField = el('div', { class: 'uc-field uc-image-field' }, [
        el('span', { class: 'uc-field-label', text: 'Featured Image' }),
        block
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

    /* THE LOCATION, AS render_location_field() DRAWS IT ON A NATIVE EVENT
       (3.105.0): two mode radios, the venue select, and five boxes under "A
       different location". There is NO field named location here, because the
       editor has none on a native event; until 3.105.0 this document had no
       location controls at all, so the location option was never exercised and
       the real Fill these in wrote the address to nothing.
       series-control-test.php holds these names to the rendered form. */
    const modeVenue = el('input', { type: 'radio', name: 'location_mode', value: 'venue', 'data-uc-location-mode': 'venue' });
    const modeCustom = el('input', { type: 'radio', name: 'location_mode', value: 'custom', 'data-uc-location-mode': 'custom' });
    const venueSel = el('select', { name: 'venue', value: '0' });
    const loc = {};
    ['location_name', 'location_street', 'location_city', 'location_state', 'location_zip'].forEach(n => {
        loc[n] = el('input', { type: 'text', name: n, value: '' });
    });

    const form = el('form', { class: 'uc-form' }, [card, imageField, startH, startM, endH, endM, descInput,
        modeVenue, venueSel, modeCustom].concat(Object.keys(loc).map(k => loc[k])));
    const root = el('div', {}, [form]);
    select.form = form;

    return {
        root, form, select, panel, optsBox, applyBtn, noneBtn, said,
        tag, block, resetInput, noneRadio, libRadio, current, preview, previewImg, removeBtn,
        startInput, endInput, descInput, modeVenue, modeCustom, venueSel, loc
    };
}

function runOver(dom, confirmAnswer) {
    const document = {
        createElement: tag => new El(tag),
        querySelector: sel => dom.root.querySelector(sel),
        querySelectorAll: sel => dom.root.querySelectorAll(sel)
    };
    const window = { confirm: () => (confirmAnswer === undefined ? true : confirmAnswer) };
    const EventStub = function (type, init) { this.type = type; this.bubbles = !!(init && init.bubbles); };

    const make = new Function('document', 'window', 'Event',
        NEEDED.map(slice).join('\n\n') + '\nreturn { initSeriesPrefill: initSeriesPrefill, initImageBlock: initImageBlock };');
    const fns = make(document, window, EventStub);
    fns.initSeriesPrefill();
    fns.initImageBlock();
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

    /* The radio group, which initImageBlock() reads through :checked. */
    const r1 = el('input', { type: 'radio', name: 'g' });
    const r2 = el('input', { type: 'radio', name: 'g' });
    const r3 = el('input', { type: 'radio', name: 'other' });
    el('form', {}, [r1, r2, r3]);
    r1.checked = true; r3.checked = true; r2.checked = true;
    probe('checking a radio unchecks its group and only its group', [r1.checked, r2.checked, r3.checked], [false, true, true]);

    /* An event that bubbles reaches the parent, with its target. */
    const kid = el('span', {});
    const mum = el('div', {}, [kid]);
    let heard = null;
    mum.addEventListener('change', e => { heard = e.target; });
    kid.dispatchEvent({ type: 'change', bubbles: true });
    probe('a bubbling event reaches the parent, target set', heard === kid, true);
    heard = null;
    kid.dispatchEvent({ type: 'change' });
    probe('one that does not bubble stays put', heard, null);

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

/* 3.108.1: with no URL box to copy into, a series picture that is not a
   library row stays inherited. The screen says so: the series picture in the
   preview, the pill reading From series, and no Remove. */
expect('choosing the series already shows its picture', [applied.previewImg.src, applied.previewImg.hidden], [SERIES_IMAGE, false]);
expect('and the pill says where it comes from', applied.tag.textContent, 'From series');

applied.applyBtn.click();

expect('after the button the series picture is still showing', applied.previewImg.src, SERIES_IMAGE);
expect('The series picture is the chosen row', [applied.noneRadio.checked, applied.libRadio.checked], [true, false]);
expect('the pill still says From series', applied.tag.textContent, 'From series');
expect('there is nothing of its own to Remove', applied.removeBtn.hidden, true);
expect('the picker names the inherited picture', applied.current.textContent, 'prop-harm-reduction-1024x576.jpg');
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
expect('the attachment is the chosen radio, which is what gets saved', [attachment.libRadio.checked, attachment.noneRadio.checked], [true, false]);
expect('the preview shows that picture', attachment.previewImg.src, LIBRARY_IMAGE);
expect('the pill says it is the event\'s own', attachment.tag.textContent, 'Event-specific');
expect('Remove is offered on it', attachment.removeBtn.hidden, false);
expect('and the reset is off, so the save keeps it', attachment.resetInput.disabled, true);
attachment.removeBtn.click();
expect('Remove falls back to the series picture', [attachment.noneRadio.checked, attachment.tag.textContent, attachment.previewImg.src], [true, 'From series', SERIES_IMAGE]);
expect('and turns the reset on, so the save clears it', attachment.resetInput.disabled, false);

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
expect('and the series picture is showing with them', mixed.previewImg.src, SERIES_IMAGE);
expect('the card counted all three',
    mixed.said.textContent.indexOf('Filled in 3 things') > -1, true);

/* =========================================================================
 * 5. THE LOCATION LANDS IN THE BOXES THE EDITOR HAS (3.105.0).
 *
 * A place of its own: the name and the four parts, each in its own box, and
 * the mode switched to "A different location". Then a venue: the select, and
 * nothing typed into the boxes.
 * ====================================================================== */

const own_place = build({
    '11': {
        location_mode: 'custom', venue: 0, venue_name: '', location: '470 Castro St, San Francisco, CA 94114',
        location_name: 'Strut',
        location_parts: { street: '470 Castro St', city: 'San Francisco', state: 'CA', zip: '94114' },
        start_time: '', end_time: '', description: '',
        image_url: '', image_id: 0, image_preview: '',
        categories: [], category_names: [], organizers: [], organizer_name: '',
        faq_set: '', faq_set_name: '', from_event: 0
    }
});
runOver(own_place);
own_place.select.choose('11');
expect('a series whose last event had its own address offers the location',
    rows(own_place).map(r => r.key), ['location']);
expect('and the card shows the place and its address',
    rows(own_place)[0] && rows(own_place)[0].text, 'Strut, 470 Castro St, San Francisco, CA 94114');
own_place.applyBtn.click();
expect('the mode is switched to a different location', own_place.modeCustom.checked, true);
expect('the name and the four parts are each in their own box', {
    name: own_place.loc.location_name.value,
    street: own_place.loc.location_street.value,
    city: own_place.loc.location_city.value,
    state: own_place.loc.location_state.value,
    zip: own_place.loc.location_zip.value
}, { name: 'Strut', street: '470 Castro St', city: 'San Francisco', state: 'CA', zip: '94114' });
expect('and nothing went looking for a field called location',
    own_place.form.querySelectorAll('[name="location"]').length, 0);

const at_venue = build({
    '11': {
        location_mode: 'venue', venue: 7, venue_name: 'Strut', location: '',
        location_name: '', location_parts: { street: '', city: '', state: '', zip: '' },
        start_time: '', end_time: '', description: '',
        image_url: '', image_id: 0, image_preview: '',
        categories: [], category_names: [], organizers: [], organizer_name: '',
        faq_set: '', faq_set_name: '', from_event: 0
    }
});
runOver(at_venue);
at_venue.select.choose('11');
at_venue.applyBtn.click();
expect('a venue is chosen in the venue select', [at_venue.modeVenue.checked, at_venue.venueSel.value], [true, '7']);
expect('and the address boxes are left alone', at_venue.loc.location_street.value, '');

/* ===================================================================== */

if (fails.length) {
    console.log('PREFILL IMAGE: ' + fails.length + ' FAILURE' + (fails.length === 1 ? '' : 'S'));
    fails.forEach(f => console.log('  - ' + f));
    process.exit(1);
}

console.log('PREFILL IMAGE');
console.log('  the card       shows the picture, with the file name as its alt text');
console.log('  the button     leaves a series picture inherited, From series, named, with no Remove');
console.log('  an attachment  is chosen in the picker, Event-specific, and Remove falls back to the series');
console.log('  the other rows are still text, and are still written');
console.log('  decided by running initSeriesPrefill() and reading the document afterwards.');
process.exit(0);
