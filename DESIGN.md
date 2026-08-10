---
version: 1.0
name: SFAF Calendar
description: "San Francisco AIDS Foundation calendar plugin. Two surfaces: a public events calendar rendered into sfaf.org and embedded on partner pages, and /caladmin, a standalone front-end portal where staff manage events. The system is restrained by mandate: one accent, one primary action per view, minimal rules and boxes, and a fixed brand palette that is not open to reinterpretation. Montserrat carries every heading and all portal chrome; Merriweather carries body prose on public surfaces only. Type, weight and space do the work that borders and color are not allowed to do."
authority: SFAFbrandguide2026v3.0.pdf
colors:
  yellow: "#FFD900"
  orange: "#F7921E"
  red: "#F04937"
  burgundy: "#A30C33"
  pink: "#F1668C"
  purple: "#8D54A2"
  green: "#8CC745"
  teal: "#16BECF"
  light-gray: "#D1D3D4"
  dark-gray: "#373433"
  teal-text-on-light: "#0E7680"
  ink-public: "#1A1D21"
  ink-portal: "#373433"
  muted-portal: "#6B6764"
  secondary-public: "#6B7280"
  canvas-portal: "#F5F6F7"
  sidebar: "#373433"
  sidebar-text: "#D1D3D4"
fonts:
  heading: "Montserrat"
  body: "Merriweather"
---

# DESIGN.md, SFAF Calendar

## What this document is

The visual authority for this repository. It is transcribed from
**SFAFbrandguide2026v3.0.pdf** and from decisions settled and measured across
releases 3.1.0 to 3.20.0.

**The brand guide and this file are the only authority for color and
typography.** No skill, plugin or reference `DESIGN.md` may substitute a
palette, a font pairing or a color of its own. Design tooling is for layout,
spacing, density and restraint. If a tool proposes a different palette or a
different type pairing, the brand guide wins and the proposal is discarded
without discussion.

Every ratio below was measured, not estimated: sRGB linearised, luminance
`0.2126R + 0.7152G + 0.0722B`, contrast `(L1 + 0.05) / (L2 + 0.05)`. Translucent
fills are pre-flattened to a hex before measuring, because a fill over an
unknown backdrop cannot be contrast-checked.

---

## 1. Color

### The approved palette, and nothing else

| Name | Hex | Role |
|---|---|---|
| Yellow | `#FFD900` | Primary actions ONLY. One per view. |
| Orange | `#F7921E` | Category only |
| Red | `#F04937` | Category, and destructive state |
| Burgundy | `#A30C33` | Category only |
| Pink | `#F1668C` | Category only |
| Purple | `#8D54A2` | Category only |
| Green | `#8CC745` | Category, and success state |
| Teal | `#16BECF` | The single structural accent |
| Light Gray | `#D1D3D4` | Hairlines, and text on dark surfaces |
| Dark Gray | `#373433` | Body text, and the portal sidebar |

Nothing outside this list is a brand color. Neutrals derived for surfaces
(`#F5F6F7` portal canvas, `#E2E5EA` hairline, `#6B6764` portal muted) are
tooling, not palette, and may not be presented as brand colors.

### Teal is one color with three different correct answers

This is the rule that has been got wrong most often, because the arithmetic
inverts depending on the surface underneath.

| Pairing | Ratio | Verdict |
|---|---|---|
| Brand teal `#16BECF` on white | **2.26:1** | **BANNED for text** |
| White on brand teal `#16BECF` | **2.26:1** | **BANNED. Never use it.** |
| Guide's darkened teal `#0E818C` on white | 4.63:1 | Passes, barely |
| `#0E818C` on the card band `#E8F9FA` | **4.27:1** | **FAILS** |
| `#0E818C` on the page band `#DFF0F3` | **3.94:1** | **FAILS** |
| Ours `#0E7680` on white | 5.35:1 | Correct |
| `#0E7680` on the page background `#F5F6F7` | 4.95:1 | Correct |
| `#0E7680` on the card band `#E8F9FA` | 4.94:1 | Correct |
| `#0E7680` on the page band `#DFF0F3` | 4.56:1 | Correct |
| `#0E7680` on the dark sidebar `#373433` | **2.30:1** | **BANNED there** |
| Brand teal `#16BECF` on the dark sidebar | 5.47:1 | **Correct there** |

Read as three rules:

1. **Brand teal never carries text on a light surface, and never has text on
   it.** The guide permits darker variations (p.18); it does not permit white
   on the brand value.
2. **`#0E7680` exists because `#0E818C` is not enough.** The guide's own
   darkened teal clears 4.5:1 on plain white and then fails the moment it lands
   on a tinted heading band, which is exactly where teal text lives in this
   product. `#0E7680` is the darkening that survives all four light surfaces.
3. **On the dark sidebar the logic inverts.** `#0E7680` collapses to 2.30:1
   there and is banned; brand teal at full value is the correct choice at
   5.47:1. A rule that is right on white is not automatically right on
   `#373433`, and vice versa.

### Yellow is for primary actions only. One per view.

Yellow means "this is the thing to act on". It appears once on a form, once in
the sidebar (the pending queue), and nowhere decorative.

- Dark Gray `#373433` on Yellow: **8.92:1**. This is the only text color on
  yellow.
- White on Yellow: **1.38:1**. Never.

### Teal is the single structural accent

One accent, used for structure: focus rings, the active indicator, heading
bands, the accent edge on a card. A second structural accent is not available.
Category color is a separate system and is not an accent.

### Text on a category color uses the darkest stop of that same family

Each category color carries a three-stop ramp: a tint for chips, a media tone
for placeholder tiles, and an ink for text on both.

| Family | Base | Tint | Ink | Ink on tint |
|---|---|---|---|---|
| Yellow | `#FFD900` | `#FFFAE0` | `#705F00` | 6.01:1 |
| Orange | `#F7921E` | `#FEF2E4` | `#8A4C05` | 6.11:1 |
| Red | `#F04937` | `#FDE9E7` | `#AD1C0D` | 6.10:1 |
| Burgundy | `#A30C33` | `#F4E2E7` | `#A30C33` | 6.35:1 |
| Pink | `#F1668C` | `#FDEDF1` | `#B3103D` | 6.07:1 |
| Purple | `#8D54A2` | `#F1EAF4` | `#7F3A98` | 6.02:1 |
| Teal | `#16BECF` | `#E3F7F9` | `#0C666F` | 6.02:1 |
| Green | `#8CC745` | `#F1F8E9` | `#46661F` | 6.08:1 |
| Light Gray | `#D1D3D4` | `#F9FAFA` | `#373433` | 11.80:1 |
| Dark Gray | `#373433` | `#E7E7E7` | `#373433` | 9.98:1 |

**Never black, never gray**, and the two have different reasons:

- Near-black `#1A1D21` on the teal tint measures 15.25:1, so it passes contrast
  and is still wrong. A chip in ten families with black text on all ten is ten
  colored boxes saying one thing. The family stop is what keeps the chip
  legible AND identifiably that category.
- Muted gray `#6B7280` on the same tint measures **4.36:1** and fails outright.

Red and Pink are large-text only where used as text. Burgundy's ink is its own
base value, which is already dark enough.

### Contrast floors

4.5:1 wherever there is text. 3:1 for anything that is only a shape: an icon
without a label, an indicator bar, a control boundary. State which floor
applies when reporting a measurement, because reporting an icon against 4.5:1
produces a false failure.

---

## 2. Typography

### The faces

- **Montserrat** for every heading, and for all `/caladmin` chrome. The portal
  is a tool; it is Montserrat throughout.
- **Merriweather** for body prose on public surfaces only. It is not used in
  the portal.

Fallbacks are stated, never assumed: `-apple-system, BlinkMacSystemFont,
'Segoe UI', Roboto, Helvetica, Arial, sans-serif` for Montserrat and
`Georgia, 'Times New Roman', serif` for Merriweather.

### The stylesheet loads them itself

**Neither face was loaded at all until 3.17.0**, because a comment asserted the
host theme already provided them and nobody checked. Every heading on every
public surface had been rendering in the fallback for the entire life of the
brand pass.

The rule that follows: **this plugin loads its own webfonts and never assumes
the host does.** `calendar.css` imports both faces; the portal head loads
Montserrat only, because Merriweather has no role there. A comment claiming
something is provided elsewhere is not evidence that it is.

### The scale, and every step is visibly different

| Step | Size | Weight | Treatment |
|---|---|---|---|
| Page title | 24px | 800 | `h1`, tight tracking |
| Section | 20px | 700 | section head |
| Card heading | 14px | 600 | UPPERCASE, `0.06em` tracked, on the tinted band |
| Subhead | 16px | 600 | 22px of space above |
| Field label | 13px | 600 | |
| Body | 14px | 400 | |
| Helper | 12px | 400 | muted |

**The card heading is deliberately smaller than the subhead.** Size is not what
makes it dominant: uppercase, letter-spacing and the colored band across the
card are. Nothing may sit between two steps. If a new element needs a size that
is not on this ladder, it is the wrong element, not a missing step.

A heading at the top of a card **is** that card's heading and takes the band
automatically. It is not a class somebody has to remember to add, because for
sixteen of thirty-nine cards they did not.

---

## 3. Dates and times, AP style

Per the guide. **One formatter, `sfaf_ap_date()` / `sfaf_ap_time()` /
`sfaf_ap_time_range()` / `sfaf_ap_date_range()`. Never format a date at the
call site.**

- **No ordinals.** "August 4", never "August 4th".
- **Lowercase `am` and `pm`, set off by one space.** "6 pm", not "6PM" or
  "6 p.m."
- **`:00` is dropped.** "6 pm", not "6:00 pm".
- **Ranges use an en dash** (U+2013), not a hyphen and not an em dash.
- **The first meridiem is omitted when the range shares it.** "6–7:30 pm". When
  it does not, both are said: "11 am–1 pm".
- **Date ranges say the month once** when both ends share it: "1–31 Aug".

Times stored as raw meta (`18:00-19:30`) must never reach a screen. That
shipped in the pending queue and was fixed in 3.18.0.

---

## 4. Layout and restraint

> "Less is more, employ minimal use of boxes, rules, and other graphic
> embellishments that compartmentalize text and clutter up layouts."
>
> SFAF brand guide 2026 v3.0

Contrast is made with **weight and size**, not with rules and color. That is the
governing instruction, and the four faults below are the specific ways this
project keeps breaking it. They are named because each one has shipped.

### No explanatory paragraph under a control

Helper text tells somebody **what to do** or **what will happen to them**. If a
sentence justifies a design decision, it does not ship.

- Ships: "Open the team once it exists to add people to it."
- Ships: "Taking somebody out of a team stops their notifications for every
  event naming it."
- Does not ship: any sentence explaining why the screen is arranged as it is.

Apply the test to every sentence, not to the paragraph. This was applied in
3.15.0 and again in 3.19.0 and 3.20.0, and each time the offending sentence had
survived several passes because the paragraph around it was useful.

### No boxes inside boxes

A card inside a card inside a bordered list is three borders saying one thing.
If content needs separating inside a card, use space, then a hairline, then a
tint, in that order. Reach for a border last and usually not at all.

### No diagnostic or developer output on a manager's screen

Raw ids, stored meta, timezone strings that never differ, serialized values,
counts nobody can act on. `/caladmin` is used by staff running programmes, not
by developers. A number on that screen must be one the reader can do something
about, and it must be scoped to what they can act on: an unscoped count is
always the one that went round the query layer straight to `$wpdb`.

### Something must lead

Nothing at the same visual weight as everything else. Every screen has a
primary thing, and the type scale, the single yellow action and the one accent
are the instruments for saying which. Five columns giving a title, a badge, a
timezone, an address and two verbs equal weight is a table with no answer in
it.

---

## 5. CSS discipline

Five separate defects where a rule was correct and never reached the screen.
These are not style preferences; they are the failure modes this codebase
actually has.

### Verify against rendered output, not against the rule you wrote

A verified change is not a verified outcome. `git log -S` answers "was my edit
applied". It does not answer "why does this still look like that". Start from
the element as rendered: its classes, its attributes, its inline styles. Then
ask what could produce the effect **by any mechanism**.

### One class loses to one class plus one type

`.uc-portal a` is (0,1,1). `.uc-nav-item` is (0,1,0). The second never wins.
This has bitten four times: the sidebar nav rendering at 2.30:1 for two
releases, the embed heading losing its margin, padding and hairline, the
thumbnail losing its aspect ratio, and the public calendar's filter pills
losing `border-radius: 99px` to a reset's `0`.

Host-proofing resets must be scoped as `.root element`, which is exactly the
shape that outranks a bare component class. When they collide: raise the
component to two classes, or drop the base rule to zero specificity, whichever
matches intent.

### Baselines go at zero specificity with `:where()`

A catch-all must always lose to a class rule. `:where()` contributes zero, so
`:where(.uc-portal) a` is (0,0,1) and anything naming a color of its own wins by
naming it once. Written `.uc-portal a` the same rule repainted nine components.

Two constraints on such a rule: never put `display` in one blind, and key it on
what the thing **is** rather than on a class somebody must remember to add.
`[class*="-actions"]` follows the naming convention already in use, so a row
added tomorrow is spaced the moment it is named.

### Watch shorthand

`background: #fff` resets `background-image` to `none`. That deleted
WordPress's select arrow while `appearance: none` stayed, leaving a white box
that happens to open a menu. A fully styled control can still lose an
affordance, and no container audit finds it, because nothing is unstyled.

### A visual element may not be CSS at all

The sidebar stripe was `<div class="uc-compact-accent">`, absolutely positioned,
with its color written as an inline style in PHP. The stylesheet only sized and
placed it. Every search for `border-left` came back clean and every one of those
searches was answering a question about the wrong thing. Check the renderers for
an element that **is** the effect.

---

## 6. Surfaces

| Surface | Canvas | Body face | Notes |
|---|---|---|---|
| Public calendar | white on `#F7F8FA` | Merriweather | Rendered into sfaf.org |
| Embedded blocks | white card, own border | Merriweather | Inside a host page we cannot see; state every property |
| `/caladmin` portal | `#F5F6F7` | Montserrat | Staff tool, Montserrat throughout |
| Portal sidebar | `#373433` | Montserrat | Contrast logic inverts here; see teal |
| WordPress admin | core | core | Core's `input[type=text]` is (0,1,1), so `:where()` does nothing; scope to our own roots |

An embedded block renders inside somebody else's page. Every property is stated
including the ones that look like defaults, because an `h3` in a host theme
routinely arrives with a serif face, a border, uppercase, letter-spacing, a top
margin and a color of its own.
