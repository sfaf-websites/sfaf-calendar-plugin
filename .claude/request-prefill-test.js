/**
 * DOES "FILL THESE IN" ON THE STAFF REQUEST FORM RENDER, AND DOES IT WRITE?
 *
 *     node .claude/request-prefill-test.js
 *     node .claude/request-prefill-test.js --self-test
 *
 * WHY THIS EXISTS, AND IT IS NOT A GENERAL WISH FOR COVERAGE. The caladmin
 * version of this control SAT DEAD FOR TWENTY-SIX RELEASES because a variable
 * was used before it was assigned, and nothing noticed: the call was present,
 * the file parsed, the callable audit was happy, and the card simply never
 * drew. The instruction that created this one said, in as many words, prove it
 * by RUNNING it rather than by the call being there. So this runs it.
 *
 * WHAT IT PROVES:
 *
 *   1. Choosing a series REVEALS the panel and builds one row per field the
 *      series can actually fill, with the value on the row.
 *   2. Choosing "Not part of one" hides it again.
 *   3. Pressing the button WRITES into the staff form's own field names:
 *      venue or venue_other, start_time, end_time, description, organizer,
 *      the image_id radio, and faq_set.
 *   4. THE DATE IS NEVER OFFERED AND NEVER WRITTEN. Neither is the title.
 *   5. Unticking a row means that field is left alone.
 *   6. A field somebody has already filled in is MARKED as one this would
 *      replace, rather than silently taken.
 *   7. It writes into fields and posts NOTHING. A control that applied by
 *      posting would discard every unsaved answer on the form, which is the
 *      3.3.0 fault people learned to avoid the FAQ set picker over.
 *
 * THE FUNCTION IS SLICED OUT OF portal.js, NOT COPIED, and the slice throws
 * rather than returning something plausible when the name is gone. A copy
 * would keep passing after the original changed.
 *
 * THE STUB IS DELIBERATELY SMALL: only what initRequestPrefill() touches.
 * A stub that does more can quietly answer a question a browser would have
 * answered differently, and --self-test feeds the selector matcher things it
 * must and must not match before any of it is trusted.
 */

'use strict';

const fs = require('fs');
const path = require('path');

const SRC = fs.readFileSync(path.join(__dirname, '..', 'public', 'js', 'portal.js'), 'utf8');
const selfTest = process.argv.slice(2).includes('--self-test');

const fails = [];
function expect(label, got, want) {
    const ok = JSON.stringify(got) === JSON.stringify(want);
    if (!ok) { fails.push(label + ': got ' + JSON.stringify(got) + ', expected ' + JSON.stringify(want)); }
    return ok;
}
function check(label, ok) {
    if (!ok) { fails.push(label); }
    return ok;
}

/* =========================================================================
 * Slicing the real function out of the real file.
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

/* =========================================================================
 * THE STUB. Tag, .class, [attr], [attr="value"] and :checked, which is
 * everything the sliced code asks for and nothing else.
 * ====================================================================== */
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
        this.own = '';
        this.value = '';
        this._checked = false;
        this.id = '';
    }
    /*
     * A RADIO GROUP IS EXCLUSIVE, AND THE BROWSER IS WHAT MAKES IT SO. The code
     * under test sets `radio.checked = true` and nothing else: unchecking the
     * one that was checked is the browser's job, not the plugin's. A stub
     * without this would make the test assert something the code does not do
     * and could not do, which is the failure this file's header warns about
     * from the other direction.
     */
    get checked() { return this._checked; }
    set checked(v) {
        this._checked = !!v;
        if (!v || 'radio' !== this.type || !this.attrs.name) { return; }
        const scope = this.form || this.parentNode;
        if (!scope || !scope.querySelectorAll) { return; }
        scope.querySelectorAll('[name="' + this.attrs.name + '"]').forEach(other => {
            if (other !== this && 'radio' === other.type) { other._checked = false; }
        });
    }
    /* REFLECTED, as a browser reflects it. The code under test creates a
       checkbox with `box.type = 'checkbox'` and this test then asks for
       `[type="checkbox"]`, which in a browser finds it because the property
       and the attribute are the same thing. A stub where they are not is a
       stub that answers that question differently from the browser, which is
       the one thing this file's header says a stub must not do. */
    get type() { return this.attrs.type || ''; }
    set type(v) { this.attrs.type = String(v); }

    get className() { return this.attrs.class || ''; }
    set className(v) { this.attrs.class = String(v); }
    classes() { return this.className.split(/\s+/).filter(Boolean); }

    get hidden() { return this.attrs.hidden !== undefined; }
    set hidden(v) { if (v) { this.attrs.hidden = 'hidden'; } else { delete this.attrs.hidden; } }

    getAttribute(n) { return this.attrs[n] === undefined ? null : this.attrs[n]; }
    setAttribute(n, v) { this.attrs[n] = String(v); }
    removeAttribute(n) { delete this.attrs[n]; }
    hasAttribute(n) { return this.attrs[n] !== undefined; }

    get textContent() { return this.own + this.kids.map(k => k.textContent).join(''); }
    set textContent(v) { this.kids = []; this.own = String(v); }

    /* The only markup this code sets is `innerHTML = ''`, clearing the options
       box. Anything else would be a case this stub is not equipped to answer. */
    set innerHTML(v) {
        if (String(v) !== '') {
            throw new Error('the stub was asked to parse markup, which it cannot do honestly');
        }
        this.kids = [];
        this.own = '';
    }

    appendChild(node) { node.parentNode = this; this.kids.push(node); return node; }
    descendants(out) {
        out = out || [];
        for (const k of this.kids) { out.push(k); k.descendants(out); }
        return out;
    }
    querySelector(sel) { const m = this.querySelectorAll(sel); return m.length ? m[0] : null; }
    querySelectorAll(sel) { const t = compile(sel); return this.descendants().filter(t); }

    addEventListener(type, fn) { (this.listeners[type] = this.listeners[type] || []).push(fn); }
    dispatchEvent(ev) {
        (this.listeners[ev.type] || []).forEach(fn => fn.call(this, ev));
        return true;
    }
    click() { this.dispatchEvent({ type: 'click', preventDefault() {} }); }
}

class Select extends El {
    constructor() { super('select'); this.options = []; this.value = ''; }
    option(value, text) {
        const o = new El('option');
        o.attrs.value = value;
        o.own = text;
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
 * THE DOCUMENT, in the shape the staff request form renders it.
 *
 * request-form-test.php is the other half of this claim: it renders the real
 * form and asserts the panel and its data block are there. Neither test is
 * worth much alone; the pair is the assertion.
 * ====================================================================== */
const PAYLOAD = {
    '11': {
        location_mode: 'venue',
        venue: 7,
        venue_name: 'Strut',
        location: '470 Castro St',
        start_time: '18:00',
        end_time: '20:00',
        description: '<p>A <strong>weekly</strong> group for anybody who wants one.</p>',
        image_url: 'https://example.org/wp-content/uploads/calendar/strut.jpg',
        image_id: 41,
        image_preview: '',
        categories: [3],
        category_names: ['Support Groups'],
        organizers: [9, 12],
        organizer_name: 'Strut',
        faq_set: 'clinic',
        faq_set_name: 'Clinic basics',
        from_event: 900
    }
};

function build(opts) {
    opts = opts || {};

    const select = new Select();
    select.attrs.name = 'series';
    select.attrs['data-uc-request-series'] = '';
    select.option('0', 'Not part of one');
    select.option('11', 'Monday Support Group');

    const venue = new Select();
    venue.attrs.name = 'venue';
    venue.option('0', 'Somewhere else');
    venue.option('7', 'Strut');
    venue.value = '0';

    /* TICK BOXES SINCE 3.84.0, not a select. This stub asserted the old
       arrangement and is rewritten to assert the new one rather than relaxed:
       the payload carries TWO organizers and both boxes must end up ticked,
       which the single select could never have expressed. The third box is here
       so "it ticks everything" would fail as loudly as "it ticks the first". */
    const org9 = el('input', { type: 'checkbox', name: 'organizer[]', value: '9' });
    const org12 = el('input', { type: 'checkbox', name: 'organizer[]', value: '12' });
    const org30 = el('input', { type: 'checkbox', name: 'organizer[]', value: '30' });
    /* No opts.organizer branch. The select had one and nothing ever passed it,
       so it was dead on arrival here; the clash case this file tests uses the
       description and the venue, which callers do pass. */
    const organizer = org9;

    const faqSet = new Select();
    faqSet.attrs.name = 'faq_set';
    faqSet.option('', 'None');
    faqSet.option('clinic', 'Clinic basics');
    faqSet.value = '';

    const venueOther = el('input', { name: 'venue_other', value: opts.venueOther || '' });
    const start = el('input', { name: 'start_time', value: opts.start || '' });
    const end = el('input', { name: 'end_time', value: '' });
    const description = el('textarea', { name: 'description', value: opts.description || '' });
    const date = el('input', { name: 'date', value: '' });
    const title = el('input', { name: 'title', value: '' });

    const radioNone = el('input', { type: 'radio', name: 'image_id', value: '0', 'data-uc-image-name': 'No picture' });
    radioNone.checked = true;
    const radio41 = el('input', { type: 'radio', name: 'image_id', value: '41', 'data-uc-image-name': 'Strut clinic' });

    const optsBox = el('div', { class: 'uc-prefill-opts', 'data-uc-prefill-opts': '' });
    const nameOut = el('strong', { 'data-uc-prefill-name': '' });
    const applyBtn = el('button', { 'data-uc-prefill-apply': '', text: 'Fill these in' });
    const noneBtn = el('button', { 'data-uc-prefill-none': '', text: 'Start from scratch' });
    const said = el('p', { class: 'uc-prefill-said', 'data-uc-prefill-said': '', hidden: 'hidden' });
    const panel = el('div', { class: 'uc-prefill', 'data-uc-request-prefill': '', hidden: 'hidden' },
        [nameOut, optsBox, applyBtn, noneBtn, said]);

    const dataNode = el('script', { 'data-uc-request-prefill-data': '' });
    dataNode.own = JSON.stringify(opts.payload === undefined ? PAYLOAD : opts.payload);

    const form = el('form', { class: 'uc-form' },
        [select, venue, venueOther, org9, org12, org30, faqSet, start, end, description, date, title,
         radioNone, radio41, panel, dataNode]);

    const root = el('div', {}, [form]);

    /* .form on a control, which the sliced code reads to find the form. */
    [select, venue, venueOther, org9, org12, org30, faqSet, start, end, description, radioNone, radio41]
        .forEach(c => { c.form = form; });

    const doc = {
        querySelector(sel) { return root.querySelector(sel); },
        querySelectorAll(sel) { return root.querySelectorAll(sel); },
        createElement(tag) { return new El(tag); }
    };

    return {
        doc, root, form, select, venue, venueOther, organizer, org9, org12, org30, faqSet,
        start, end, description, date, title, radioNone, radio41,
        panel, optsBox, nameOut, applyBtn, noneBtn, said
    };
}

/** Run the real initRequestPrefill() against one of those documents. */
function run(world, source) {
    const body = source || slice('initRequestPrefill');
    const sandbox = {
        document: world.doc,
        window: { tinymce: null },
        Event: function (type, init) { this.type = type; this.bubbles = !!(init && init.bubbles); }
    };
    const fn = new Function('document', 'window', 'Event',
        body + '\nreturn initRequestPrefill;');
    fn(sandbox.document, sandbox.window, sandbox.Event)();
}

/* =========================================================================
 * SELF-TEST: the reader, before anything is trusted.
 * ====================================================================== */
if (selfTest) {
    const caught = {};

    /* The matcher must match what it claims and refuse what it does not. */
    let ok = true;
    try {
        const a = el('input', { name: 'venue_other' });
        ok = ok && compile('[name="venue_other"]')(a) && !compile('[name="venue"]')(a);
        const b = el('input', { type: 'radio', name: 'image_id', value: '41' });
        b.checked = true;
        ok = ok && compile('[name="image_id"]:checked')(b);
        b.checked = false;
        ok = ok && !compile('[name="image_id"]:checked')(b);
    } catch (e) { ok = false; }
    caught['the selector matcher reads what it claims to read'] = ok;

    /* An unreadable selector must throw rather than match nothing quietly. */
    let threw = false;
    try { compile('div > p'); } catch (e) { threw = true; }
    caught['an unreadable selector throws rather than matching nothing'] = threw;

    /* THE 3.64.0 FAULT ITSELF: the panel never revealed. Planted by making the
       payload empty, which is the same observable as a card that does not draw. */
    const dead = build({ payload: {} });
    run(dead);
    dead.select.choose('11');
    caught['a panel that never reveals'] = dead.panel.hidden === true;

    /* And a write that goes to the wrong field name, which is what "the
       caladmin applier, unchanged" would have been. */
    const wrong = build();
    const bad = slice('initRequestPrefill').split("'venue_other'").join("'location'");
    run(wrong, bad);
    wrong.select.choose('11');
    wrong.applyBtn.click();
    caught['a write aimed at a field this form does not have'] = wrong.venueOther.value === '';

    /* The slicer must refuse a name that is gone. */
    let sliceThrew = false;
    try { slice('initSomethingThatIsNotThere'); } catch (e) { sliceThrew = true; }
    caught['the slicer refuses a function that is gone'] = sliceThrew;

    Object.keys(caught).forEach(k => {
        console.log('  ' + k.padEnd(56) + (caught[k] ? 'caught' : 'MISSED'));
    });
    const allOk = Object.keys(caught).every(k => caught[k]);
    console.log('');
    if (!allOk) {
        console.error('self-test FAILED');
        process.exit(1);
    }
    console.log('self-test passed: the reader reads what it claims, and both faults are caught.');
    process.exit(0);
}

/* =========================================================================
 * 1. IT RENDERS.
 * ====================================================================== */
const w = build();
run(w);

check('the panel starts hidden, before a series is chosen', w.panel.hidden === true);

w.select.choose('11');
check('choosing a series reveals the panel', w.panel.hidden === false);
expect('and names the series', w.nameOut.textContent.trim(), 'Monday Support Group');

const labels = w.optsBox.querySelectorAll('.uc-prefill-opt-label').map(n => n.textContent);
expect('one row per field the series can fill', labels,
    ['Location', 'Start and end time', 'Description', 'Organizer', 'Picture', 'FAQ set']);

const values = w.optsBox.querySelectorAll('.uc-prefill-opt-value').map(n => n.textContent);
check('the location row shows the venue name', values[0] === 'Strut');
check('the times row shows both times', values[1] === '18:00 to 20:00');
check('the description row shows its opening words', values[2].indexOf('weekly') > -1);
check('the organizer row names the organizer', values[3] === 'Strut');
check('the picture row names the picture', values[4] === 'Strut clinic');
check('the FAQ row names the set', values[5] === 'Clinic basics');

/* 4. THE DATE IS NEVER OFFERED, and neither is the title. */
check('no row offers the date', labels.indexOf('Date') === -1);
check('no row offers the title', labels.indexOf('Title') === -1);

/* 2. AND IT HIDES AGAIN. */
w.select.choose('0');
check('choosing "Not part of one" hides the panel again', w.panel.hidden === true);

/* =========================================================================
 * 3. IT WRITES, INTO THIS FORM'S OWN FIELD NAMES.
 * ====================================================================== */
const a = build();
run(a);
a.select.choose('11');
a.applyBtn.click();

expect('the venue select is set', a.venue.value, '7');
expect('the start time is written', a.start.value, '18:00');
expect('the end time is written', a.end.value, '20:00');
check('the description is written', a.description.value.indexOf('<strong>weekly</strong>') > -1);
/* EVERY ORGANIZER THE PAYLOAD CARRIES, not the first (3.84.0). This asserted
   the first and nothing else, so it passed while the panel previewed "A and B"
   and the form received A. Both ticked is the claim now, and the third box
   staying clear is what stops "tick everything" from passing in its place. */
check('the first organizer is ticked', a.org9.checked === true);
check('and so is the second', a.org12.checked === true);
check('an organizer the series does not lend is left clear', a.org30.checked === false);
expect('the FAQ set is set', a.faqSet.value, 'clinic');
check('the picture radio is checked', a.radio41.checked === true);
check('and the "no picture" radio is not', a.radioNone.checked === false);

/* 4 again, against what was actually WRITTEN rather than what was offered. */
expect('the date is untouched', a.date.value, '');
expect('the title is untouched', a.title.value, '');

check("it says how many it filled in", a.said.hidden === false && /6 fields/.test(a.said.textContent));

/* =========================================================================
 * 5. UNTICKING A ROW LEAVES THAT FIELD ALONE.
 * ====================================================================== */
const b = build();
run(b);
b.select.choose('11');
const boxes = b.optsBox.querySelectorAll('[type="checkbox"]');
check('every row starts ticked', boxes.every(x => x.checked === true));
boxes[1].checked = false; // times
b.applyBtn.click();
expect('an unticked row is not written', b.start.value, '');
expect('and the ticked ones still are', b.venue.value, '7');

/* =========================================================================
 * 6. A FIELD ALREADY ANSWERED IS MARKED AS ONE THIS WOULD REPLACE.
 * ====================================================================== */
const c = build({ start: '09:00', venueOther: '1035 Market St' });
run(c);
c.select.choose('11');
const warned = c.optsBox.querySelectorAll('.uc-prefill-opt')
    .filter(row => row.querySelectorAll('.uc-prefill-opt-warn').length > 0)
    .map(row => row.querySelectorAll('.uc-prefill-opt-label')[0].textContent);
expect('the two fields already answered are the two marked', warned.sort(),
    ['Location', 'Start and end time']);

/* =========================================================================
 * 7. NOTHING POSTS.
 * ====================================================================== */
const source = slice('initRequestPrefill');
check('it never submits the form', !/\.submit\s*\(/.test(source));
check('it never navigates', !/location\s*\.\s*(href|assign|replace)/.test(source));
check('it never fetches', !/\bfetch\s*\(|XMLHttpRequest/.test(source));

/* ------------------------------------------------------------------------- */
if (fails.length) {
    console.log('REQUEST PREFILL: ' + fails.length + ' FAILURE(S)');
    fails.forEach(f => console.log('  - ' + f));
    process.exit(1);
}

console.log('Fill this in from the last one, on the staff request form');
console.log('  renders   choosing a series reveals the panel and builds one row per field the');
console.log('            series can fill, each naming the value it would write');
console.log('  applies   pressing it writes into THIS form\'s fields: venue, times,');
console.log('            description, organizer, the image_id radio and the FAQ set');
console.log('  never     the date, and never the title');
console.log('  respects  an unticked row is left alone, and a field already answered is');
console.log('            marked as one this would replace');
console.log('  posts     nothing. No submit, no navigation, no fetch, so an unsaved answer');
console.log('            on the form survives pressing it');
console.log('');
console.log('Run by executing the real function out of portal.js, not by reading it. The');
console.log('caladmin twin of this control sat dead for twenty-six releases because nothing');
console.log('ever ran it.');
