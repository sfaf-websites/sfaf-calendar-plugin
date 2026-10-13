/**
 * AN EMBED TAKES ITS FILTER FROM THE HOST PAGE'S ADDRESS (3.110.4).
 *
 *     node .claude/embed-page-filter-test.js
 *
 * "See all events" and the category chips link to the calendar home with
 * uc_cat, uc_org or uc_group. When that page is an embed on another site, the
 * filter is only applied if embed.js reads the host page's address; until
 * 3.110.4 it never did. pageParam() and init() are sliced out of the real file
 * and run against a stub container.
 *
 *   PLANT E.1  init() ignores uc_org in the address
 *   PLANT E.2  init() clears an organizer the address did not name
 */
'use strict';

const fs = require('fs');
const path = require('path');

const SRC = fs.readFileSync(path.join(__dirname, '..', 'public', 'js', 'embed.js'), 'utf8');

const fails = [];
function check(ok, msg) {
    if (!ok) { fails.push(msg); }
}

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

function container(attrs) {
    const a = Object.assign({}, attrs);
    return {
        getAttribute: (k) => (k in a ? a[k] : null),
        setAttribute: (k, v) => { a[k] = String(v); },
        attrs: a
    };
}

function run(search, attrs) {
    const window = { location: { search: search } };
    const noop = function () {};
    const make = new Function('window', 'bindAddToCalendar', 'bindChips', 'bindGroups', 'bindOrganizer',
        'bindWho', 'ensureStylesheet', 'setLoading', 'loadBlock',
        slice('pageParam') + '\n' + slice('init') + '\nreturn init;');
    const init = make(window, noop, noop, noop, noop, noop, noop, noop, noop);
    const c = container(attrs || {});
    init(c);
    return c.attrs;
}

let a = run('?uc_org=strut');
check(a['data-active-organizer'] === 'strut', 'PLANT E.1: ?uc_org=strut gave organizer ' + JSON.stringify(a['data-active-organizer']));
check(a['data-active-category'] === 'all', 'a page with no uc_cat is not on category "all": ' + a['data-active-category']);

a = run('?uc_cat=support-groups&uc_group=coffee%2Cart');
check(a['data-active-category'] === 'support-groups', 'uc_cat was not applied: ' + a['data-active-category']);
check(a['data-active-groups'] === 'coffee,art', 'uc_group was not decoded: ' + a['data-active-groups']);

a = run('', { 'data-active-organizer': 'alpha' });
check(a['data-active-organizer'] === 'alpha', 'PLANT E.2: an address with no uc_org cleared the organizer: ' + JSON.stringify(a['data-active-organizer']));
check(a['data-active-groups'] === '', 'no uc_group is not an empty group list: ' + JSON.stringify(a['data-active-groups']));

a = run('?uc_org=%E0%A4%A');
check(a['data-active-organizer'] === undefined, 'a malformed uc_org was applied rather than ignored');

a = run('?not_uc_org=x');
check(a['data-active-organizer'] === undefined, 'a parameter ending in uc_org was read as uc_org');

if (fails.length) {
    console.log('FAIL\n  ' + fails.join('\n  '));
    process.exit(1);
}
console.log('OK: an embed takes uc_cat, uc_org and uc_group from the host page\'s address, and leaves what it does not name alone.');
