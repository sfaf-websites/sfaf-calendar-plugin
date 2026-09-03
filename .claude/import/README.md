# One-time import from The Events Calendar

Not part of the plugin. Not in the zip. Run once against resources.sfaf.org,
then delete the folder from the server.

## What decides what

**Mark's list is the structure.** It is written out by hand in `build-plan.php`,
organizer by organizer, and it is the only thing that decides which series exist
and which events each one holds. The four WXR exports in the project root are a
source of detail for the rows that match it, and nothing more: description,
start and end times, venue, organizer, featured image. Where they disagree the
list wins. Where the list has no match, the export row is dropped.

Series and event are not one to one. Coffee Social, PROP, Mobile Health Sites
and Strut community events each hold several distinct events.

## The files

| | |
|---|---|
| `lib.php` | WXR reader and a summariser for TEC's serialised `_EventRecurrence` blob. |
| `build-plan.php` | **Mark's list, by hand**, joined to the export. Writes `plan.php`. |
| `plan.php` | Generated. The whole import as one data array. Do not edit it; edit `build-plan.php` and re-run. |
| `export-rules.php` | Every distinct recurrence rule the export holds for a matched title, with the rows it came from. The evidence for which schedules are live. |
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

## Two things it will not do

It **never creates a category.** Every category in caladmin carries a brand
color and an icon somebody chose; one created here would carry the defaults and
look like a mistake on the calendar. An event whose category is absent is
created without one and named in the report.

It **never renames or re-addresses** an organizer or venue it finds, and it
leaves every existing series exactly as it is, image and FAQ set included.
