#!/usr/bin/env node
/*
 * ONE HEADLESS CHROME, WITH A TIME LIMIT, AND NOTHING LEFT RUNNING AFTER IT.
 *
 *     node .claude/chrome-run.js <chrome arguments...>
 *
 * Every browser check starts Chrome as `$chrome = getenv( 'SFAF_CHROME' ) ?:
 * '...chrome.exe'`, and run-all.sh sets SFAF_CHROME to chrome-run.cmd, which
 * runs this. By hand, without the variable, a check starts Chrome directly as
 * it always has.
 *
 * WHY (measured 2026-10-21). A single `chrome --headless --dump-dom` call
 * sometimes never exits: release-31103-live hung in 2 of 5 runs on its own,
 * with seven Chrome processes holding a flat 170 MB, nowhere near the memory
 * ceiling. Nothing timed out one call, so the check sat until browser-guard's
 * 900 s limit and failed as a timeout, and the suite took a quarter of an hour
 * longer each time. A page takes 2 to 25 s here, so this gives a call
 * CHROME_RUN_TIMEOUT seconds (60), ends the whole process tree on the limit,
 * and starts it again, up to CHROME_RUN_TRIES times (3) in all.
 *
 * NOTHING OUTLIVES A CALL. Each call gets its own --user-data-dir, and every
 * chrome.exe whose command line names it is ended afterwards, whether the call
 * finished or not: Chrome's crashpad handler and utilities can outlive the
 * browser process, which is how nine checks left processes behind in 3.110.2.
 *
 * Output is Chrome's stdout (the --dump-dom page), unchanged. A call that hit
 * the limit every time prints nothing and exits 124, so the check reports
 * "Chrome returned no probe block" rather than waiting. Each call ended at the
 * limit is noted on stderr, which the checks send to NUL, and as a line in
 * CHROME_RUN_LOG when that is set, which run-all.sh counts.
 */
'use strict';
const { spawn, execFileSync } = require('child_process');
const fs = require('fs');
const os = require('os');
const path = require('path');

const CHROME = process.env.SFAF_CHROME_EXE || 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const LIMIT = Math.max(5, parseInt(process.env.CHROME_RUN_TIMEOUT || '60', 10)) * 1000;
const TRIES = Math.max(1, parseInt(process.env.CHROME_RUN_TRIES || '3', 10));

function sweep(profile) {
    // Every chrome.exe started for this profile, by its command line.
    const ps = "Get-CimInstance Win32_Process -Filter \"Name='chrome.exe'\" | " +
        "Where-Object { $_.CommandLine -like '*" + profile.replace(/'/g, "''") + "*' } | " +
        "ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }";
    try { execFileSync('powershell.exe', ['-NoProfile', '-Command', ps], { stdio: 'ignore', timeout: 30000 }); } catch (e) { /* nothing to end */ }
}

function once(args) {
    return new Promise(function (resolve) {
        const profile = fs.mkdtempSync(path.join(os.tmpdir(), 'uc-chrome-'));
        const own = args.some(function (a) { return /^--user-data-dir=/.test(a); });
        const child = spawn(CHROME, own ? args : args.concat(['--user-data-dir=' + profile]), { windowsHide: true });
        const out = [];
        let timedOut = false;
        child.stdout.on('data', function (b) { out.push(b); });
        child.stderr.on('data', function () {});
        const timer = setTimeout(function () {
            timedOut = true;
            try { execFileSync('taskkill', ['/PID', String(child.pid), '/T', '/F'], { stdio: 'ignore' }); } catch (e) { /* already gone */ }
        }, LIMIT);
        child.on('close', function (code) {
            clearTimeout(timer);
            sweep(profile);
            try { fs.rmSync(profile, { recursive: true, force: true }); } catch (e) { /* Chrome may hold a file a moment longer */ }
            resolve({ timedOut: timedOut, code: code, out: Buffer.concat(out) });
        });
    });
}

(async function () {
    const args = process.argv.slice(2);
    for (let i = 1; i <= TRIES; i++) {
        const r = await once(args);
        if (!r.timedOut) {
            process.stdout.write(r.out);
            process.exitCode = r.code || 0;
            return;
        }
        process.stderr.write('chrome-run: no exit after ' + (LIMIT / 1000) + 's, ended (try ' + i + ' of ' + TRIES + ')\n');
        if (process.env.CHROME_RUN_LOG) {
            const page = args.filter(function (a) { return /^file:/.test(a); }).join(' ');
            try { fs.appendFileSync(process.env.CHROME_RUN_LOG, new Date().toISOString() + ' try ' + i + ' ' + page + '\n'); } catch (e) { /* the log is a convenience */ }
        }
    }
    process.exitCode = 124;
})();
