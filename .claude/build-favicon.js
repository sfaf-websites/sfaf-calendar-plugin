/**
 * THE CALADMIN FAVICON, DRAWN FROM THE PLUGIN'S OWN CALENDAR GLYPH.
 *
 *     node .claude/build-favicon.js            # write the PNGs
 *     node .claude/build-favicon.js --preview  # print it as text at 16 and 32
 *
 * WHY THIS EXISTS. caladmin builds its own document, so it owns its <head> and
 * can carry a favicon that the rest of resources.sfaf.org does not. Without one
 * a caladmin tab is indistinguishable from every other SFAF tab, which is the
 * whole of the problem it solves.
 *
 * IT IS THE MARK THIS PLUGIN ALREADY DRAWS, not a new asset. The geometry below
 * is sfaf_icon_paths()['calendar'] read as geometry, exactly as
 * build-email-icons.js reads it: a second calendar glyph would be the
 * two-renderers fault this project has already paid for twice.
 *
 * WHAT WAS SIMPLIFIED, AND WHY IT HAD TO BE. The sidebar glyph is a 2 unit
 * stroke on a 24 unit grid with two hanging tabs above the body. At 16 device
 * pixels the stroke is 1.33px and the tabs are two 1.33px nubs three pixels
 * long: --preview at 16 shows them smearing into the head rule and each other.
 * So the tabs are DROPPED and the head rule is drawn as a SOLID BAND rather
 * than a line. A filled band survives any downsampling a hairline does not, and
 * "rounded box with a dark cap" is still unmistakably a calendar. The mark is
 * the same mark; what changed is what it can afford to say at this size.
 *
 * THE COLOURS ARE THE BRAND PAIR AND ARE NOT NEGOTIABLE HERE. Dark Gray
 * #373433 on brand Yellow #FFD900, measured at 8.92:1, which is already the
 * primary button treatment. See DESIGN.md.
 */
'use strict';

const fs = require('fs');
const path = require('path');
const zlib = require('zlib');

const GRID = 24;          // the icon spec's coordinate space
const SS = 4;             // supersamples per axis, for the antialiasing

const INK = '373433';     // SFAF Dark Gray
const FILL = 'FFD900';    // SFAF Yellow

/* ---------------------------------------------------------------------------
 * The mark, in grid units.
 *
 * TILE is the yellow ground and bleeds to the edge: a favicon is a tile, not a
 * glyph floating on whatever the browser paints behind it, and a transparent
 * one would put dark gray strokes on the dark chrome of a dark-mode browser.
 * ------------------------------------------------------------------------ */
const TILE = { x: 0, y: 0, w: 24, h: 24, r: 5 };
const BODY = { x: 4.4, y: 6.4, w: 15.2, h: 13.2, r: 2.6 };
const STROKE = 2.4;       // heavier than the 2 unit spec: see the note above
const HEAD_Y = 10.9;      // the solid band runs from BODY.y down to here

/** Signed distance to a rounded rectangle's outline. Negative inside. */
function sdRoundBox(px, py, box) {
    const cx = box.x + box.w / 2;
    const cy = box.y + box.h / 2;
    const bx = box.w / 2 - box.r;
    const by = box.h / 2 - box.r;
    const qx = Math.abs(px - cx) - bx;
    const qy = Math.abs(py - cy) - by;
    const outside = Math.hypot(Math.max(qx, 0), Math.max(qy, 0));
    const inside = Math.min(Math.max(qx, qy), 0);
    return outside + inside - box.r;
}

/** Is this point inside the yellow tile at all? */
function inTile(px, py) {
    return sdRoundBox(px, py, TILE) <= 0;
}

/**
 * Is this point dark ink?
 *
 * Two shapes unioned: the body's outline as a stroke, and the head as a solid
 * band clipped to the body. The band is clipped rather than drawn as its own
 * rounded rect so its top corners are the body's corners exactly, with no
 * seam where two radii nearly agree.
 */
function inInk(px, py) {
    const d = sdRoundBox(px, py, BODY);
    if (Math.abs(d) <= STROKE / 2) return true;         // the outline
    if (d <= 0 && py <= HEAD_Y) return true;            // the solid head band
    return false;
}

/** Coverage of one output pixel, 0..1, for each of the two layers. */
function sample(x, y, size) {
    const S = size / GRID;
    let tile = 0;
    let ink = 0;
    for (let sy = 0; sy < SS; sy++) {
        for (let sx = 0; sx < SS; sx++) {
            const px = (x + (sx + 0.5) / SS) / S;
            const py = (y + (sy + 0.5) / SS) / S;
            if (inTile(px, py)) {
                tile++;
                if (inInk(px, py)) ink++;
            }
        }
    }
    const n = SS * SS;
    return { tile: tile / n, ink: ink / n };
}

/* ---------------------------------------------------------------------------
 * A minimal PNG writer, lifted from build-email-icons.js for the same reason
 * it was written there: no image dependency is installed on this machine and
 * the format is four chunks.
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
    ihdr[10] = 0;
    ihdr[11] = 0;
    ihdr[12] = 0;

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

function rgb(hex) {
    return [
        parseInt(hex.slice(0, 2), 16),
        parseInt(hex.slice(2, 4), 16),
        parseInt(hex.slice(4, 6), 16),
    ];
}

/** One bitmap: yellow tile, dark mark, transparent outside the tile. */
function render(size) {
    const [ir, ig, ib] = rgb(INK);
    const [fr, fg, fb] = rgb(FILL);
    const rgba = Buffer.alloc(size * size * 4);
    let i = 0;
    let inked = 0;
    let filled = 0;

    for (let y = 0; y < size; y++) {
        for (let x = 0; x < size; x++) {
            const { tile, ink } = sample(x, y, size);
            if (ink > 0) inked++;
            if (tile > 0) filled++;
            // Ink composited over the yellow, both clipped to the tile.
            const k = tile > 0 ? ink / tile : 0;
            rgba[i++] = Math.round(fr + (ir - fr) * k);
            rgba[i++] = Math.round(fg + (ig - fg) * k);
            rgba[i++] = Math.round(fb + (ib - fb) * k);
            rgba[i++] = Math.round(tile * 255);
        }
    }
    return { rgba, inked, filled };
}

/* ---------------------------------------------------------------------------
 * --preview: the thing the brief actually asked for.
 *
 * "Check it at 16px, since a glyph drawn for 20px in a sidebar can turn to mush
 * at favicon size." This is that check, and it is why the tabs are gone: run it
 * against the unsimplified geometry and the two tabs print as single specks
 * that touch the head rule.
 * ------------------------------------------------------------------------ */
function preview(size) {
    const ramp = ' .:-=+*#%@';
    console.log(`\n${size}x${size}, ink coverage as text:`);
    for (let y = 0; y < size; y++) {
        let row = '';
        for (let x = 0; x < size; x++) {
            const { tile, ink } = sample(x, y, size);
            if (tile <= 0) { row += ' '; continue; }
            const k = tile > 0 ? ink / tile : 0;
            row += ramp[Math.min(ramp.length - 1, Math.round(k * (ramp.length - 1)))];
        }
        console.log('  |' + row + '|');
    }
}

if (process.argv.includes('--preview')) {
    preview(16);
    preview(32);
    process.exit(0);
}

const OUT = path.join(__dirname, '..', 'public', 'images');
fs.mkdirSync(OUT, { recursive: true });

let bad = 0;
for (const size of [32, 180]) {
    const { rgba, inked, filled } = render(size);

    /*
     * A checker that cannot fail is not a checker. An empty or a solid bitmap
     * are the two ways this goes wrong silently, and both would ship: one as an
     * invisible favicon, one as a yellow square.
     */
    const floor = Math.round(size * size * 0.04);
    if (inked < floor) {
        console.error(`FAIL: ${size}px came out almost empty (${inked} inked pixels, floor ${floor}).`);
        bad++;
        continue;
    }
    if (inked >= filled) {
        console.error(`FAIL: ${size}px is solid ink (${inked} of ${filled}).`);
        bad++;
        continue;
    }

    const file = 32 === size ? 'favicon-caladmin.png' : 'favicon-caladmin-180.png';
    fs.writeFileSync(path.join(OUT, file), png(size, size, rgba));
    console.log(`wrote public/images/${file}  (${inked} inked of ${filled} tile pixels)`);
}

process.exit(bad ? 1 : 0);
