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
| Light Gray | `#D1D3D4` | Hairlines, text on dark surfaces, and the secondary button's face |
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

**The one place inside `/caladmin` that is Merriweather, and why it is not an
exception.** The rich text editor's own frame, from 3.54.0. TinyMCE draws into
an iframe with its own document, and what that document holds is not portal
chrome: it is a draft of body prose that will render on the public event page in
exactly this face. Showing somebody a sans-serif draft of something a visitor
reads in a serif is the version of this rule that gets broken, not the version
where the preview matches. The chrome around the frame stays Montserrat, and
`public/css/editor-content.css` is the only thing that styles inside it.

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
Montserrat only, because Merriweather has no role in the chrome; the editor
frame asks for its own face in its own stylesheet, so that carve-out costs the
portal document nothing. A comment claiming
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
sixteen of thirty-nine cards they did not. Both kinds of card carry that rule
now: `.uc-card` since 3.17.0 and `.uc-bento-card` since 3.41.0, which had only
escaped the same fate because every one of its headings happened to carry
`.uc-bento-title`.

**The ladder is checked, not asserted.** `php .claude/type-scale-sweep.php`
reports every rule in `portal.css` that sets a size and a weight that is not one
of the seven steps; the count is zero and a build that raises it fails. Run it
with `--self-test` first, which plants eleven cases and proves it can reject
them. Chrome is exempt BY SELECTOR and every exemption carries its reason, so
widening the ladder is a decision somebody writes down.

**The first version of it could not see a half pixel, which is the one value the
ladder exists to forbid.** It matched `(\d+)px`, and the digits in `13.5px` are
not followed by `px`, so every fractional rule was skipped rather than judged,
and its self-test passed because every case in it was a whole number. Six real
rules were hiding behind that: `.uc-field-error` and `.uc-reassign-form label`
at 12.5px/600, `.uc-cancel-state` at 13.5px/700, `.uc-cancel-visibility legend`
at 12.5px/700, and two now exempt by name. So the cancel card was carrying two
of them, the reassign form one and every field error one, which is what "the
scale was applied to headings and not to what sits under them" looks like away
from the card that got reported.

**The scale was applied to headings and not to what sits under them, which is
why the complaint came back.** Twelve rules were between two steps. The one that
was reported was the Classification card reading heavier than every other card
in the editor: its heading is the same `.uc-bento-title` as all of them, but the
card is almost entirely checkbox labels, and `.uc-check` was 14px/500. Half a
step above body, and inside `.uc-check-grid` it was shrunk to 13px with the 500
left, which is the FIELD LABEL's own size. A card with thirty of those reads as
a wall no matter what its heading does. A checkbox label is a choice, so it is
body: 14px/400.

---

## 3. Dates and times, AP style

Per the guide. **One formatter, `sfaf_ap_date()` / `sfaf_ap_datetime()` /
`sfaf_ap_time()` / `sfaf_ap_time_range()` / `sfaf_ap_date_range()`. Never format
a date at the call site.**

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

**The rule is checked, not asserted.** `php .claude/date-callsite-sweep.php`
tokenises every PHP file and prints every human-facing date format outside the
formatter; the count is zero and a build that raises it fails the check. The
rule was set in 3.17.0 and fifteen call sites were still in place seven builds
later, two of them added after it, which is the whole argument for a checker
over a paragraph. Run it with `--self-test` first: it plants nine cases and
proves it can reject them.

**A missing style is why a call site writes its own format**, so add the style
rather than the format. `short_year` and `month_year` exist for that reason.

**Machine formats are not this rule's business.** `Y-m-d` keys, `Y-m` slugs, the
ICS stamp, `H:i` meta and the `w` weekday numbers the recurrence engine compares
are storage and protocol. Putting them through a localised formatter breaks
them, and the sweep classifies them apart on purpose.

**Two traps, both of which have shipped as wrong days rather than wrong
formats.** PHP's `date()` reads the SERVER clock, and WordPress runs it in UTC,
so an evening event or an evening registration renders as tomorrow: always
`date_i18n()`, which is what the formatter uses. And `strtotime( '2026-08-04' )`
is midnight UTC, which a site-timezone formatter renders as the 3rd anywhere
west of Greenwich: hand `sfaf_ap_date()` the stored date STRING, which it
anchors at midday for exactly this reason, rather than a timestamp made from it.

**Two engines, one wording.** `SFAF_Recurrence::pattern_label()` has a mirror in
`public/js/portal.js`, so a copy change is two files and
`.claude/recurrence-crosscheck.php` is what proves they still agree.

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

### White has to mean something, or it means nothing

A row of controls on a white page, each of them a white box with a thin
outline, tells a visitor nothing about which is which. That was the public
filter bar until 3.61.0: a search field, a dropdown, category chips and group
pills, four different kinds of control and one appearance between them.

The fix is not more outlines. **Put a tint under the group and let white become
a statement:**

- **white fill and a boundary** — you type in this, or it opens
- **no fill and a boundary** — you press this

That is the tint step of the escalation above, used at the level of a whole
control group rather than inside one card, and it does more work than any
amount of bordering because it makes white *informative* instead of default.

Two things follow from it and are worth keeping:

- **Differentiate by shape and mark before colour.** A pick-one row is
  round-cornered and its chosen item fills; a pick-any row is square-cornered
  and its chosen items get ticks. Those read at a glance, survive a
  colour-blind viewer and do not spend a hue. Contrast made of weight and
  shape is what the brand guide asks for; colour is the last instrument, not
  the first.
- **An affordance belongs in the markup, not in a background.** A chevron or a
  magnifier drawn as `background-image` is deleted by any host rule that says
  `background:` anything, which is how WordPress's select arrow was lost in
  3.18.0. Drawn as an element, and placed on the wrapper rather than on the
  control, no rule about `select` or `input` can reach it at all.

### The control standard (3.64.0)

The rule above was settled on the public filter bar and then applied to one
surface. `/caladmin` kept the first half of it and not the second: a text field
was white with a boundary, and so was a secondary button, so **the two were the
same object with a different font-weight**. It was reported three times, on
three screens, and fixed twice by restyling the screen it was reported on.

**Fixing instances is what makes the next instance.** The defect is not "this
button looks wrong"; it is that one kind of control had several definitions and
the one that reached the screen was decided by which wrapper the control
happened to sit in. A text field's appearance was declared in **fourteen**
places, nine of which handed it the decorative hairline instead of a boundary.

**One definition per kind, and the kinds are these.**

| Kind | Fill | Boundary | Says |
|---|---|---|---|
| Text input, select, textarea | white | `--p-border-strong` | you type in this |
| Select | white, plus a chevron **and an end cap** | `--p-border-strong` | it opens a list |
| Secondary button | Light Gray `#D1D3D4` | `--p-border-strong` | you press this |
| Primary button | Yellow `#FFD900`, ink `#373433` | `#E0BE00` | this is THE action. One per view |
| Utility button | `#0E7680`, white ink | its own | go and get it again |
| Destructive button | `#c0392b`, white ink | its own | behind a disclosure |
| Quiet action | none | none | a verb in a row of verbs |
| Segmented option group | white, chosen fills `#0E7680` | one boundary, seams inside | pick one of these |
| Option card | white | `--p-border-strong` | pick one, with its consequence under it |
| Disclosure | inherits | none, or a ring on a card head | this opens |

**In caladmin the button takes the surface, not the field, and that is not a
reversal of the filter-bar rule.** "No fill" is only available where the group
sits on a tint. A caladmin button sits on a white card as often as on the page,
so transparent *is* white there. The statement is kept by inverting which half
carries it: white stays "you type in this", and the button gets Light Gray.
Measured, in `.claude/control-standard-audit.php`:

| Pairing | Ratio |
|---|---|
| `#D1D3D4` face against a white card | 1.50:1 |
| `#D1D3D4` face against the page `#F5F6F7` | 1.39:1 |
| `#373433` ink on the face | 8.22:1 |
| `#BFC2C4` hover face, ink on it | 6.89:1 |

**The fill is not the boundary and is not asked to be.** 1.50:1 is a visible
surface, not an edge; the 1px `--p-border-strong` at 3.33:1 is what clears WCAG
1.4.11. Two jobs, two declarations.

**It must not read as disabled, and the numbers are how that stays true.** A
disabled control here is *lighter* than the page with *muted* ink: `#F3F4F6`
with `#6B7280`, or `#F9FAFB` with `#4B5563`. The button face is darker than
both, at full ink and 600 weight, and measures 1.37:1 against the nearer of
them.

**An option group is not a row of buttons.** Pressing a segment picks a value;
it does not do a thing. So a pick-one group wears **one** boundary with seams
inside it and its chosen item fills, and a choice with a consequence written
under it is an **option card** with the white "you are working in this" fill,
never the button face. The moment either takes a button surface, the screen has
stopped saying which things do something.

**A disclosure has one mark, and the plain glyph is the default.** The ring is
for a `<summary>` that IS a card's head band, where the band is a surface and
the ring is a control on it. Everywhere else the chevron inherits its colour
from the line it sits in, so no disclosure needs a rule about its own marker.

**And the affordance rule above has a boundary of its own.** `/caladmin` has no
host page: `portal.css` is the only stylesheet on it, and the one rule that
could delete a `background-image` affordance is the `background` shorthand,
which the baseline bans and the audit checks. So the select's chevron and end
cap are background layers there, and elements on the public calendar, and that
difference is a decision rather than a drift. The idea is the same one either
way: a chevron says it opens, and an end cap makes the right-hand end a part of
the control rather than more of the field.

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

**When the instruments are already spent, use position and space** (3.42.1). The
event editor's Save and Publish were a right-aligned pair under a hairline,
between the last card and the cancel card, wearing the same border as every
divider on the page. The obvious fix is more colour, and it was not available:
Publish already carries the one yellow, and tinting the row it sits on would put
two signals in one place competing to be the answer. So the row became a BAND
with its own surface, set clear of the cards on both sides, with the largest
buttons on the page. Surface, separation and size are the fourth instrument and
they cost nothing from the palette.

**And emphasis given everywhere is emphasis spent.** Only the event editor's row
gets this. Every other screen's actions sit in a small form where they are
already the obvious next thing, and a band on all of them would make the band
mean nothing.

**A destructive control is reached deliberately, not scrolled into** (3.42.1).
Cancelling an event was a card in the same rhythm as the cards above it, so
somebody scrolling past Save landed in radio buttons and a red button. It is a
closed disclosure now, and the red only appears once it is open, on the control
that does the thing: a closed disclosure that shouts is the same problem in a
smaller box.

> **A STATE IS NOT AN ACTION, and the two want opposite treatments.** The same
> renderer shows a cancelled event's status and its Reinstate control, and that
> is NOT hidden. Nobody needs protecting from it, it is the most important fact
> on the screen, and it is what somebody came to press. Hiding a status behind a
> disclosure is how somebody edits a cancelled event without noticing it is
> cancelled.

### No color a reader cannot decode

> "Too many colors create a kaleidoscope effect. A neutral base with one or two
> accents is the rule."
>
> SFAF brand guide 2026 v3.0

**If nothing on the page says what a hue means, the hue is decoration wearing
the clothes of information.** A reader who can see that two things are colored
differently, and cannot find out why, has been given a key with no legend. That
is worse than no color, because it invites the belief that something has been
communicated.

**The test: can a visitor learn what this color means without leaving the
screen?** If yes, it is carrying information and it must clear 3:1 as a non-text
graphic. If no, it is decoration and it should be neutral.

**The closure hatch (3.60.0) passes that test rather than being exempt from
it.** A closed day is striped in Red — tint `#FDE9E7`, ink `#AD1C0D`, stripe
`#F04937` at 14% — and every closure also says the word "Closed" in text, in
both renderers, with the grid cell's `aria-label` carrying the whole sentence
before its event count. The stripes agree with something already legible instead
of encoding it, so a reader who cannot see the color loses nothing. **Red is the
palette's own "destructive state" role**, which is what a closure is: the
message is do not come. Grey was not available, because the month grid already
spends grey on out-of-month days and two greys meaning two things is worse than
one highlight. The reasoning is repeated in `calendar.css` at the tokens
themselves, so a decoration audit finds it where it would do the removing.

**3.49.0 removed the month grid's category ring under this rule, reversing half
of 3.31.0, and the reasoning is recorded rather than deleted because it is the
kind somebody restores from.** 3.31.0 was fixing a real defect: the accent bar
it replaced was the raw category color, and six of the ten measure under 3:1 on
white (Yellow 1.38, Light Gray 1.50, Green 2.02, Teal 2.26, Orange 2.31, Pink
2.99). Its answer was a ring in `--cat-ink`, the darkened half of the pair, and
**that answer was right about which color to use**. What it never established
was that a color belonged there at all: ten inks around ten thumbnails in one
cell, and no legend anywhere on the grid.

The thumbnails now take a **neutral 1px hairline in `--uc-border` (#E2E5EA)**,
measured **1.26:1 on a white cell and 1.17:1 on an out-of-month or hovered one**.
Those numbers are not failures of the 3:1 floor: **the floor applies to a graphic
that carries information, and a hairline that distinguishes nothing is not asked
to distinguish anything.** Its job is to bound a photograph with pale edges, and
it is the same token every other edge in the calendar uses.

**What keeps its color, and why the rule does not sweep further:**

- **The category chip.** It carries the category NAME immediately beside it, so
  the color is labeled on the spot.
- **The placeholder tile.** It needs a fill, and its icon is the category's own.

Both are checked, in the same file that measures the ink, so a later sweep
toward "make it neutral" cannot quietly take them.

**Apply an edge to the CONTAINER, not to the picture.** One hairline and one
radius then serve both the photograph and the placeholder, so the two cannot
drift apart, and the declaration is not sitting on the one element in the row
that every host stylesheet writes a rule for.

---

### Colour on two headings, not on thirty-four rows (3.92.0)

The Organizers and groups panel holds thirty-four names in two sections. The
section headings carry `--uc-teal-text`, the measured #0E7680, with the rule
under each mixed from the same token at 22% so the word and its line read as one
mark. **Nothing else in the panel took colour.**

> **The amount is the decision, not the hue.** Two coloured words separate two
> sections. Thirty-four coloured rows are a field of teal with no separation in
> it at all, and the names stop being names. There is an assertion against the
> rows taking the heading colour, because that is the edit that looks like
> consistency and is not.

**The count beside each name stays `--uc-secondary`.** It is a second piece of
information on a row that already has one, and it must lose.

### A tap target is the row, and the row is the label (3.92.0)

The panel's rows came down from 37px to 31px, which is 5px of vertical padding
around one 19.6px line. That is under any reading of a 44px touch target and it
is fine, because **the whole row is the `<label>`**: the hit area is 220 by 31
CSS pixels in the organizers column and 202 by 31 in each group sub-column,
against the 24 by 24 that WCAG 2.5.8 asks for.

> **The checkbox is 13px and has never met that on its own.** Any measurement
> that looks at the box is measuring the wrong element. What makes this safe is
> a structural decision made long before the padding one, and it is the reason
> not to replace the label with a div and a click handler.

**5px is the floor.** Below it a row stops reading as a band and becomes text
with a rule through it, which is the complaint this panel has already had once.
The assertion captures the number and checks the range rather than matching a
literal, so 6px passes and 2px does not.

### Width taken from a gutter is free, width taken from a name is not (3.92.0)

Adding `(12)` to every row in that panel cost each name about 26px, and at the
embed width that wrapped **eleven names of thirty-four onto a second line**. A
wrapped row is 50px against 31px, so the list goes ragged rather than dense,
which is the opposite of what a count is for.

**The 26px came back out of the spacing, not out of the names.** The column gap,
the divider padding and the sub-column gap each went 18px to 14px, the row's
side padding 12px to 10px, the label gap 11px to 8px, the count to 11px. That
put it at three, which is the two that wrapped before counts existed plus one.

> **Once a line is drawn, the space around it is only breathing room.** The
> dividers added in 3.91.0 are what make the narrower gutters affordable: a gap
> alone has to be wide to read as a boundary, a hairline does not.

**Nothing is truncated.** The alternative was an ellipsis, and that is the thing
3.91.0 reversed in the month tiles for the same reason: a column of cut-off
names tells nobody what anything is.

### A scrollbar that fires makes the thing it is scrolling taller (3.92.0)

The panel capped at `min(70vh, 460px)` and its content wanted 460px, so it
clipped by a hair and drew a scrollbar. **The scrollbar then took 15px of width
off every row, which wrapped three more names, which made the content taller
still.** A loop, started by a cap set one pixel too low.

The cap is 520px now. Measured at the embed width with nine organizers and
twenty-five groups:

```
900px viewport   cap 520px   content 460px   no scroll region at all
630px viewport   cap 442px   content 461px   scrolls
```

**The overflow cannot be removed, and the second row is why.** `70vh` is what
binds on a short window: a 1366x768 laptop has about 630px of viewport and 70vh
of that is 441px. Taking the overflow away there would not make the panel fit,
it would put the last few groups past the bottom edge with nothing to reach them
by. **Measure the content and the cap separately before deciding a scrollbar is
unnecessary; "it looks like it fits" is a statement about one window.**

### A parent's suggestion is not a rule: align the box, not the row (3.93.0)

A check box against a label that can wrap to two lines belongs at the TOP. This
was fixed in 3.86.0, fixed again, and reported a third time, and the reason is
the shape of the fix rather than the value in it.

**Both previous fixes set `align-items` on the ROW.** That is a suggestion the
parent makes about its children, and any later rule naming that row takes it
back. By the third report there were **twenty-two rules across three
stylesheets** setting alignment on a box-bearing class, fourteen of them centre
or baseline, and the comment above the 3.86.0 one still said "this is the one
place it is decided".

> **`align-self` on the box beats `align-items` on the container, and not on
> specificity.** They are different properties on different elements, so there
> is no contest to lose. A row written tomorrow with `align-items: center` gets
> a centred row and a top-aligned box.

**When a rule keeps coming back, ask whether it is the kind of rule that can be
taken back.** Specificity is the usual answer and it is the wrong one here: a
higher-specificity `align-items` would have lost to the next higher one. The
property that cannot be overruled from the parent is the fix.

**And the checker reads the source rather than holding a list.**
`.claude/checkbox-align-test.php` finds every element that wraps an
`input[type=checkbox|radio]`, collects its classes, and checks the stylesheets
against that set, exempting the hidden-input controls by reading the stylesheet
for `position: absolute` or `opacity: 0`. A list in a checker is a list somebody
has to remember to add to, which is the same failure as a rule somebody has to
remember to apply.

### An inherited property you do not declare is one the host page sets (3.93.0)

A name in the filter dropdown was breaking mid-word on sfaf.org: "Transformacione"
then "s". Measured against the real terms, the column was never too narrow. The
longest single word in either list is **123.6px in Merriweather at 14px, 145.6px
with its count**, and the sub-column gives a name **161.5px** at the embed width
and 251px stacked. It fits at every width, scrollbar in or out.

**`word-break`, `overflow-wrap`, `hyphens` and `line-break` are inherited.** This
panel renders inside somebody else's page, so not declaring them is not a neutral
choice: it is taking the host's. A theme with `overflow-wrap: break-word` on its
body reaches every name in the panel.

> **The same class of fault as "an `<img>` is what the host styles".** Not a
> cascade fight we lost. A value we never wrote down. The remedy is the same:
> declare it on our own element.

**And put a floor under the thing that would make it real.** `columns: 190px 2`
is a minimum width and a maximum count: two sub-columns wherever two will hold a
name, one where they will not. `columns: 2` alone put no floor under the column
at all, so the arithmetic could have become the cause even after the inherited
rule stopped being it.

### Coloured text is not separation; a band is (3.93.0)

3.92.0 gave the dropdown's two section headings the palette teal. The report on
it: that is not separation, it is coloured text. Correct. **A word in a different
colour above a list still floats above the list. A filled band is a boundary,
because the eye reads an area before it reads a hue.**

The band is brand teal at 18% over white, flattened to **#D5F3F6**, and the
strength is a measured ceiling rather than a preference:

```
10%  #E8F9FA   band 1.08:1 on white   heading 4.94:1
18%  #D5F3F6   band 1.17:1 on white   heading 4.58:1
20%  #D0F2F5   band 1.19:1 on white   heading 4.51:1
24%  #C7EFF3   band 1.23:1 on white   heading 4.35:1  FAILS
```

The heading is 11px, so it is small text at a 4.5:1 floor. **20% clears it by
0.01, which is not a margin.** 18% is the strongest band this text can sit on.

**The rule under the heading went when the band arrived.** A band and a hairline
under the band are two boundaries for one section, which is the "boxes inside
boxes" rule applied to a heading.

### Selection-first is for a list you cannot see all of (3.93.0)

Ticked items floated to the top of the dropdown's columns, and a reorder-on-open
existed so the sort could never move a row out from under the cursor between one
press and the next. Both are gone.

**The behaviour is right in a long scrolling list**, where what somebody just
ticked would otherwise be out of sight and they would have no way to see what is
running. **This panel shows all thirty-five options at once in two columns**, so
it bought nothing and cost somebody their place in a list of names they were
reading down.

> **When you remove a behaviour, remove what was built to protect it.** The
> on-open timing had no purpose but the sort. A function that carefully reorders
> nothing is worse than no function, because the next person has to work out
> what it is for.

### Out of flow costs the list nothing, and the tab order does not move (3.93.0)

The dropdown's Clear sat in a footer row, which cost the list **46px** at exactly
the point where height decides whether the panel scrolls. It is absolutely
positioned in the panel's top right now.

**It is written FIRST in the markup.** Absolute positioning moves a control
visually and changes nothing about where a keyboard reaches it, so a control
drawn at the top and written at the bottom arrives after thirty-five checkboxes.

**An empty container still costs its own padding and margin.** The footer now
renders inside `<noscript>` in its entirety, not just the button in it: leaving
the div outside would have moved the control and kept the height.

Measured with the real terms at the embed width:

```
plain heading and a footer      582px
the heading band alone          578px    (the band saves 4px)
Clear out of the footer alone   536px    (46px)
both                            532px
```

**And a cap measured against invented data is a cap measured against nothing.**
520px was set in 3.92.0 from made-up names; the real ones are much longer, eleven
of thirty-five take two lines and three take three. The cap is 580px.

### A flex item is something a host page can move (3.94.0)

The count beside each name in the filter dropdown was a sibling of the name in a
flex row. **Measured in a browser it sat a constant 10px after the name on every
row. On sfaf.org it was pinned to the column's right edge**, and the only thing
that does that is a rule giving the name a flex-grow, which nothing in this
plugin does.

> **Third time this panel has been hit by a value set outside it**, after an
> inherited `word-break` splitting a name in half and a theme's rule reaching our
> `<img>`. The pattern is the same each time: a property we never declared, or a
> layout role we left available for something else to fill.

**The count is inline inside the name now**, and that fixes a second fault at
the same time: as a flex item it took the row's `align-items: flex-start`, so an
11px count and a 14px name had their box tops level and their text on different
lines. **Inline text shares the name's baseline because it is in the same line
box.** No property has to be set for that and none can be overridden.

**The space before it is a real space in the markup**, for the same reason a
margin was wrong: a margin is a property, and a property is a thing something
else can take away.

### A tint cannot carry a selection on its own (3.94.0)

A ticked row in the dropdown carried `--uc-bg`, which measures **1.06:1**
against the panel. That is not a light background, it is white with a rounding
error, and with thirty-five rows in two columns nobody could see what they had
picked.

**And no tint was going to fix it.** Brand teal over white tops out near 1.3:1
before the name starts losing contrast:

```
10%  #E8F9FA   1.08:1 on white
18%  #D5F3F6   1.17:1
36%  #ABE8EE   1.35:1, and by here the tint is doing the reading
```

**So the fill says which row and a solid edge says that it is picked.** A 3px
rule down the left in `--uc-teal-text` is 5.35:1 against the panel and clears
the 3:1 WCAG asks of a non-text indicator with room; the brand fill #16BECF
would have been 2.26:1 and failed it. **Shape and mark before colour**, which
this file already asks for, applied to a state rather than to a control.

**An inset shadow, not a border**, so the edge costs no layout and ticking does
not shift the list sideways.

### System colour is allowed in caladmin and nowhere public (3.94.0)

The three actions at the foot of the event editor are Save, Cancel and Delete.
**Cancel and Delete were both red**, which is the real fault under "it reads as
a mess": the two actions with the most different consequences on the screen
looked identical. One is reversible and keeps every registration; the other
keeps nothing and tells nobody.

Green, amber and red are **system states, not brand**. The palette rule in
CLAUDE.md is about what a visitor sees; caladmin is a set of controls for one
person doing a job, and going outside the palette is allowed there and only
there.

**Measure green and amber, because those two are usually wrong:**

```
#16A34A green 600   white text 3.30:1   FAILS
#D97706 amber 600   white text 3.19:1   FAILS
#15803D green 700   white text 5.02:1
#B45309 amber 700   white text 5.02:1
#c0392b the red already in use   white text 5.44:1
```

The 600 weights are what a palette hands you first and neither survives white
text. **One weight across the three** so the row is one family rather than three
borrowed palettes, and the amber was already on this screen at that value.

### A corner is a square, not a point (3.94.0)

"See all events sits on the rounded corner" was reported three times and
diagnosed twice, and **both diagnoses measured the wrong element**: the combined
view, where the sidebar has `border: 0` and `padding: 0` because the panel
carries the box, and where the link clears the card by 21px and always has.
**The standalone sidebar, which carries its own box, had never been measured.**

```
card radius                14px, so 13px on the padding box
link bottom to that edge   15px
clearance                  2px
```

**A 14px radius means the card is still coming in for the whole last 14px**, so
a full-width element ending 2px above that has its own bottom corners inside the
curve's square. Two pixels is a coincidence, not a clearance. Bottom padding is
20px now: 21px of clearance, which is the number the other mode has always had.

> **When an explanation has failed three times against what somebody sees, the
> thing to check is whether it is about the element they are looking at.** All
> three fixes were correct about the component they measured.

## 5. CSS discipline

Six separate defects where a rule was correct and never reached the screen.
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

### `container-type: inline-size` erases the element's intrinsic size

**Never put `container-type` on an element whose width can come from its
contents, and an embed handed to other sites can never guarantee it will not.**

`container-type: inline-size` is not only a way to ask questions. It applies
**size containment in the inline axis**, and size containment means the
element's intrinsic sizes are computed *as if it had no contents*. Its
min-content and max-content contributions both collapse to whatever its own
border and padding come to.

That is harmless when the width arrives from the parent, which is the case
everybody tests. It is fatal when the parent sizes itself from its contents,
and there are more of those than you think:

- a table cell
- a float
- an `inline-block`
- a flex item, or a grid track sized `auto` / `min-content` / `max-content`
- an absolutely positioned box with no width

3.24.0 put `container-type` on `.uc-sidebar`, which lives inside whatever
markup a host page happens to have. On the live test page that was a table.
The card's min-content contribution fell from about 150px, a 60px thumbnail
plus the longest word in a title, to 30px of border and padding. Every column
in that table collapsed, every card fell under the 240px breakpoint its own
container queries used, and the thumbnail disappeared from all four columns
including the one labelled 400px. Nobody could reproduce it from the CSS,
because the CSS was correct: the element it was measuring had been erased.

Two remedies, and pick by whether the element needs to be a container at all:

- **It does not.** Take `container-type` off and make the component reflow
  intrinsically, with `flex-wrap` and a declared basis on the column that
  should win. That is what the sidebar row does now, and it cannot be defeated
  by any parent, because it asks nothing about width.
- **It does.** Keep it and give the element a definite `min-width`. A definite
  `min-width` does contribute to intrinsic sizing, so the parent has a floor to
  size from again. `.uc-calendar` carries `min-width: 260px` for exactly this,
  and it overrides the general rule that these blocks never enforce a minimum:
  without it the block does not get cramped, it gets destroyed and takes the
  host's layout with it.

### A floor nobody declared is a floor nobody chose

**Every block handed to a host page states its own `min-width`, whether or not
it is a query container.** The containment case above is the dramatic one. The
quiet one is a block with no floor at all: in a content-sized parent the browser
still asks it how narrow it can be, and it still answers. `.uc-sidebar` answered
196px, which was the longest word in its heading plus the one date span carrying
`white-space: nowrap` plus 30px of border and padding, in whatever face the page
had loaded. Nothing about that number was a decision, and shortening the heading
would have moved it under the width the readme publishes as supported. It
carries `min-width: 200px` since 3.24.2, which is the published number.

**A floor is not a way to make a parent honour a width it has not got, and
raising one makes that case worse.** Four columns asking 180 + 220 + 280 + 400
are asking for 1080px; in a 700px content area the auto table algorithm has
nothing to distribute and pins every column at its floor, so they all render
identically. That is arithmetic. Measure it across page widths before treating
"all the columns are the same" as a fault in the block.

### An `<img>` is the one element a host page is certain to have a rule for

**Never let a picture's fill depend on `width` / `height` when the same slot can
hold a `<span>`.** The sidebar row's placeholder is a span, which no theme
targets, and its photograph is an `<img>`, which every WordPress theme targets
with some form of `img { width: auto; height: auto }` for responsive images. A
component rule at `(0,2,0)` outranks a bare `img` and loses outright to the same
rule carrying `!important`, and when it loses the picture falls back to its
intrinsic size. A 150px crop in a 210px band is not obviously a cascade problem
when you look at it: it looks like a small picture, and the band around it
measures perfectly correct.

The remedy is layout, not specificity: make the slot a flex container and the
media a flex item that grows and stretches, so the used size comes from flexing
and `align-self` rather than from properties on the element. That holds against
rules nobody has seen yet, which `!important` does not.

**And when two things share a slot, test both with the difference intact.** The
probe that cleared this pair in 3.24.1 used a 1x1 GIF as its photograph. An
image with no intrinsic size fills any box whatever the cascade does to it, so
the one property that distinguishes an `<img>` from a `<span>` was the one the
probe had removed. It measured the band and never the picture inside it.

### The shape of the error, which is worth more than the rule

The comment above that declaration read **"SAFE, BECAUSE OF WHAT IS NOT IN
HERE"** and then reasoned, carefully and correctly, about absolutely positioned
descendants: containment makes an element their containing block, so every
absolute box inside was traced to a nearer `position: relative` ancestor and the
RSVP modal was confirmed to live on `<body>`. All of that was true. None of it
was about size.

**One mechanism was verified, the element was declared safe, and the conclusion
was generalised to a mechanism nobody had checked.** That is the same move as
citing `git log -S` to answer "why does this still look like that": real
evidence, answering a question next to the one being asked. When a property
brings several behaviours with it, the checklist is the behaviours, not the one
that came to mind. Write down which ones you checked, so the ones you did not
are visible as a gap rather than covered by the word "safe".

The corollary for published numbers: **`readme.txt`'s minimum widths are
measured, not calculated.** 3.24.0 derived them by arithmetic and shipped a
220px sidebar minimum in the same build that made the thumbnail vanish below
270px. `.claude/embed-width-probe.html` renders every mode at eleven widths in
three kinds of parent, including a table cell and a flex item, and prints what
it measures. Run it before changing a published number.

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

---

## 7. Email

**An email is not a web page and the rules do not carry over.** Outlook on
Windows renders with Word's engine. There is no flexbox, no grid, no reliable
`border-radius`, and a stylesheet in `<head>` is unreliable across clients
generally. Everything below is enforced by `.claude/email-render-test.php`,
which builds all four messages and fails on any of it.

- **Tables for layout, inline styles for everything.** A div with modern CSS
  renders as a stack of full-width blocks in the client a large share of these
  recipients use. The only `<div>` in a message is the hidden preheader.
- **600px, stated twice**: `width="600"` for Outlook and `max-width:600px` for
  everyone else.
- **The banner is the header.** No yellow rule under it: the band would be a
  second header competing with the first, and yellow means "act on this", which
  a stripe does not. It carries real alt text, because many clients block
  images and the message has to read without it.
- **Every message has a plain-text alternative.** Not optional. A text-only
  client shows an empty message without one, and it scores badly with filters.
  The test checks that every fact in the HTML is also in the text.
- **One yellow button per message**, and `#373433` is the only text color on it.
  Links and the outline button are `#0E7680`. The palette is closed, and the
  test rejects any hex outside it.
- **A picture of the event does not go in.** Two large images push the date,
  the time and the address below the fold on a phone, which is the part
  somebody opens the email to re-read, and half the imported events have no
  photograph and would render a flat color block.

**Delivery is somebody else's job.** Everything goes through `wp_mail()` and
stops there. The site has a Postmark plugin that overrides `wp_mail()`, so no
SMTP layer, no transport and no second delivery path may be added here.

**The one thing that cannot be checked from here.** WordPress attaches a
text alternative by setting `AltBody` on the `phpmailer_init` action, and a
plugin that replaces `wp_mail()` outright and talks to an HTTP API never
constructs PHPMailer, so the action never fires. Whether a `text/plain` part
survives on this site is a fact about the transport, visible only in a
delivered message. Automation > Send a test email exists to answer it.

### Copy

Per the SFAF editorial style guide: short sentences, strong verbs, active
voice, second person, warm rather than stuffy, person first. Straightforward
beats creative in a subject line.

**SFAF uses the serial comma.** "Monday, Wednesday, and Friday". Two items take
no comma. The list-joining helpers are the place this is decided, not the call
sites: `SFAF_Recurrence::join_words()`, `SFAF_Sources::field_phrase()`, and
their mirrors `ucJoinWords()` and `phrase()` in `portal.js`. Change one, change
its pair, and the recurrence cross-check will tell you if you did not.
