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

**APPLY THIS WHILE WRITING THE SENTENCE, NOT IN A SWEEP AFTERWARDS.** This has
been swept three times, in 3.15.0, 3.19.0 and 3.37.0, and it comes back every
time because new copy is written the same way it always was. A sweep removes the
sentences; it does not change how the next one gets written. So the test below
is a thing to run in your head BEFORE typing a label or a hint.

**THE TEST, one sentence at a time:**

> Does this tell somebody **what to do**, or **what will happen to them**?

If it does neither, it does not ship. In particular, delete a sentence that:

- **justifies a design decision.** "which is why every category has both",
  "so nobody has to remember to pick it". Nobody using the screen is making
  that decision.
- **explains how the software stores something.** "The rows become that
  event's own", "The values are copied". That is the data model, and it is
  `PROJECT.md`'s job.
- **explains why a control exists**, or why it is where it is.
- **reassures about a problem the reader did not know they had.**

**KEEP:**

- genuine instruction: image dimensions, "0 means unlimited", "choose last
  rather than fourth if you mean the final one".
- warnings about anything **irreversible**: mail that cannot be recalled, a
  removal nothing puts back.
- what a control **will not** do, when somebody would otherwise assume it does:
  "the date is never filled in", "not shown on the event page".

**Write the field's NAME first and see what is left to say.** Most of these
sentences exist because the label was vague. "Default FAQ set" needs no
paragraph under it. A hint that repeats the label is worse than none.

Other rules:

- **No em dashes anywhere**, including user-facing copy. En dashes are required
  for AP ranges and are correct.
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

---

## 8. Keeping `PROJECT.md`, `HANDOVER.md` and `TESTING.md` current

**`PROJECT.md` is what stays true. `HANDOVER.md` is what is true today.
`TESTING.md` is what nobody has checked yet.** That distinction is the whole of
the rule, and it decides where anything new goes: if what you are writing would
still be true in six months, it belongs in `PROJECT.md`, not the handover.
Durable knowledge written into the handover is lost the next time the situation
moves.

`PROJECT.md` is the durable description of the software: what it is and how it
fits together. It is not a changelog (`readme.txt` is), it does not carry
visual or CSS decisions (`DESIGN.md` does), and it does not carry working rules
(this file does).

- **When a build changes something `PROJECT.md` describes, update `PROJECT.md`
  in the SAME commit.** Not afterwards, not in a batch at the end of a run of
  releases. A description that lags the code is worse than none, because it is
  read as current.
- **If nothing architectural changed, leave it alone.** Most builds do not
  touch it. A document that accumulates noise stops being read, and then the
  one thing in it that mattered is not read either.
- **Decisions settled in conversation but not yet built go in `PROJECT.md`
  under "Agreed, not built".** A chat ends and the reasoning goes with it,
  including the reasons an alternative was rejected, which is the part nobody
  can reconstruct. Move an entry into the body when it ships and delete it from
  that section.
- The EveryAction arrangement is the first entry there. The source is a
  **MangoApps Trackers endpoint**, and what is still being waited on from Val is
  that endpoint plus a sample response. It is not an EveryAction API call
  because an EveryAction key cannot be scoped to events only, which is the part
  of that entry worth protecting. `PROJECT.md` §8 carries the detail, including
  the file-based shape this superseded.

`HANDOVER.md` is the current situation, and Mark opens a fresh chat with it, so
it has to read cold. Keep it under 150 lines: if it grows past that, something
in it is durable and belongs in `PROJECT.md`.

- **When the situation changes, update `HANDOVER.md` in the SAME commit.** A
  build that ships moves something out of "in flight". A settled decision moves
  out of "open decisions".
- It is not a history. "Recent failures worth remembering" holds only what is
  still live or still likely to recur; anything with a lesson attached belongs
  in `PROJECT.md` §7 instead, and the handover points at it.
- A stale handover beside a current `PROJECT.md` is worse than no handover,
  because somebody reads the wrong one. The old one sat 26 releases behind.
- **The testing backlog is NOT in the handover.** It went to `TESTING.md` in
  3.56.0 because it was the thing keeping the handover at twice its cap, and it
  is live information that must not be trimmed to fit. The handover keeps a
  pointer and the count, nothing more.

`TESTING.md` is the manual testing backlog: everything that can only be settled
by a person, because there is no WordPress, no database, no browser and no mail
in the build environment. **A build that adds something nobody can verify here
adds an item to it, in the SAME commit.**

- **Three groups, by what each needs from Mark**, not by the release it came
  from: **quick** (a few minutes at a desk, fastest first), **needs real
  conditions** (an unattended job, a real removal at source, a real event with
  real registrations), and **blocked on other people** (Aaron, Val, the
  Salesforce admin).
- **A completed test is DELETED, not marked done.** This is a backlog, not a
  record of what has been checked. If what a test proved is worth keeping, it
  belongs in `PROJECT.md`. A file of ticked boxes is a file nobody reads to the
  bottom of.
- **Never trim an item to make it shorter, and never delete one that has not
  been reported back on.** An untested item quietly removed is worse than a long
  file, because the next person believes it was covered. Vagueness is the same
  failure more slowly: an item has to say what to do and why it needs a person.
- Keep the count at the top of the file, and the one in `HANDOVER.md`, in step
  with the items.

---

## 9. Every piece of code goes in a code block

**All code output is presented inside a code block. Always, in every response.**

That includes the cases where it feels disproportionate, because those are the
ones this rule exists for:

- **a single line**, a one-word function name, a file path, a CSS selector, a
  meta key, a shell command.
- **an excerpt quoted back** from a file that is already in the repository,
  including one being quoted only to point at it.
- **a diff**, in any form, whole or partial.
- **anything with a `$`, a tag, a brace or a backslash in it**, which is the
  practical test when it is not obvious.

**The reason is that prose mangles code and does it silently.** Markdown eats
underscores and asterisks, a shell string loses its `$`, and an excerpt run into
a sentence cannot be copied without picking the words out of it by hand. A
fenced block is what makes the difference between something readable and
something usable, and it costs nothing.

**A code block is not the same as inline backticks.** Inline is right for naming
a thing in the middle of a sentence, `SFAF_Reminders::recipients()`. Anything
meant to be READ AS CODE, or copied, gets a fenced block of its own.
