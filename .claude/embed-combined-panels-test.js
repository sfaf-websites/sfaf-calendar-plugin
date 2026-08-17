/**
 * A COMBINED BLOCK THAT RENDERS ITS CHROME AND NO EVENTS.
 *
 *     node .claude/embed-combined-panels-test.js
 *
 * WHY THIS FILE EXISTS AND embed-modes-test.php WAS NOT ENOUGH. That file reads
 * the sources and asserts, correctly, that the PHP builds both panels, that
 * neither carries `hidden`, and that embed.js knows 'combined' is not a
 * remembered choice. Every one of those passed while the mode rendered a search
 * box, a filter bar, a count and nothing else on sfaf.org, because the thing
 * that broke was none of them: the server sent both panels visible and the
 * browser then set `hidden` on both.
 *
 * So this asserts the OUTCOME instead of the sources: run the real view
 * functions out of public/js/embed.js over a block, then count the events a
 * visitor can actually see. Chrome present and zero events visible is the exact
 * failure, and it is what this file fails on.
 *
 * THE FUNCTIONS ARE SLICED OUT OF embed.js, NOT COPIED. A copy would keep
 * passing after the original changed, which is the failure mode of every test
 * that restates its subject. The slice is by name and brace depth, and it
 * throws rather than returning something plausible if a name is gone.
 *
 * The DOM stub below is deliberately tiny: class selectors, `hidden`,
 * `className`, `classList`, attributes and event listeners. That is everything
 * these functions touch, and a stub that does no more cannot quietly answer a
 * question the real DOM would have answered differently.
 */

'use strict';

const fs = require('fs');
const path = require('path');

const SRC = fs.readFileSync(path.join(__dirname, '..', 'public', 'js', 'embed.js'), 'utf8');

const fails = [];
function check(ok, msg) {
    if (!ok) { fails.push(msg); }
}

/* =========================================================================
 * Slicing the real functions out of the real file
 * ====================================================================== */

function slice(name) {
    const at = SRC.indexOf('function ' + name + '(');
    if (at < 0) {
        throw new Error('embed.js has no function ' + name + '(); this test is asking about code that is gone');
    }
    let depth = 0;
    for (let j = SRC.indexOf('{', at); j < SRC.length; j++) {
        if (SRC[j] === '{') { depth++; }
        else if (SRC[j] === '}') {
            depth--;
            if (depth === 0) { return SRC.slice(at, j + 1); }
        }
    }
    throw new Error('unbalanced braces reading ' + name + '() out of embed.js');
}

const NEEDED = [
    'inner', 'panelOf', 'panelHiddenFor', 'viewKey', 'storedView',
    'rememberView', 'showView', 'viewFor', 'bindViewToggle'
];
const sliced = NEEDED.map(slice);

/* =========================================================================
 * The stub
 * ====================================================================== */

class El {
    constructor(classes, attrs) {
        this.className = classes || '';
        this.attrs = Object.assign({}, attrs || {});
        this.hidden = false;
        this.kids = [];
        const self = this;
        this.classList = {
            contains(c) { return self.classes().indexOf(c) > -1; },
            add(c) { if (!this.contains(c)) { self.className = (self.className + ' ' + c).trim(); } },
            remove(c) { self.className = self.classes().filter(x => x !== c).join(' '); },
            toggle(c, on) { if (on) { this.add(c); } else { this.remove(c); } }
        };
    }

    classes() {
        return this.className.split(/\s+/).filter(Boolean);
    }

    append(child) { this.kids.push(child); return child; }

    setAttribute(k, v) { this.attrs[k] = String(v); }
    getAttribute(k) { return Object.prototype.hasOwnProperty.call(this.attrs, k) ? this.attrs[k] : null; }
    addEventListener(type, fn) { (this.listeners || (this.listeners = [])).push([type, fn]); }

    /** Only class selectors, optionally comma-separated. Nothing else is used. */
    matches(selector) {
        return selector.split(',').some(part => {
            const want = part.trim().replace(/^\./, '');
            return want !== '' && this.classList.contains(want);
        });
    }

    descendants() {
        return this.kids.reduce((all, k) => all.concat([k], k.descendants()), []);
    }

    querySelector(selector) {
        return this.descendants().find(el => el.matches(selector)) || null;
    }

    querySelectorAll(selector) {
        return this.descendants().filter(el => el.matches(selector));
    }
}

/**
 * What a visitor can see. A hidden element takes its whole subtree with it,
 * which is the property the failure depended on: the cards were in the document
 * the entire time.
 */
function visible(root, selector) {
    let n = 0;
    (function walk(el) {
        for (const kid of el.kids) {
            if (kid.hidden) { continue; }
            if (kid.matches(selector)) { n++; }
            walk(kid);
        }
    })(root);
    return n;
}

/* =========================================================================
 * The block, in the shape SFAF_Shortcodes emits it
 * ====================================================================== */

function makeBlock(view) {
    /*
     * THE MODE IS AN ATTRIBUTE OF THE SNIPPET, NOT OF THE RENDERED MARKUP, and
     * getting that wrong is what a first draft of this file did. viewFor() and
     * viewKey() both read the CONTAINER, which is the div the embed generator
     * writes onto the host page; data-view on the inner block is the server
     * echoing back what it rendered. Put the attributes only on the block and
     * viewFor() answers 'list' for every mode, which is a fixture that agrees
     * with itself and with nothing on sfaf.org.
     */
    const container = new El('', {
        'data-sfaf-calendar': '',
        'data-view': view,
        'data-category': '',
        'data-organizer': '',
        'data-series': '',
        'data-venue': ''
    });
    const block = container.append(new El('uc-calendar uc-view-' + view, { 'data-view': view }));

    const filters = block.append(new El('uc-filters'));
    filters.append(new El('uc-search-wrap')).append(new El('uc-search'));
    filters.append(new El('uc-filter-buttons')).append(new El('uc-filter-btn', { 'data-category': 'all' }));
    block.append(new El('uc-view-bar')).append(new El('uc-event-count')).append(new El('uc-count-number'));

    if (view === 'sidebar') {
        block.append(new El('uc-sidebar-list'));
        return { container, block, list: null, grid: null };
    }

    const panels = block.append(new El('uc-view-panels' + (view === 'combined' ? ' uc-view-panels-combined' : '')));

    // The combined mode emits the grid first; every other mode keeps list-then-grid.
    const grid = new El('uc-view-panel uc-panel-calendar');
    const month = grid.append(new El('uc-month'));
    const table = month.append(new El('uc-month-grid'));
    const cell = table.append(new El('uc-day', { 'data-day': '2026-08-17' }));
    const dayEvents = cell.append(new El('uc-day-events'));
    dayEvents.append(new El('uc-day-event'));
    dayEvents.append(new El('uc-day-event'));
    month.append(new El('uc-month-day-panel'));

    const list = new El('uc-view-panel uc-panel-list');
    const cards = list.append(new El('uc-event-list'));
    for (let i = 0; i < 3; i++) { cards.append(new El('uc-event-card')); }

    // A mode other than combined ships one panel hidden from the server.
    if (view !== 'combined') {
        list.hidden = (view !== 'list');
        grid.hidden = (view !== 'calendar');
    }

    if (view === 'combined') { panels.append(grid); panels.append(list); }
    else { panels.append(list); panels.append(grid); }

    if (view === 'list' || view === 'calendar') {
        const bar = block.querySelector('.uc-view-bar');
        const toggle = bar.append(new El('uc-view-toggle'));
        toggle.append(new El('uc-view-btn', { 'data-view': 'list' }));
        toggle.append(new El('uc-view-btn', { 'data-view': 'calendar' }));
    }

    return { container, block, list, grid };
}

/* =========================================================================
 * Wiring the sliced functions up
 * ====================================================================== */

let store = {};
const windowStub = {
    localStorage: {
        getItem(k) { return Object.prototype.hasOwnProperty.call(store, k) ? store[k] : null; },
        setItem(k, v) { store[k] = String(v); }
    }
};

let syncCalls = 0;
const api = new Function('window', 'syncDayPanel', [
    '"use strict";',
    sliced.join('\n\n'),
    'return { showView: showView, viewFor: viewFor, bindViewToggle: bindViewToggle };'
].join('\n'))(windowStub, function () { syncCalls++; });

/* =========================================================================
 * THE FAILURE THAT SHIPPED
 * ====================================================================== */

store = {};
let b = makeBlock('combined');
api.bindViewToggle(b.container, b.block);

const cardsSeen = visible(b.container, '.uc-event-card');
const daysSeen = visible(b.container, '.uc-day-event');
const chromeSeen = visible(b.container, '.uc-search') + visible(b.container, '.uc-event-count');

check(chromeSeen === 2, 'the fixture is wrong: the chrome is not visible, so a chrome-but-no-events assertion proves nothing');
check(
    cardsSeen + daysSeen > 0,
    'A COMBINED BLOCK RENDERED ITS CHROME AND NO EVENTS. Both panels are in the document and both are hidden; ' +
    'that is what the live page showed, at every width, and it is a JavaScript fault rather than a CSS one'
);
check(cardsSeen === 3, `the combined mode shows ${cardsSeen} of 3 list cards`);
check(daysSeen === 2, `the combined mode shows ${daysSeen} of 2 grid entries`);
check(b.list.hidden === false, 'the list panel is hidden in the combined mode');
check(b.grid.hidden === false, 'the grid panel is hidden in the combined mode');

/*
 * ONE uc-view-* CLASS, AND IT IS THE RIGHT ONE. showView() rewrites the block's
 * class list, and a mode missing from that rewrite either stacks up duplicates
 * or leaves the class of a view that is no longer showing, which is what the
 * 1200px cap and every container query key off.
 */
const viewClasses = b.block.classes().filter(c => /^uc-view-/.test(c));
check(
    viewClasses.length === 1 && viewClasses[0] === 'uc-view-combined',
    'the block ended up with the uc-view classes [' + viewClasses.join(', ') + '] rather than exactly uc-view-combined'
);

/*
 * A STALE CHOICE FROM ANOTHER BLOCK MUST NOT REACH THIS ONE. The combined mode
 * has no toggle, so nothing in it could have been chosen; a 'calendar' left in
 * localStorage by a different block on the same site would otherwise take half
 * the layout away.
 */
store = {};
b = makeBlock('combined');
store['sfafView:||||combined'] = 'calendar';
api.bindViewToggle(b.container, b.block);
check(
    visible(b.container, '.uc-event-card') === 3 && visible(b.container, '.uc-day-event') === 2,
    'a view left in localStorage collapsed the combined mode to one panel'
);

/* =========================================================================
 * THE OTHER THREE MODES ARE UNCHANGED
 * ====================================================================== */

store = {};
b = makeBlock('list');
api.bindViewToggle(b.container, b.block);
check(visible(b.container, '.uc-event-card') === 3, 'list mode does not show its cards');
check(visible(b.container, '.uc-day-event') === 0, 'list mode shows the month grid as well');
check(b.block.classes().filter(c => /^uc-view-/.test(c)).join(' ') === 'uc-view-list', 'list mode lost its uc-view-list class');

store = {};
b = makeBlock('calendar');
api.bindViewToggle(b.container, b.block);
check(visible(b.container, '.uc-day-event') === 2, 'calendar mode does not show its grid entries');
check(visible(b.container, '.uc-event-card') === 0, 'calendar mode shows the list as well');

// The toggle still switches, and still remembers.
store = {};
b = makeBlock('list');
api.bindViewToggle(b.container, b.block);
api.showView(b.container, 'calendar', true);
check(visible(b.container, '.uc-day-event') === 2 && visible(b.container, '.uc-event-card') === 0, 'the toggle no longer switches to the grid');
check(store['sfafView:||||list'] === 'calendar', 'the toggle no longer remembers the chosen view');
check(
    b.block.classes().filter(c => /^uc-view-/.test(c)).join(' ') === 'uc-view-calendar',
    'switching view left the old uc-view class behind'
);

// A view the code has never heard of must not empty the block either. This is
// the shape of the fault: 'combined' was exactly such a view to showView().
store = {};
b = makeBlock('combined');
api.showView(b.container, 'nonsense', false);
check(
    visible(b.container, '.uc-event-card') + visible(b.container, '.uc-day-event') > 0,
    'an unrecognised view hides every panel, which is the fault this file was written for wearing a different name'
);

// Sidebar has no panels at all and must not throw on the way past.
store = {};
b = makeBlock('sidebar');
let threw = '';
try { api.bindViewToggle(b.container, b.block); } catch (e) { threw = String(e && e.message); }
check(threw === '', 'the sidebar mode throws in bindViewToggle: ' + threw);

/* =========================================================================
 * Report
 * ====================================================================== */

console.log('Combined mode, as a visitor sees it');
console.log('sliced:   ' + NEEDED.join(', ') + ' out of public/js/embed.js');
console.log('combined: 3 list cards and 2 grid entries visible, chrome visible, one uc-view class,');
console.log('          and a stale remembered view cannot collapse it');
console.log('list:     cards only. calendar: grid only. the toggle still switches and remembers.');
console.log('edges:    an unrecognised view cannot empty the block; sidebar has no panels and does not throw.');
console.log('');

if (fails.length) {
    console.log('FAIL: ' + fails.length);
    for (const f of [...new Set(fails)]) { console.log('  . ' + f); }
    process.exit(1);
}
console.log('the combined mode renders events, not just its chrome.');
process.exit(0);
