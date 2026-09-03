# One-time import from The Events Calendar

Not part of the plugin. Not in the zip. Run once against resources.sfaf.org,
then delete the folder from the server.

## What decides what

**Mark's list is the structure.** It is written out by hand in `build-plan.php`,
organizer by organizer, and it is the only thing that decides which series exist
and which events each one holds. Series and event are not one to one: Coffee
Social, PROP, Mobile Health Sites and Strut community events each hold several
distinct events.

**Three sources supply the detail, and they rank:**

1. **What Mark supplies directly**, written into `build-plan.php` as `desc`,
   `time`, `pat` and `on`. Most recent, and it wins over everything.
2. **The Stonewall Project group info sheet**, current as of 2026-27. It
   supersedes the export for every Stonewall series.
3. **The export**, and only the **most recent occurrence** of a title.

Where a description or a schedule comes from 1 or 2, the export's is not taken.
The export still supplies what neither mentions: the featured image, and the
times of the three PROP groups. Each event records which source its description
came from, in `from`.

**Rule 3 is not a preference, it is a correction.** El Salon's first twelve
export rows say 09:30-11:00 on a Wednesday and its most recent says 12:30-14:00
on a Thursday; Express Yourself's early rows are Thursdays and its most recent
is a Monday. The Stonewall sheet agrees with the recent rows in both cases, so
the first row in the file is not merely the oldest fact in it, it is the wrong
one.

## The files

| | |
|---|---|
| `lib.php` | WXR reader and a summariser for TEC's serialised `_EventRecurrence` blob. |
| `build-plan.php` | **Mark's list, by hand**, joined to the export. Writes `plan.php`. |
| `plan.php` | Generated. The whole import as one data array. Do not edit it; edit `build-plan.php` and re-run. |
| `export-rules.php` | Every distinct recurrence rule the export holds for a matched title, with the rows it came from. The evidence for which schedules are live. |
| `trashed-only.php` | Which titles exist in the export **only** as trashed or draft rows. The 3.69.0 report said five names on Mark's list matched only trashed rows; it is one, Brothers Who Read, and this is the check that settles it. |
| `schedule.php` | Where a generated schedule starts. No WordPress, no database. |
| `dryrun.php` | The plan run through `SFAF_Recurrence` itself, with WordPress stubbed. Carries a self-test. |
| `sfaf-tec-import.php` | The importer. Runs on the site. |

## Running it

Here, before anything else:

```
php .claude/import/dryrun.php --self-test
php .claude/import/dryrun.php
php .claude/import/build-plan.php      # only after editing build-plan.php
```

On the site, with `sfaf-tec-import.php`, `plan.php` and `schedule.php` together
in one folder inside the WordPress install:

```
wp eval-file sfaf-tec-import.php report
wp eval-file sfaf-tec-import.php clear
wp eval-file sfaf-tec-import.php import
```

or, logged in as an administrator:

```
.../sfaf-tec-import.php?mode=report
.../sfaf-tec-import.php?mode=clear&confirm=CLEAR
.../sfaf-tec-import.php?mode=import&confirm=IMPORT
```

`report` is the default and writes nothing. It is the half of the dry run that
can only happen on the site: which organizers, venues, categories and series
caladmin already has. Read it before `clear`.

`clear` moves every uc_event to the **trash**, not to deletion, and counts
before and after. `wp_trash_post()` is what the plugin's own remove actions use,
so a wrong call is one click to undo.

Everything `import` creates is a **draft**.

## Nothing it creates can cause mail to anybody

The calendar has not rolled out. An address on an event's notification list
means a real person starts receiving registration alerts and pre-event summaries
the moment that event is published, and this creates 273 drafts for somebody to
publish in bulk.

**The address it would otherwise add is one nobody typed.**
`SFAF_Reminders::notify_entries()` reads four sources: `post_author`,
`_uc_notify_users`, `_uc_notify_emails`, and the event's teams. This import
writes none of the last three. But `wp_insert_post()` defaults `post_author` to
whoever is logged in, and the creator is on the list unless the event says
otherwise, so running it from a browser would put the administrator who ran it
on all 273 lists.

**And the opt-out does not travel to an occurrence.** `SFAF_Recurrence` copies
`post_author` onto every generated occurrence, and its `$copied_meta` carries
none of the three notification keys. That is right for a manager building a
group by hand and wrong here, so `sfaf_import_silence()` runs on the seed **and
on every date generated from it**.

**The check is the plugin's own resolver, not an assertion about it.** Every
post ends by asking `SFAF_Reminders::notify_list()` who is left, and a non-empty
answer is a reported failure. `no-mail-check.php` proves the same thing at build
time, and carries a self-test that plants five violations.

**Addresses inside descriptions stay exactly as written.** They are text on a
page, no send path reads them, and Mark's team reviews every description during
the audit.

## Two things it will not do

It **never creates a category.** Every category in caladmin carries a brand
color and an icon somebody chose; one created here would carry the defaults and
look like a mistake on the calendar. An event whose category is absent is
created without one and named in the report.

It **never renames or re-addresses** an organizer or venue it finds, and it
leaves every existing series exactly as it is, image and FAQ set included.
