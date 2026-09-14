/**
 * WHERE DOES EACH FUNCTION IN portal.js ACTUALLY SIT?
 *
 * WHY. A browser reported `initRequestPrefill is not defined` while
 * `node --check` passed and `.claude/js-scope-test.js` passed. A file whose
 * braces BALANCE can still nest a function inside another one: the imbalance is
 * absorbed somewhere later and the parse succeeds. That is the JavaScript twin
 * of the 3.2.0 PHP lesson in CLAUDE.md, balance is not validity, and it is why
 * this counts depth per declaration rather than totalling the file.
 *
 *     node .claude/js-nesting.js [file]
 *     node .claude/js-nesting.js --self-test
 *
 * A REAL SCANNER, NOT A REGEX. The first attempt stripped comments and strings
 * with regexes and reported depths of 15, which is nonsense and would have sent
 * somebody hunting fourteen missing braces. Line comments, block comments,
 * single and double quoted strings, template literals with ${} nesting, and
 * REGEX LITERALS all contain braces that are not code. Regex literals are the
 * hard one: `/\d{4}/` has braces in it and `a / b` does not, and telling them
 * apart needs the previous significant token.
 */

const fs = require('fs');
const path = require('path');

/* The token classes before which a `/` starts a REGEX rather than divides.
 * After an identifier, a number, or a closing bracket, `/` is division. */
function regexAllowedAfter(prev) {
    if (prev === '') { return true; }
    if (/[A-Za-z0-9_$)\]]/.test(prev)) { return false; }
    return true;
}

function scan(src) {
    const out = [];         // { line, name, depth }
    let depth = 0;
    let line = 1;
    let prevSig = '';       // last significant character
    let i = 0;
    const n = src.length;

    while (i < n) {
        const c = src[i];

        if (c === '\n') { line++; i++; continue; }

        /* Comments. */
        if (c === '/' && src[i + 1] === '/') {
            while (i < n && src[i] !== '\n') { i++; }
            continue;
        }
        if (c === '/' && src[i + 1] === '*') {
            i += 2;
            while (i < n && !(src[i] === '*' && src[i + 1] === '/')) {
                if (src[i] === '\n') { line++; }
                i++;
            }
            i += 2;
            continue;
        }

        /* Strings. */
        if (c === '"' || c === "'") {
            const q = c; i++;
            while (i < n && src[i] !== q) {
                if (src[i] === '\\') { i++; }
                if (src[i] === '\n') { line++; }
                i++;
            }
            i++; prevSig = q; continue;
        }

        /* Template literals, including ${ } which may nest braces. */
        if (c === '`') {
            i++;
            let tdepth = 0;
            while (i < n) {
                if (src[i] === '\\') { i += 2; continue; }
                if (src[i] === '\n') { line++; i++; continue; }
                if (src[i] === '$' && src[i + 1] === '{') { tdepth++; i += 2; continue; }
                if (src[i] === '}' && tdepth > 0) { tdepth--; i++; continue; }
                if (src[i] === '`' && tdepth === 0) { i++; break; }
                i++;
            }
            prevSig = '`'; continue;
        }

        /* Regex literals. */
        if (c === '/' && regexAllowedAfter(prevSig)) {
            let j = i + 1;
            let inClass = false;
            let ok = false;
            while (j < n) {
                const d = src[j];
                if (d === '\\') { j += 2; continue; }
                if (d === '\n') { break; }            // not a regex after all
                if (d === '[') { inClass = true; j++; continue; }
                if (d === ']') { inClass = false; j++; continue; }
                if (d === '/' && !inClass) { ok = true; j++; break; }
                j++;
            }
            if (ok) {
                while (j < n && /[gimsuyd]/.test(src[j])) { j++; }
                i = j; prevSig = '/'; continue;
            }
            /* fall through: it was division */
        }

        if (c === '{') { depth++; i++; prevSig = c; continue; }
        if (c === '}') { depth--; i++; prevSig = c; continue; }

        /* A function declaration, at the point it is declared. */
        if (c === 'f' && src.startsWith('function', i) && !/[A-Za-z0-9_$.]/.test(src[i - 1] || '')) {
            const m = /^function\s+([A-Za-z0-9_$]+)\s*\(/.exec(src.slice(i, i + 200));
            if (m) { out.push({ line, name: m[1], depth }); }
        }

        if (!/\s/.test(c)) { prevSig = c; }
        i++;
    }
    return { decls: out, final: depth };
}

/* ---------------------------------------------------------------------------
 * Self-test, because a scanner that cannot fail is not evidence.
 * ------------------------------------------------------------------------ */
if (process.argv.includes('--self-test')) {
    const cases = [
        ['siblings', '(function(){ function a(){} function b(){} })();', { a: 1, b: 1 }],
        ['nested',   '(function(){ function a(){ function b(){} } })();', { a: 1, b: 2 }],
        ['regex',    '(function(){ var r = /\\d{4}/; function a(){} })();', { a: 1 }],
        ['string',   '(function(){ var s = "{{{"; function a(){} })();', { a: 1 }],
        ['comment',  '(function(){ /* { { { */ function a(){} })();', { a: 1 }],
        ['template', '(function(){ var t = `x${ {a:1} }y`; function a(){} })();', { a: 1 }],
        ['divide',   '(function(){ var x = 4 / 2; function a(){} })();', { a: 1 }],
    ];
    let bad = 0;
    for (const [name, src, want] of cases) {
        const got = {};
        scan(src).decls.forEach(d => { got[d.name] = d.depth; });
        for (const k of Object.keys(want)) {
            if (got[k] !== want[k]) {
                console.log(`  FAIL ${name}: ${k} at depth ${got[k]}, expected ${want[k]}`);
                bad++;
            }
        }
    }
    if (bad) { console.log(`\nself-test FAILED: ${bad}`); process.exit(1); }
    console.log('self-test: the scanner sees through comments, strings, templates and regexes.');
    process.exit(0);
}

const file = process.argv[2] || 'public/js/portal.js';
const full = path.isAbsolute(file) ? file : path.join(path.dirname(__dirname), file);
const { decls, final } = scan(fs.readFileSync(full, 'utf8'));

console.log(`${file}: ${decls.length} function declaration(s), final brace depth ${final}`);
const byDepth = {};
decls.forEach(d => { byDepth[d.depth] = (byDepth[d.depth] || 0) + 1; });
console.log('  declarations by depth: ' + Object.keys(byDepth).sort().map(k => `${k}:${byDepth[k]}`).join('  '));

/* In this codebase every initialiser lives directly inside a top-level IIFE,
 * which is depth 1. Anything deeper is either a deliberate inner helper or a
 * function that has been swallowed by a missing brace, and the two are told
 * apart by whether anything OUTSIDE calls it. */
const deep = decls.filter(d => d.depth > 1);
if (process.argv.includes('--verbose') && deep.length) {
    console.log('\n  deeper than depth 1:');
    deep.forEach(d => console.log(`    line ${String(d.line).padStart(5)}  depth ${d.depth}  ${d.name}`));
}

/* ---------------------------------------------------------------------------
 * THE GATE: EVERY INITIALISER THE STARTUP LIST NAMES MUST BE DECLARED AT
 * DEPTH 1.
 *
 * THIS IS THE HOLE .claude/js-scope-test.js NAMES IN ITS OWN HEADER, and the
 * fault walked straight through it three releases after it was written:
 *
 *     "A function declared inside a NESTED function is treated as belonging to
 *      its whole top-level scope. That is an over-approximation, so it can MISS
 *      a genuine fault."
 *
 * 3.77.0 closed initFaqSetPeek() AFTER initRequestPrefill() instead of before
 * it, so the whole of initRequestPrefill lived inside initFaqSetPeek. The
 * braces still balanced, so `node --check` passed. The name was still declared
 * somewhere in the same top-level scope as far as the scope test could tell, so
 * that passed too.
 *
 * WHAT IT ACTUALLY DID. `run('requestPrefill', initRequestPrefill)` evaluates
 * the identifier BEFORE calling run(), so the ReferenceError is thrown in the
 * caller rather than inside run()'s try/catch, and it killed the rest of the
 * DOMContentLoaded handler: requestPrefill, calendarTick and tickPickers. That
 * is every tick picker on every screen, from 3.72.0's schedule publish to
 * 3.79.0's bulk publish, silently doing nothing for four releases.
 *
 * The check is exact rather than heuristic: read the names out of the run()
 * calls, and require each to be declared at depth 1.
 * ------------------------------------------------------------------------ */
const src = fs.readFileSync(full, 'utf8');
const named = [];
const rx = /\brun\(\s*['"][^'"]+['"]\s*,\s*([A-Za-z0-9_$]+)\s*\)/g;
let m;
while ((m = rx.exec(src)) !== null) { named.push(m[1]); }

if (!named.length) {
    console.log('\nno run() startup list found, so this asserted nothing about it.');
    process.exit(0);
}

const at = {};
decls.forEach(d => { if (!(d.name in at) || d.depth < at[d.name]) { at[d.name] = d.depth; } });

const bad = [];
named.forEach(name => {
    if (!(name in at)) {
        bad.push(`${name} is named in the startup list and declared nowhere in this file.`);
    } else if (at[name] > 1) {
        bad.push(`${name} is named in the startup list but declared at depth ${at[name]}, `
            + `inside another function. Evaluating it throws ReferenceError, which kills every `
            + `run() after it.`);
    }
});

console.log(`  startup list: ${named.length} initialiser(s), all declared at depth 1`
    .replace('all declared at depth 1', bad.length ? `${bad.length} WRONG` : 'all declared at depth 1'));

if (bad.length) {
    console.log('\nFAIL:');
    bad.forEach(b => console.log('  . ' + b));
    console.log('\nThe ones that would stop running are this one and everything below it');
    console.log('in the list, because the throw is in the caller, not inside run().');
    process.exit(1);
}
