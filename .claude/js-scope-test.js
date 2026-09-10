/**
 * IS EVERY HELPER CALLED FROM A SCOPE THAT CAN SEE IT?
 *
 *     node .claude/js-scope-test.js --self-test
 *     node .claude/js-scope-test.js
 *
 * WHY THIS EXISTS. 3.72.0 declared ucDismissOnBackdrop() inside portal.js's
 * first top-level IIFE and called it from the third and the fourth, which are
 * its SIBLINGS and cannot see into it. Both calls threw ReferenceError. Because
 * each throws AFTER preventDefault() and AFTER the panel has been moved into a
 * not-yet-shown <dialog>, the visible result was a control that did nothing at
 * all: "Get a form link" on the dashboard, and Approve and Reject on the
 * pending queue, which is the main action of the screen every submission and
 * all 287 imported drafts pass through.
 *
 * NEITHER BUILD GATE COULD SEE IT AND NEITHER EVER WILL. `node --check` proves
 * a file PARSES; a ReferenceError is a runtime fact. The callable audit is
 * PHP-side. This is the JavaScript twin of the dead `$force` parameter 3.73.0
 * closed, and PROJECT.md 7 carries both.
 *
 * WHAT IT CHECKS, AND IT IS DELIBERATELY NARROW. Only calls to names THIS FILE
 * ITSELF DECLARES. A browser global is never declared here, so it is never
 * flagged, and there is no allow-list of globals to keep in step with the
 * platform. The question is exactly the one that broke: "this file declares
 * `foo`; is every `foo()` in it able to reach that declaration?"
 *
 * WHAT IT CANNOT SEE, said plainly rather than left to be assumed:
 *
 *   . A function declared inside a NESTED function is treated as belonging to
 *     its whole top-level scope. That is an over-approximation, so it can MISS
 *     a genuine fault; it cannot invent one. False negatives only.
 *   . Anything reached through a property, `window.foo()` or `obj.foo()`, is
 *     ignored. Those are not scope questions.
 *   . It reads scopes, not execution. A helper declared correctly and called
 *     before its file has run is a different fault and this says nothing about
 *     it.
 *
 * A self-test first, because a checker that cannot fail is not evidence. It
 * puts the 3.72.0 arrangement back and requires it caught.
 */

const fs = require('fs');
const path = require('path');

const ROOT = path.dirname(__dirname);
const FILES = ['public/js/portal.js', 'public/js/calendar.js', 'public/js/embed.js'];

/* ---------------------------------------------------------------------------
 * Comments and string literals are removed first, so a helper's NAME appearing
 * in a docblock, and a call written inside a template literal, cannot be read
 * as code. Replaced with spaces rather than deleted so every line number and
 * column survives and a report can point at the real place.
 * ------------------------------------------------------------------------ */
function blank(src) {
    let out = '';
    let i = 0;
    const n = src.length;
    const keep = (ch) => (ch === '\n' ? '\n' : ' ');

    while (i < n) {
        const c = src[i];
        const d = src[i + 1];

        if (c === '/' && d === '*') {
            const end = src.indexOf('*/', i + 2);
            const stop = end === -1 ? n : end + 2;
            for (let k = i; k < stop; k++) { out += keep(src[k]); }
            i = stop;
            continue;
        }
        if (c === '/' && d === '/') {
            let k = i;
            while (k < n && src[k] !== '\n') { out += ' '; k++; }
            i = k;
            continue;
        }
        if (c === '"' || c === "'" || c === '`') {
            const quote = c;
            out += ' ';
            let k = i + 1;
            while (k < n) {
                if (src[k] === '\\') { out += '  '; k += 2; continue; }
                if (src[k] === quote) { out += ' '; k++; break; }
                out += keep(src[k]);
                k++;
            }
            i = k;
            continue;
        }
        out += c;
        i++;
    }
    return out;
}

/* ---------------------------------------------------------------------------
 * The top-level scopes. This file's own shape: `(function () {` at column 0
 * opens one and `})();` at column 0 closes it. Everything outside them is file
 * scope, which every IIFE can see.
 * ------------------------------------------------------------------------ */
function scopesOf(lines) {
    const scopes = [];
    let open = null;
    lines.forEach((line, idx) => {
        if (/^\(function\s*\(/.test(line)) { open = idx + 1; return; }
        if (/^\}\)\(\);/.test(line) && open !== null) {
            scopes.push({ from: open, to: idx + 1 });
            open = null;
        }
    });
    return scopes;
}

function scopeAt(scopes, lineNo) {
    for (let i = 0; i < scopes.length; i++) {
        if (lineNo >= scopes[i].from && lineNo <= scopes[i].to) { return i; }
    }
    return -1; // file scope
}

/* Words that are followed by "(" and are not calls. */
const NOT_CALLS = new Set([
    'if', 'for', 'while', 'switch', 'catch', 'return', 'typeof', 'function',
    'new', 'delete', 'void', 'in', 'of', 'do', 'else', 'case', 'throw', 'await',
    'yield', 'instanceof',
]);

function analyse(src) {
    const clean = blank(src);
    const lines = clean.split('\n');
    const scopes = scopesOf(src.split('\n'));

    /* Declarations: `function NAME(`, and `var|let|const NAME =` where the
       value is a function expression. Both are things this file provides. */
    const declared = new Map(); // name -> Set of scope indexes
    const declRe = /(?:^|[^.\w$])function\s+([A-Za-z_$][\w$]*)\s*\(/g;
    const varFnRe = /(?:^|[^.\w$])(?:var|let|const)\s+([A-Za-z_$][\w$]*)\s*=\s*function\s*\(/g;

    lines.forEach((line, idx) => {
        const at = scopeAt(scopes, idx + 1);
        let m;
        declRe.lastIndex = 0;
        while ((m = declRe.exec(line)) !== null) {
            if (!declared.has(m[1])) { declared.set(m[1], new Set()); }
            declared.get(m[1]).add(at);
        }
        varFnRe.lastIndex = 0;
        while ((m = varFnRe.exec(line)) !== null) {
            if (!declared.has(m[1])) { declared.set(m[1], new Set()); }
            declared.get(m[1]).add(at);
        }
    });

    /* Calls, excluding property access and the keywords above. */
    const problems = [];
    const callRe = /(^|[^.\w$])([A-Za-z_$][\w$]*)\s*\(/g;

    lines.forEach((line, idx) => {
        const lineNo = idx + 1;
        const at = scopeAt(scopes, lineNo);
        let m;
        callRe.lastIndex = 0;
        while ((m = callRe.exec(line)) !== null) {
            const name = m[2];
            if (NOT_CALLS.has(name)) { continue; }
            if (!declared.has(name)) { continue; }   // not ours: a global
            const where = declared.get(name);
            // Visible if declared in this very scope, or at file scope (-1).
            if (where.has(at) || where.has(-1)) { continue; }
            // The declaration line itself is not a call.
            if (/(?:^|[^.\w$])function\s+$/.test(line.slice(0, m.index + m[1].length))) { continue; }
            problems.push({
                name,
                line: lineNo,
                calledIn: at,
                declaredIn: Array.from(where),
            });
        }
    });

    return { scopes, declared, problems };
}

/* ------------------------------------------------------------------------- */
function run(label, src) {
    const { scopes, problems } = analyse(src);
    return { label, scopes: scopes.length, problems };
}

const selfTest = process.argv.slice(2).includes('--self-test');

if (selfTest) {
    const src = fs.readFileSync(path.join(ROOT, 'public/js/portal.js'), 'utf8');

    /* PUT 3.72.0 BACK. Move the declaration off file scope and into the first
       IIFE, which is exactly the arrangement that shipped. */
    const decl = [
        'function ucDismissOnBackdrop(dialog) {',
        "    dialog.addEventListener('click', function (e) {",
        '        if (e.target === dialog) { dialog.close(); }',
        '    });',
        '}',
    ].join('\n');

    if (!src.includes(decl)) {
        console.error('self-test: the declaration is not at file scope, so the plant cannot be made');
        process.exit(1);
    }

    const planted = src
        .replace(decl, '')
        .replace("(function () {\n    'use strict';", "(function () {\n    'use strict';\n" + decl.split('\n').map((l) => '    ' + l).join('\n'));

    const before = run('the real source', src);
    const after = run('with the declaration back inside IIFE 1', planted);

    console.log('  %s: %d problem(s)', before.label, before.problems.length);
    console.log('  %s: %d problem(s)', after.label, after.problems.length);

    const ok = before.problems.length === 0 && after.problems.length >= 2;
    console.log('');
    if (!ok) {
        console.error('self-test FAILED: the plant must be caught and the real source must pass.');
        after.problems.forEach((p) => console.error('    ' + p.name + ' at line ' + p.line));
        process.exit(1);
    }
    console.log('self-test passed: the 3.72.0 arrangement is caught, and the real source is clean.');
    process.exit(0);
}

let bad = 0;
const report = [];
FILES.forEach((rel) => {
    const full = path.join(ROOT, rel);
    if (!fs.existsSync(full)) { return; }
    const r = run(rel, fs.readFileSync(full, 'utf8'));
    report.push('  ' + rel.padEnd(24) + r.scopes + ' top-level scope(s)');
    r.problems.forEach((p) => {
        bad++;
        report.push(
            '    LINE ' + p.line + ': ' + p.name + '() is called in scope ' + p.calledIn
            + ' and declared only in scope ' + p.declaredIn.join(', ')
        );
    });
});

console.log('JavaScript scope');
report.forEach((l) => console.log(l));
console.log('');
if (bad) {
    console.log(bad + ' call(s) cannot reach the declaration they name.');
    process.exit(1);
}
console.log('every helper this file declares is called from a scope that can see it.');
