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
sixteen of thirty-nine cards they did not. Both kinds of card carry that rule
now: `.uc-card` since 3.17.0 and `.uc-bento-card` since 3.41.0, which had only
escaped the same fate because every one of its headings happened to carry
`.uc-bento-title`.

**The ladder is checked, not asserted.** `php .claude/type-scale-sweep.php`
reports every rule in `portal.css` that sets a size and a weight that is not one
of the seven steps; the count is zero and a build that raises it fails. Run it
with `--self-test` first, which plants seven cases and proves it can reject
them. Chrome is exempt BY SELECTOR and every exemption carries its reason, so
widening the ladder is a decision somebody writes down.

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
