/**
 * RASTERISE THE PLUGIN'S OWN CALENDAR ICON AS A PNG, FOR EMAIL.
 *
 * WHY THIS EXISTS. Every icon this plugin draws is an inline SVG built by
 * sfaf_icon(), and an inline SVG does not render in Gmail, Outlook or Yahoo. So
 * the two "Add to calendar" buttons need a raster, and the raster has to be the
 * SAME MARK as the one on the website: the alternative is a second calendar
 * glyph that drifts from the first, which is the two-renderers fault this
 * project has already paid for twice.
 *
 * IT IS NOT A PLATFORM LOGO, AND THAT IS THE POINT. The buttons say "Google"
 * and "Apple or Outlook", so the obvious icon is each vendor's mark. Those are
 * registered trademarks with published brand terms, SFAF is a nonprofit with a
 * brand guide of its own, and nothing here has been cleared to reproduce them.
 * One generic calendar glyph on both buttons says the same thing and asks
 * nobody's permission.
 *
 * The path data below is copied from sfaf_icon_paths()['calendar'] and the
 * geometry is the icon spec in sfaf-template-functions.php: a 24 unit grid, 2
 * unit stroke, round caps and joins, no fill. Drawn at 32px and displayed at
 * 16px, so it is sharp on a retina screen, which is the same trick the email
 * banner uses.
 *
 *     node .claude/build-email-icons.js
 *
 * It writes public/images/. Re-run it if the icon ever changes; nothing calls it
 * at runtime.
 */
'use strict';

const fs = require('fs');
const path = require('path');
const zlib = require('zlib');

const SIZE = 32;          // pixels written
const GRID = 24;          // the icon spec's coordinate space
const STROKE = 2;         // in grid units, per the spec
const SS = 4;             // supersamples per axis, for the antialiasing

const S = SIZE / GRID;

/* ---------------------------------------------------------------------------
 * The mark, in grid units. This is sfaf_icon_paths()['calendar'] read as
 * geometry rather than as an SVG string:
 *
 *   <rect x="3" y="5.5" width="18" height="15.5" rx="2.5"/>
 *   <path d="M3 10.5h18M8 3v5M16 3v5"/>
 * ------------------------------------------------------------------------ */
const BOX = { x: 3, y: 5.5, w: 18, h: 15.5, r: 2.5 };
const LINES = [
    [3, 10.5, 21, 10.5],   // the head rule under the month name
    [8, 3, 8, 8],          // the two hanging tabs
    [16, 3, 16, 8],
];

/** Signed distance to a rounded rectangle's outline, in grid units. */
function sdRoundBox(px, py) {
    const cx = BOX.x + BOX.w / 2;
    const cy = BOX.y + BOX.h / 2;
    const bx = BOX.w / 2 - BOX.r;
    const by = BOX.h / 2 - BOX.r;
    const qx = Math.abs(px - cx) - bx;
    const qy = Math.abs(py - cy) - by;
    const outside = Math.hypot(Math.max(qx, 0), Math.max(qy, 0));
    const inside = Math.min(Math.max(qx, qy), 0);
    return outside + inside - BOX.r;
}

/** Distance to a line segment. Round caps come free from this. */
function sdSegment(px, py, ax, ay, bx, by) {
    const pax = px - ax, pay = py - ay;
    const bax = bx - ax, bay = by - ay;
    const len2 = bax * bax + bay * bay;
    let h = len2 ? (pax * bax + pay * bay) / len2 : 0;
    h = Math.max(0, Math.min(1, h));
    return Math.hypot(pax - bax * h, pay - bay * h);
}

/** Distance from a point to the nearest bit of ink, in grid units. */
function distance(px, py) {
    let d = Math.abs(sdRoundBox(px, py));
    for (const [ax, ay, bx, by] of LINES) {
        d = Math.min(d, sdSegment(px, py, ax, ay, bx, by));
    }
    return d;
}

/**
 * Coverage of one output pixel, 0..1.
 *
 * Supersampled rather than smoothstepped: the stroke is 2 grid units, which is
 * 2.67 device pixels at this size, so the two tabs and the head rule land on
 * fractional pixel boundaries and a distance-threshold alone renders them at
 * visibly different weights. Sixteen samples per pixel costs nothing here and
 * makes the three strokes the same colour as each other.
 */
function coverage(x, y) {
    let hits = 0;
    for (let sy = 0; sy < SS; sy++) {
        for (let sx = 0; sx < SS; sx++) {
            const px = (x + (sx + 0.5) / SS) / S;
            const py = (y + (sy + 0.5) / SS) / S;
            if (distance(px, py) <= STROKE / 2) hits++;
        }
    }
    return hits / (SS * SS);
}

/* ---------------------------------------------------------------------------
 * A minimal PNG writer. No dependency is installed on this machine and the
 * format is four chunks, so it is written out rather than fetched.
 * ------------------------------------------------------------------------ */
const CRC_TABLE = (() => {
    const t = new Int32Array(256);
    for (let n = 0; n < 256; n++) {
        let c = n;
        for (let k = 0; k < 8; k++) c = (c & 1) ? (0xEDB88320 ^ (c >>> 1)) : (c >>> 1);
        t[n] = c;
    }
    return t;
})();

function crc32(buf) {
    let c = 0xFFFFFFFF;
    for (let i = 0; i < buf.length; i++) c = CRC_TABLE[(c ^ buf[i]) & 0xFF] ^ (c >>> 8);
    return (c ^ 0xFFFFFFFF) >>> 0;
}

function chunk(type, data) {
    const len = Buffer.alloc(4);
    len.writeUInt32BE(data.length, 0);
    const body = Buffer.concat([Buffer.from(type, 'ascii'), data]);
    const crc = Buffer.alloc(4);
    crc.writeUInt32BE(crc32(body), 0);
    return Buffer.concat([len, body, crc]);
}

function png(width, height, rgba) {
    const sig = Buffer.from([0x89, 0x50, 0x4E, 0x47, 0x0D, 0x0A, 0x1A, 0x0A]);
    const ihdr = Buffer.alloc(13);
    ihdr.writeUInt32BE(width, 0);
    ihdr.writeUInt32BE(height, 4);
    ihdr[8] = 8;    // bit depth
    ihdr[9] = 6;    // colour type: truecolour with alpha
    ihdr[10] = 0;   // deflate
    ihdr[11] = 0;   // adaptive filtering
    ihdr[12] = 0;   // no interlace

    // One filter byte per scanline, filter type 0 (none).
    const raw = Buffer.alloc(height * (1 + width * 4));
    let o = 0;
    for (let y = 0; y < height; y++) {
        raw[o++] = 0;
        rgba.copy(raw, o, y * width * 4, (y + 1) * width * 4);
        o += width * 4;
    }

    return Buffer.concat([
        sig,
        chunk('IHDR', ihdr),
        chunk('IDAT', zlib.deflateSync(raw, { level: 9 })),
        chunk('IEND', Buffer.alloc(0)),
    ]);
}

/* ---------------------------------------------------------------------------
 * Two files, because the glyph is currentColor on the site and an email has no
 * currentColor: it has to be baked. The two foregrounds are the two the button
 * styles use, and they are the audited brand values rather than eyeballed ones.
 * ------------------------------------------------------------------------ */
const OUT = path.join(__dirname, '..', 'public', 'images');

const VARIANTS = [
    { file: 'icon-calendar-ink.png', hex: '373433', note: 'on the yellow primary button' },
    { file: 'icon-calendar-teal.png', hex: '0E7680', note: 'on the white outline button' },
];

for (const v of VARIANTS) {
    const r = parseInt(v.hex.slice(0, 2), 16);
    const g = parseInt(v.hex.slice(2, 4), 16);
    const b = parseInt(v.hex.slice(4, 6), 16);

    const rgba = Buffer.alloc(SIZE * SIZE * 4);
    let i = 0;
    let inked = 0;
    for (let y = 0; y < SIZE; y++) {
        for (let x = 0; x < SIZE; x++) {
            const a = coverage(x, y);
            if (a > 0) inked++;
            rgba[i++] = r;
            rgba[i++] = g;
            rgba[i++] = b;
            rgba[i++] = Math.round(a * 255);
        }
    }

    // A checker that cannot fail is not a checker. An empty bitmap is the one
    // way this can go wrong silently, and it would ship as an invisible glyph.
    if (inked < 100) {
        console.error(`FAIL: ${v.file} came out almost empty (${inked} inked pixels).`);
        process.exit(1);
    }

    const buf = png(SIZE, SIZE, rgba);
    fs.writeFileSync(path.join(OUT, v.file), buf);
    console.log(`${v.file}  ${SIZE}x${SIZE}  #${v.hex}  ${buf.length} bytes  ${inked} inked px  (${v.note})`);
}

console.log('\nDisplayed at 16x16 in the email, so 2x for a retina screen.');
