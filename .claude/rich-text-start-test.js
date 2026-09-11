/**
 * DO THE FAQ ANSWERS GET AN EDITOR ON PAGE LOAD, OR ONLY AFTER "ADD FAQ"?
 *
 *     node .claude/rich-text-start-test.js --self-test
 *     node .claude/rich-text-start-test.js
 *
 * WHY THIS EXISTS. Three releases were spent on "the FAQ editors do not start",
 * and the rows being looked at turned out to be the LOCKED ones a source owns,
 * which are not editors and are not meant to be. That left the real question
 * unanswered: an event whose FAQs came from a set, or were typed by hand, has
 * deferred rows on the page at load, and nobody had established whether those
 * get started then or only when Add FAQ is pressed.
 *
 * This answers the half that is ours. It runs initRichText() from the shipping
 * source against a document holding two stored rows and a template, with
 * wp.editor stubbed, and reads back which ids initialize() was called for.
 *
 * WHAT IT PROVES:
 *   . both STORED rows are started at load, not just after Add
 *   . the row inside the <template> is NOT started, because it is a pattern
 *   . a row added afterwards is started, and the stored ones are not started
 *     twice
 *   . a throw from initialize() is REPORTED rather than swallowed, which is
 *     the 3.72.0 logging and the only reason the real fault is diagnosable
 *
 * WHAT IT CANNOT PROVE, and this is the honest boundary: whether WordPress's
 * real wp.editor.initialize() then succeeds in a browser. That needs TinyMCE,
 * an iframe and a real document. What this settles is that the plugin asks,
 * correctly, for every row it should ask for, at load. If the editors are
 * still plain text after this, the cause is on WordPress's side of that call
 * and the console line this file asserts on is what will say so.
 */

const fs = require('fs');
const path = require('path');
const vm = require('vm');

const ROOT = path.dirname(__dirname);
const SRC = path.join(ROOT, 'public/js/portal.js');

/* ---------------------------------------------------------------------------
 * A document, in miniature. Only what initRichText() touches.
 * ------------------------------------------------------------------------ */
function makeDoc(rows) {
    const settings = {
        tagName: 'SCRIPT',
        id: 'uc-rich-settings',
        textContent: JSON.stringify({ tinymce: { toolbar1: 'bold' }, quicktags: false }),
        innerHTML: '',
    };

    const areas = rows.map((r) => ({
        tagName: 'TEXTAREA',
        id: r.id || '',
        _inTemplate: !!r.inTemplate,
        _attrs: {},
        getAttribute(k) { return Object.prototype.hasOwnProperty.call(this._attrs, k) ? this._attrs[k] : null; },
        setAttribute(k, v) { this._attrs[k] = v; },
        removeAttribute(k) { delete this._attrs[k]; },
        closest(sel) { return (sel === 'template' && this._inTemplate) ? { tagName: 'TEMPLATE' } : null; },
    }));

    return {
        _areas: areas,
        getElementById(id) { return id === 'uc-rich-settings' ? settings : null; },
        /* The real selector cannot see inside a <template>, and neither does
           this: template contents live in a DocumentFragment, not the tree. */
        querySelectorAll(sel) {
            if (sel === 'textarea[data-uc-rich]') {
                return areas.filter((a) => !a._inTemplate);
            }
            if (sel === 'textarea[data-uc-rich]:not([data-uc-rich-on])') {
                return areas.filter((a) => !a._inTemplate && !a.getAttribute('data-uc-rich-on'));
            }
            return [];
        },
        querySelector(sel) {
            const all = this.querySelectorAll(sel);
            return all.length ? all[0] : null;
        },

        /* ------------------------------------------------------------------
         * THE THIRD WAY A ROW ARRIVES (3.74.0).
         *
         * A FAQ set is applied in place: initFaqSetPicker() clones the
         * <template>, fills it in and appends it, with no reload. So the rows
         * exist neither at load nor through the repeater's Add button, and
         * whether they are ever asked for an editor depends entirely on
         * whether initRichText()'s document listener matches the button that
         * put them there. That is what _press() is here to exercise.
         * --------------------------------------------------------------- */
        _clicks: [],
        addEventListener(type, fn) {
            if ('click' === type && typeof fn === 'function') { this._clicks.push(fn); }
        },
        /* A click whose target answers closest() for one selector only. The
           real listener asks for a comma list, so this splits it the way a
           browser would rather than comparing the whole string. */
        _press(what) {
            const target = {
                closest(sel) {
                    const parts = String(sel).split(',').map((s) => s.trim());
                    return parts.indexOf(what) >= 0 ? { tagName: 'BUTTON' } : null;
                },
            };
            this._clicks.forEach((fn) => fn({ target }));
        },
        /* A row appended after load, the way the set picker appends one. */
        _append(id) {
            areas.push({
                tagName: 'TEXTAREA',
                id: id,
                _inTemplate: false,
                _attrs: {},
                getAttribute(k) { return Object.prototype.hasOwnProperty.call(this._attrs, k) ? this._attrs[k] : null; },
                setAttribute(k, v) { this._attrs[k] = v; },
                removeAttribute(k) { delete this._attrs[k]; },
                closest() { return null; },
            });
        },
    };
}

/* Pull initRichText() and its one helper out of the shipping file. */
function slice(src) {
    const start = src.indexOf('    function richTextSettings() {');
    if (start < 0) { return null; }
    const marker = '\n    }\n';
    const initAt = src.indexOf('    function initRichText() {', start);
    if (initAt < 0) { return null; }
    // initRichText ends at the first line that is exactly four spaces + }
    let end = initAt;
    while (true) {
        end = src.indexOf(marker, end + 1);
        if (end < 0) { return null; }
        const after = src.slice(end + marker.length, end + marker.length + 200);
        if (/^\s*\/\*|^\s*function|^\s*\}\)\(\);/.test(after)) { break; }
    }
    return src.slice(start, end + marker.length);
}

function run(source, opts) {
    opts = opts || {};
    const doc = makeDoc(opts.rows);
    const started = [];
    const errors = [];

    const sandbox = {
        document: doc,
        console: {
            error(...a) { errors.push(a.map(String).join(' ')); },
            log() {}, warn() {},
        },
    };
    sandbox.window = {
        console: sandbox.console,
        wp: {
            editor: {
                initialize(id) {
                    if (opts.throwOn && opts.throwOn === id) { throw new Error('planted'); }
                    started.push(id);
                },
                remove() {},
            },
        },
        setTimeout(fn) { (sandbox.__timers = sandbox.__timers || []).push(fn); },
    };
    sandbox.wp = sandbox.window.wp;

    vm.createContext(sandbox);
    vm.runInContext(source + '\ninitRichText();', sandbox, { filename: 'portal.js (initRichText)' });

    /* Drain the deferred passes the way a browser would. */
    function drain() {
        let guard = 0;
        while (sandbox.__timers && sandbox.__timers.length && guard < 40) {
            const fn = sandbox.__timers.shift();
            fn();
            guard++;
        }
    }
    drain();

    return {
        started,
        errors,
        doc,
        /* Append a row and press the control that put it there, then let the
           deferred task run. Returns the ids started by that press alone. */
        press(what, newRow) {
            const before = started.length;
            if (newRow) { doc._append(newRow); }
            doc._press(what);
            drain();
            return started.slice(before);
        },
    };
}

/* ------------------------------------------------------------------------- */
const selfTest = process.argv.slice(2).includes('--self-test');
const src = fs.readFileSync(SRC, 'utf8');
const body = slice(src);

if (!body) {
    console.error('could not slice initRichText() out of portal.js');
    process.exit(1);
}

const fails = [];
function expect(what, got, want) {
    if (JSON.stringify(got) !== JSON.stringify(want)) {
        fails.push(what + ': got ' + JSON.stringify(got) + ', expected ' + JSON.stringify(want));
    }
}

if (selfTest) {
    /* Plant 1: the load pass is removed, so only Add would ever start a row.
       That is the fault the whole investigation was about, and it must fail. */
    const noLoad = body.replace('window.setTimeout(pass, 0);', '/* planted: no load pass */');
    if (noLoad === body) {
        console.error('self-test: could not find the load pass to remove');
        process.exit(1);
    }
    const a = run(noLoad, { rows: [{ id: 'faq-1' }, { id: 'faq-2' }, { id: 'tpl', inTemplate: true }] });
    const caught1 = a.started.length === 0;
    console.log('  ' + 'the load pass removed, so nothing starts at load'.padEnd(56) + (caught1 ? 'caught' : 'MISSED'));

    /* Plant 2: the logging is swallowed again. */
    const noLog = body.replace(/if \(window\.console && window\.console\.error\) \{[\s\S]*?\n                \}/, '/* planted: silent */');
    const b = run(noLog, { rows: [{ id: 'faq-1' }], throwOn: 'faq-1' });
    const caught2 = b.errors.length === 0;
    console.log('  ' + 'a throw from initialize() is swallowed again'.padEnd(56) + (caught2 ? 'caught' : 'MISSED'));

    /* Plant 3: the 3.73.0 arrangement. The listener matches the repeater's own
       Add button and nothing else, so a row a FAQ SET appended is never asked
       for an editor and stays a plain box until some other press sweeps the
       document. That is the fault reported after 3.73.0 installed. */
    const addOnly = body.replace(".closest('.uc-repeater-add, [data-uc-faq-apply]')", ".closest('.uc-repeater-add')");
    if (addOnly === body) {
        console.error('self-test: could not find the listener selector to narrow');
        process.exit(1);
    }
    const c3 = run(addOnly, { rows: [{ id: 'faq-1' }] });
    const caught3 = c3.press('[data-uc-faq-apply]', 'set-1').length === 0;
    console.log('  ' + 'a FAQ set row is appended and never started'.padEnd(56) + (caught3 ? 'caught' : 'MISSED'));

    /* And the real source must behave. */
    const c = run(body, { rows: [{ id: 'faq-1' }, { id: 'faq-2' }, { id: 'tpl', inTemplate: true }] });
    const ok = c.started.length === 2 && c.errors.length === 0
        && c.press('[data-uc-faq-apply]', 'set-1').join() === 'set-1';
    console.log('  ' + 'the real source, unmodified'.padEnd(56) + (ok ? 'passes' : 'FAILS'));

    console.log('');
    if (!caught1 || !caught2 || !caught3 || !ok) {
        console.error('self-test FAILED');
        process.exit(1);
    }
    console.log('self-test passed: every planted hole caught, no false positive.');
    process.exit(0);
}

/* 1. Stored rows are started at LOAD. */
const load = run(body, { rows: [{ id: 'faq-1' }, { id: 'faq-2' }, { id: 'tpl', inTemplate: true }] });
expect('both stored rows are started at page load', load.started.sort(), ['faq-1', 'faq-2']);
expect('the template row is never started', load.started.indexOf('tpl'), -1);
expect('nothing is reported when nothing throws', load.errors.length, 0);

/* 2. Every started row is marked, so a later pass cannot start it twice. */
const marked = load.doc._areas
    .filter((a) => !a._inTemplate)
    .every((a) => a.getAttribute('data-uc-rich-on') === '1');
expect('every started row is marked', marked, true);

/* 3. THE THIRD PATH: a row a FAQ SET appended, with no reload. */
const set = run(body, { rows: [{ id: 'faq-1' }] });
expect('applying a FAQ set starts the row it appended', set.press('[data-uc-faq-apply]', 'set-1'), ['set-1']);
expect('and does not start an existing row a second time', set.press('[data-uc-faq-apply]', null), []);

/* 4. Add FAQ still works, which is the path that was never broken. */
const added = run(body, { rows: [{ id: 'faq-1' }] });
expect('+ Add FAQ starts the row it cloned', added.press('.uc-repeater-add', 'new-1'), ['new-1']);

/* 5. A throw is REPORTED and the row is unmarked so it can be retried. */
const threw = run(body, { rows: [{ id: 'faq-1' }], throwOn: 'faq-1' });
expect('a throw is reported through the console', threw.errors.length > 0, true);
expect('the report names the element', /faq-1/.test(threw.errors.join(' ')), true);
expect('the failed row is left unmarked', threw.doc._areas[0].getAttribute('data-uc-rich-on'), null);

/* ------------------------------------------------------------------------- */
if (fails.length) {
    console.log('RICH TEXT START: ' + fails.length + ' FAILURE(S)');
    fails.forEach((f) => console.log('  - ' + f));
    process.exit(1);
}
console.log('Rich text, at page load');
console.log('load:    both stored rows are asked for an editor when the page loads, not');
console.log('         only when Add FAQ is pressed');
console.log('template: the pattern row is never started, and started rows are marked so');
console.log('         a later pass cannot start one twice');
console.log('set:     a row a FAQ SET appended is started by the press that appended it,');
console.log('         which until 3.74.0 nothing did');
console.log('failure: a throw from wp.editor.initialize() is named in the console with');
console.log('         the element id, and the row is left retryable');
console.log('');
console.log('the plugin asks for every editor it should, at load. Whether WordPress then');
console.log('starts one is a browser fact this cannot reach.');
