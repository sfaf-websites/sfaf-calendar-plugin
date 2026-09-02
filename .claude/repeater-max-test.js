/**
 * THE ADD BUTTON GOES AT THE CAP, AND ONLY WHERE THERE IS ONE (3.67.0).
 *
 *     node .claude/repeater-max-test.js
 *     node .claude/repeater-max-test.js --self-test
 *
 * WHAT IS BEING ASSERTED. The community form takes up to five addresses on its
 * About you field, and the instruction about the button at five was "gone, not
 * disabled". A disabled control is still a thing to read and wonder about; one
 * that is not in the document is answered. So this presses the button until the
 * cap and then asks whether the button is still in its parent, which is a
 * question neither a class check nor a `disabled` check would answer.
 *
 * AND WHAT MUST NOT HAVE CHANGED. `data-repeater-max` is new, and every other
 * repeater in this codebase, the FAQ rows on both public forms and the campaign
 * rows in Settings, has none. A repeater with no cap must keep its button
 * forever, so that case is asserted here rather than assumed from reading the
 * `if`.
 *
 * THE FUNCTION IS SLICED OUT OF portal.js, NOT COPIED, by name and brace depth,
 * and the slice throws rather than returning something plausible when the name
 * is gone. A copy would keep passing after the original changed, which is the
 * failure mode of every test that restates its subject.
 *
 * WHAT THIS CANNOT PROVE. That the community form actually renders a repeater
 * carrying `data-repeater-max`, and that its server refuses a sixth address.
 * Both are in .claude/submissions-test.php, which reads the real markup and
 * runs the real validate(). Neither file is worth much alone.
 *
 * THE STUB IS DELIBERATELY SMALL: elements with a class list, a parent, a
 * children array, `getAttribute`, `querySelector`/`querySelectorAll` over a
 * single class or attribute, `addEventListener`, `appendChild` and `remove`.
 * --self-test feeds it the cases it must get right before any assertion above
 * is allowed to rest on it.
 */

'use strict';

const fs = require('fs');
const path = require('path');

const SRC = fs.readFileSync(path.join(__dirname, '..', 'public', 'js', 'portal.js'), 'utf8');

const fails = [];
function expect(label, got, want) {
    if (JSON.stringify(got) !== JSON.stringify(want)) {
        fails.push(label + ': got ' + JSON.stringify(got) + ', expected ' + JSON.stringify(want));
        return false;
    }
    return true;
}

/* =========================================================================
 * Slicing the real function out of the real file
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
 * THE STUB
 * ====================================================================== */

function El(tag, classes, attrs) {
    this.tagName = tag;
    this.classes = (classes || '').split(/\s+/).filter(Boolean);
    this.attrs = attrs || {};
    this.children = [];
    this.parent = null;
    this.listeners = {};
    this.innerHTML = '';
}
El.prototype.getAttribute = function (name) {
    return Object.prototype.hasOwnProperty.call(this.attrs, name) ? this.attrs[name] : null;
};
El.prototype.appendChild = function (node) {
    node.parent = this;
    this.children.push(node);
    return node;
};
El.prototype.remove = function () {
    if (!this.parent) { return; }
    const at = this.parent.children.indexOf(this);
    if (at >= 0) { this.parent.children.splice(at, 1); }
    this.parent = null;
};
El.prototype.addEventListener = function (type, fn) {
    (this.listeners[type] = this.listeners[type] || []).push(fn);
};
El.prototype.click = function () {
    (this.listeners.click || []).forEach(function (fn) { fn({ target: null }); });
};
/** Every descendant, depth first. */
El.prototype.all = function () {
    let out = [];
    this.children.forEach(function (c) { out = out.concat([c], c.all()); });
    return out;
};
/** One class, or one [attr], which is everything the sliced code asks for. */
function matches(node, sel) {
    if (sel.charAt(0) === '.') {
        return node.classes.indexOf(sel.slice(1)) >= 0;
    }
    if (sel.charAt(0) === '[') {
        return Object.prototype.hasOwnProperty.call(node.attrs, sel.slice(1, -1));
    }
    return node.tagName === sel;
}
El.prototype.querySelectorAll = function (sel) {
    return this.all().filter(function (n) { return matches(n, sel); });
};
El.prototype.querySelector = function (sel) {
    const found = this.querySelectorAll(sel);
    return found.length ? found[0] : null;
};

/**
 * `wrap.innerHTML = html` then `wrap.firstChild`.
 *
 * The class of the first tag is all the sliced code needs from the parsed
 * result: it appends the node and later counts rows by class. Anything more
 * would be a parser in a test, which is a thing to get wrong.
 */
function Wrap() {
    El.call(this, 'div', '', {});
}
Wrap.prototype = Object.create(El.prototype);
Object.defineProperty(Wrap.prototype, 'firstChild', {
    get: function () {
        const m = /<[a-z]+[^>]*\sclass="([^"]*)"/i.exec(this.innerHTML || '');
        return new El('div', m ? m[1] : '', {});
    }
});

/** A repeater: a rows container holding `start` rows, a template, a button. */
function repeater(max, start) {
    const rep = new El('div', 'uc-repeater', max ? { 'data-repeater-max': String(max) } : {});
    const rows = new El('div', 'uc-repeater-rows', {});
    for (let i = 0; i < start; i++) {
        rows.appendChild(new El('label', 'uc-repeater-row', {}));
    }
    const tpl = new El('template', 'uc-repeater-tpl', {});
    tpl.innerHTML = '<label class="uc-repeater-row"><input /></label>';
    const btn = new El('button', 'uc-repeater-add', {});
    rep.appendChild(rows);
    rep.appendChild(tpl);
    rep.appendChild(btn);
    return { rep: rep, rows: rows, btn: btn };
}

function run(repeaters) {
    const root = new El('div', 'root', {});
    repeaters.forEach(function (r) { root.appendChild(r.rep); });
    const documentStub = {
        querySelectorAll: function (sel) { return root.querySelectorAll(sel); },
        createElement: function () { return new Wrap(); }
    };
    /* eslint-disable no-new-func */
    const make = new Function('document', slice('initRepeaters') + '\nreturn initRepeaters;');
    make(documentStub)();
    return root;
}

function rowCount(r) { return r.rows.querySelectorAll('.uc-repeater-row').length; }
function buttonIsInTheDocument(r) { return r.rep.querySelectorAll('.uc-repeater-add').length === 1; }

/* =========================================================================
 * SELF TEST
 * ====================================================================== */

if (process.argv.indexOf('--self-test') >= 0) {
    let bad = 0;
    const say = function (ok, good, broken) {
        if (ok) { console.log('ok       ' + good); } else { console.log('BROKEN:  ' + broken); bad++; }
    };

    let threw = false;
    try { slice('aFunctionThatIsNotThere'); } catch (e) { threw = true; }
    say(threw, 'the slicer refuses a name that is not in portal.js',
        'the slicer invents a function that does not exist');

    const probe = repeater(0, 1);
    say(probe.rep.querySelectorAll('.uc-repeater-add').length === 1,
        'the reader can see a button that is present',
        'the reader cannot see a button that is present');
    probe.btn.remove();
    say(probe.rep.querySelectorAll('.uc-repeater-add').length === 0,
        'and can see that a removed button is gone',
        'a removed button still reads as present, so every assertion here is vacuous');

    const counted = repeater(0, 3);
    say(counted.rows.querySelectorAll('.uc-repeater-row').length === 3,
        'the reader counts the rows it was given',
        'the row count is wrong before anything has been clicked');

    const wrap = new Wrap();
    wrap.innerHTML = '<label class="uc-repeater-row"><input /></label>';
    say(wrap.firstChild.classes.indexOf('uc-repeater-row') >= 0,
        'a cloned template row arrives carrying its class',
        'a cloned row has no class, so appended rows would never be counted');

    say(matches(new El('div', 'uc-repeater-added', {}), '.uc-repeater-add') === false,
        'a class that is only a prefix does not match',
        'the matcher matches a prefix, so it cannot tell two controls apart');

    console.log('\n' + (bad ? bad + ' case(s) wrong; this checker cannot be trusted.'
        : 'the harness reads what it claims to read.'));
    process.exit(bad ? 1 : 0);
}

/* =========================================================================
 * 1. A CAPPED REPEATER LOSES ITS BUTTON AT THE CAP.
 * ====================================================================== */

const five = repeater(5, 1);
run([five]);

expect('it starts with one row', rowCount(five), 1);
expect('and the button is offered', buttonIsInTheDocument(five), true);

five.btn.click();
five.btn.click();
five.btn.click();
expect('four rows, and the button is still there', rowCount(five), 4);
expect('because four is not the cap', buttonIsInTheDocument(five), true);

five.btn.click();
expect('the fifth row is added', rowCount(five), 5);
expect('and the button is GONE, not disabled', buttonIsInTheDocument(five), false);

/* =========================================================================
 * 2. AN UNCAPPED REPEATER KEEPS ITS BUTTON.
 *
 * The FAQ rows on both public forms and the campaign rows in Settings have no
 * data-repeater-max, and a cap applied to them by accident would silently stop
 * somebody adding a seventh question.
 * ====================================================================== */

const open = repeater(0, 1);
run([open]);
for (let i = 0; i < 9; i++) { open.btn.click(); }
expect('ten rows on an uncapped repeater', rowCount(open), 10);
expect('and its button is still offered', buttonIsInTheDocument(open), true);

/* =========================================================================
 * 3. TWO REPEATERS ON ONE PAGE ARE COUNTED APART.
 *
 * The community form has both: the email field is capped and the FAQ rows are
 * not, on the same document, and a counter shared between them would take the
 * button off the wrong one.
 * ====================================================================== */

const capped = repeater(2, 1);
const uncapped = repeater(0, 1);
run([capped, uncapped]);

uncapped.btn.click();
uncapped.btn.click();
uncapped.btn.click();
expect('the uncapped one grew', rowCount(uncapped), 4);
expect('and the capped one did not', rowCount(capped), 1);
expect('the capped one still offers its button', buttonIsInTheDocument(capped), true);

capped.btn.click();
expect('until it reaches its own cap', rowCount(capped), 2);
expect('and then loses it', buttonIsInTheDocument(capped), false);
expect('while the uncapped one keeps its own', buttonIsInTheDocument(uncapped), true);

/* =========================================================================
 * Report.
 * ====================================================================== */

console.log('The repeater cap, run over the real initRepeaters()');
console.log('='.repeat(72));
console.log('checked by running:   initRepeaters() sliced out of public/js/portal.js,');
console.log('                      over a document, pressing the add button');
console.log('not proven here:      that the community form renders data-repeater-max,');
console.log('                      or that the server refuses a sixth address. Both are');
console.log('                      in .claude/submissions-test.php.\n');

if (!fails.length) {
    console.log('the button is offered up to the cap and gone at it.');
    process.exit(0);
}
console.log(fails.length + ' problem(s):');
fails.forEach(function (f) { console.log('  - ' + f); });
process.exit(1);
