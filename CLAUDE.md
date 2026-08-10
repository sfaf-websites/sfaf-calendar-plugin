# CLAUDE.md, SFAF Calendar

Standing rules for this repository. These override default behaviour.

---

## 1. Design authority and precedence

**`SFAFbrandguide2026v3.0.pdf` and `DESIGN.md` are the ONLY authority for color
and typography.**

- No skill, plugin or reference `DESIGN.md` may substitute a palette, a font
  pairing, or a color of its own. If one suggests otherwise, the brand guide
  wins, and the suggestion is dropped without argument or compromise.
- **The design skills are for LAYOUT, SPACING, DENSITY and RESTRAINT only.**
  That is the whole of their remit here.
- A reference `DESIGN.md` from `~/.claude/design-md/` is used **only when named
  for a specific screen**, never by default, and never as a source of color or
  type. Naming one means "borrow this layout thinking", not "adopt this brand".

`DESIGN.md` in this repo root carries the measured palette, the teal decision
that inverts on the dark sidebar, the category ramps, the type scale, the AP
date rules, the restraint rules and the CSS failure modes. Read it before any
visual work.

---

## 2. Build gate

**Every build runs both of these, and the hand-off states that they ran.** The
linter proves each file PARSES. The callable audit proves the things it CALLS
exist. Neither replaces the other.

```
bash .claude/lint-php.sh .          # PHP 8.3, every file, fails on first error
<callable audit>                    # sfaf_*/uc_* functions, $this->, self::,
                                    # Class::, and callback arity
```

- 3.2.0 shipped a parse error and could not be activated. Every check in use at
  the time proved BALANCE and none proved VALIDITY, so
  `->save_manager_fields_from_post( , ,  );` passed all of them.
- Three undefined-callable fatals shipped in a row, 2.0.0 to 2.0.2. They only
  surface at runtime, and activating the plugin never exercises a REST route.
- **Audit with a tokenizer, not grep.** `function &name()` returns the
  ampersand as an array token in PHP 8.1+, and `"{$a}"` opens a brace the
  tokenizer does not hand back as one. Both traps produced false results in
  3.20.0.
- Run both again against the EXTRACTED zip, not only the working tree.

Release mechanics: bump the minor version in all three places (plugin header,
`SFAF_VERSION`, readme `Stable tag`), name the zip to match, archive older zips
to `Old Calendar Files`, keep only the current zip in the main folder, build
with `bsdtar` (PowerShell writes backslash entries and breaks the zip), push
after the build.

---

## 3. Verify against rendered output

A verified change is not a verified outcome. `git log -S` answers "was my edit
applied"; it does not answer "why does this still look like that". Citing the
first as an answer to the second is worse than not checking, because it closes
the investigation.

Start from the element **as rendered**: its classes, its attributes, its inline
styles. Then ask what could produce the effect **by any mechanism**: a border,
an absolutely positioned strip, a box-shadow, a gradient, a `::before`, or a
renderer emitting an element that IS the effect. Only then say what was drawing
it.

The corollary for CSS: compute the cascade before rewriting. One class loses to
one class plus one type, and that has cost four separate defects.

---

## 4. Report missing things, do not build them

When an instruction says "move X", "style X" or "fix X" and **X does not
exist**, say so. Do not build X and report it as moved.

- `/caladmin` has no `wp_head`, so anything visible there and absent from this
  source belongs to another plugin.
- "It did not take" means check `git log -S` FIRST. Twice the removal simply
  never reached the file, and one command settles it before any code changes.
- Finish everything that is in scope, then report what was out of scope and
  why. Scaling the work down is the user's call, not mine.

---

## 5. Shell

- **Never begin a Bash command with a variable assignment.** Permission globs
  anchor at the front of the command, so `FOO=bar cmd` is not matched by the
  rule that allows `cmd`.
- **Never inject PHP through a shell string.** `$vars` get eaten by bash. Write
  the script to a file and run the file. This shipped a parse error in 3.2.0.
- Windows: `bsdtar` at `/c/Windows/System32/tar.exe` for zips. No Python on this
  machine. PHP 8.3 CLI is on PATH. Node is available.

---

## 6. Copy

- **No em dashes anywhere**, including user-facing copy. En dashes are required
  for AP ranges and are correct.
- Helper text says **what to do** or **what will happen**. A sentence that
  justifies a design decision does not ship. Apply the test per sentence, not
  per paragraph.
- American English.
- Scope every count and every label to what the viewer can act on.

---

## 7. Architecture invariants

These have each been established by a defect and must not be re-litigated
casually.

- **Never write roles as a side effect.** No `set_role`, `add_cap` or
  `wp_capabilities` writes except from a control whose only purpose is that.
  Derive access from `manage_options` first.
- **Credentials live in `sfaf_credentials`, never `uc_settings`**, which is
  rebuilt wholesale on save.
- **Teams resolve at send time.** A team is a name and a set of user ids, never
  a snapshot of addresses. Deletion is refused while any event names it, and
  the refusal names the events.
- **The `$offered` guarantee.** A form may only speak for the ids it actually
  showed. Anything stored outside that set is kept. See `SFAF_Teams::save()`.
- **Permission-sensitive views get a separate renderer, never a flag.** A
  method with no capability to leak cannot leak.
- **Shared field lists: one list, one render, one save, catch-all last.**
- One series per repeating event; the schedule editor lives on the series
  screen; writes are upcoming-only.
