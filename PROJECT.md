# PROJECT.md, SFAF Calendar

What this software is and how it fits together. The things that stay true
between sessions.

This is **not** a changelog. `readme.txt` is the changelog and is authoritative
for what shipped when. This document does not repeat `DESIGN.md` (color,
typography, layout, CSS failure modes) or `CLAUDE.md` (standing working rules,
the build gate, shell rules). When something here contradicts one of those,
those win in their own remit and this file is wrong and should be fixed.

Current version at last update of this file: **3.32.0**.

---

## 1. Architecture

### Two sites, one plugin

The plugin is installed on **resources.sfaf.org** only. It registers the
`uc_event` post type, the `/caladmin` front-end portal, the public
`[sfaf_calendar]` shortcode, and one REST route that serves the embed.

**sfaf.org runs on a locked platform where nothing can be installed.** No
plugin, no PHP, no theme file. sfaf.org gets the calendar by pasting an embed
snippet that loads `embed.js` from resources and calls the REST route
cross-domain. That single constraint explains most of what follows.

### Why the back link is a referrer with a fallback

Event pages are served from **resources.sfaf.org**, because that is where the
post type lives. But the calendar people actually browse is a page on sfaf.org
carrying a shortcode, or an embed of it on some third site.

So "back to all events" cannot be `get_post_type_archive_link()`: that is a
resources URL, and a visitor who clicked through from sfaf.org would land on a
site they have never seen and that is not a public surface.

The resolution order is **referrer, then a configured URL, then the archive**
(`sfaf_calendar_return_url()`, over `sfaf_calendar_referrer()` and
`sfaf_calendar_home_url()`, in `includes/sfaf-template-functions.php`). The
referrer is the only thing that knows *which* calendar page they came from, and
there can be several. The
configured URL in settings covers a shared link, a search result or a bookmark,
where there is no referrer at all. The archive is a last resort so the link is
never broken, not because it is a good destination.

The plugin does not guess which page holds the shortcode. Searching
`post_content` would find drafts, revisions and the wrong page as readily as the
right one.

The referrer is attacker-supplied input and is validated as such: it must parse,
must be http or https, must be this site's host or the configured calendar
host or a host sharing the last two labels of this site's domain, must not
itself be an event page, and the URL is **rebuilt** from the validated parts
rather than passed through. That is what stops it becoming an open redirect.

**AN ORIGIN IS NOT A PAGE, and in practice that is the common case rather than
an edge one** (3.66.0). Because the event page and the calendar are on different
hosts, **every real click through to an event is cross-origin**, and every
current browser defaults to a referrer policy of
`strict-origin-when-cross-origin`, which sends the **origin only**:
`https://sfaf.org/`, with no path. Nothing can recover which calendar page that
was, because it was never sent.

That passed every check above. The host is ours, the scheme is https, and a
path with no segments is not an event page, so the function returned
`https://sfaf.org/` and **"All Events" took a visitor who was reading a calendar
to the site root**. It looked like a lost referrer or an unfilled setting and
was neither: the referrer arrived, was valid, and named nothing. A referrer with
no path is now treated as no referrer, so the configured URL answers instead.

> **The code half does not work alone.** With `calendar_home_url` empty the
> fall-through is the archive on resources.sfaf.org, which is not a public
> surface. **Filling that setting in is the other half of this fix.**

> **A related quirk, recorded rather than changed.** The "is this an event page"
> test applies THIS site's archive base to any allowed host, so a calendar page
> at `sfaf.org/events/whatever` is refused as though it were one of our event
> permalinks. The consequence is mild, because it falls through to the
> configured URL, which is a calendar page anyway.
> `.claude/self-built-pages-test.php` asserts it so it is a decision somebody
> can find rather than a surprise the next person debugs from scratch.

### Event links open a new tab, and rel="noopener" is load-bearing

**Every link from the calendar to an event opens a new tab** (3.67.0): the month
grid day link, the sidebar row, the list card's picture and title, the View
event button, the compact card, and the two "in this series" lists. The calendar
renders inside somebody else's page, so a card that navigated in place would
take a visitor away from whatever they were reading, and closing a tab is how
they get back.

- **`_blank`, and never `_top`.** `_top` replaces the whole window the embed is
  sitting in, which is somebody else's page.
- **`rel="noopener"`, and deliberately NOT `noreferrer`.** noopener is the
  security half: it stops the opened page reaching back through
  `window.opener`. **noreferrer also suppresses the Referer header, which is
  exactly what the section above reads** to work out which calendar somebody
  came from. Adding it would break "All Events" on every event opened from a
  card. `sfaf_action_button()`'s `external` flag keeps `noreferrer`, because
  that one goes off this site entirely; event links take `new_tab` instead.
- **Every link a person can reach says so**, in a visually hidden
  `(opens in a new tab)` inside the link, which is the pattern
  `sfaf_external_marker()` already established here. The list card's picture
  link is the exception and is `aria-hidden` with `tabindex="-1"`: it has no
  name to add a sentence to, and the title beside it is the same destination.
- **One place decides both**, `sfaf_new_tab_attrs()` and `sfaf_new_tab_note()`,
  for the reason `sfaf_event_link()` is one place: a rule written into three of
  four renderers is a display mode that behaves differently for no visible
  reason. `.claude/embed-modes-test.php` counts the anchors and fails if one
  loses its attributes or gains a `noreferrer`.

**The back link is unchanged.** Carrying a return address on the embed is a
separate piece of work and nothing here begins it.

### Pages that build their own document

Four surfaces write their own `<!DOCTYPE>` and their own `<head>` and call
`wp_head()` **nowhere**: caladmin, the staff request form, the community
submission form, and the notice page the follow links and the registration
cancel link land on. Anything an ordinary WordPress page gets for free, these
get only if their own head asks for it.

**This has cost the same thing twice.** They had no stylesheet until 3.44.0, and
no favicon until 3.66.0, for one reason. The comment beside caladmin's icon
links said the public forms did not need them because they were "rendered by the
theme through `wp_head()`", which was true of nothing and is why nobody looked
again.

**The plugin owns the favicon.** `public/images/favicon-caladmin.*` is bundled
with the code and drawn by `.claude/build-favicon.js` from
`sfaf_icon_paths()['calendar']`, so it travels with the plugin and cannot be
deleted from the media library by somebody tidying up. It is not the site's icon
and not the theme's. `sfaf_favicon_links()` is the one declaration; a fifth
self-built document calls it rather than copying three `<link>` tags.

### Why no new REST route can be added

`SFAF_Embed::is_embed_request()` compares `$request->get_route()` against the
namespace and route **as an exact string**. Every CORS mechanism in the embed
hangs off that comparison: the preflight answer, the response headers, and the
serve fallback.

A second REST route would therefore match **none** of them. It would work
perfectly in local testing and on resources itself, and be blocked by the
browser the moment sfaf.org asked for it, which is the one place it has to
work.

**So new embed parameters go on the existing route.** This has already been the
answer three times: the sidebar heading, the category selection, and the cron
nudge. The cron nudge is on `admin-ajax` rather than REST for exactly this
reason.

### Why several layouts stack, permanently

The sfaf.org template is locked at roughly **770px** of content width and
cannot be widened. It is not a setting on our side.

Anything whose two-column layout needs more than 770px will stack on sfaf.org
forever. That is a fact to design around, not a bug to fix:

- The combined mode goes side by side at **864px**, so on sfaf.org it is always
  the month grid above the sidebar card.
- The month grid drops its cell entries at 560px of its own column. Side by side
  at 770px would hand it 413px, so side by side would be *worse* than stacking.
  Flex bases are therefore set from the breakpoints they must clear, not chosen
  for looks.

**The rule that came out of this: side by side must never be worse than
stacking.** Check any new flex basis against every container query the item's
own contents use.

Embeds measure **their own column** with `@container`, not the window, because
an embed in a 280px sidebar inside a 1440px window is 280px wide and every
`@media` query about the window is a lie there.

### Naming, and the branches

**Data names stay `uc_*`** for backwards compatibility: the post type, the
taxonomies, the meta keys, the options. **Code identifiers are `sfaf_` /
`SFAF_`.** The two never converge, and neither is renamed to match the other.

Branches:

- **`production-2.0`** is the active line: the real 1.7.5 production baseline
  plus everything since. This is where work happens.
- `main` holds the original two plugins as received (unified-calendar 1.0.0 and
  satellite 1.0.9). Stale, and kept as the record of what arrived.
- `embed-system`, `brand-guide`, `phase-1-consolidation` are old 1.0.0-line work
  kept for cherry-picking. **Do not delete them.**

---

### The public event request form

**One page, no account, and not a restricted caladmin.** Most staff will never
manage events; they want to ask MarCom to add one. `SFAF_Request` owns the whole
of it: the link request, the form, the validation, the insert and the two
emails.

> **A cut-down portal would have been the expensive answer.** Every caladmin
> screen would grow a second set of permission questions, and "what can a
> requester see" would be answered in forty places instead of one file. A
> separate surface has one answer, in one place, and it is this file.

**Access is a token in a link, and the token is the only credential.**
`SFAF_Reminders::new_token()` is the same generator the cancel links use, so
there is one answer to how strong it is. It lives 60 minutes.

- **Stored as a transient, keyed on a hash of the token.** No row, because
  nothing about somebody who has not submitted anything is worth keeping; no
  schema change and no `SFAF_DB_VERSION` bump for a value that lives an hour;
  and the options table never holds a usable credential.
- **Requesting a link renders one page whatever happens.** Sent, rate-limited,
  or dropped for a mailbox that does not exist: identical. A different screen
  for any of them answers "is that a real address here". A non-sfaf.org address
  is refused out loud, because that reveals nothing about a person.
- **The domain test is `sfaf.org` or a subdomain of it**, so `notsfaf.org` and
  `sfaf.org.example.com` are both refused.

**No REST route, and this is the reason to keep it that way.**
`is_embed_request()` matches an exact string, so a new route would fail CORS
from sfaf.org. This hangs off a front-end query var on `template_redirect`, like
the cancel link and the .ics endpoint, which also means **no rewrite rule and no
flush**, so it works on an install updated by overwriting the folder.

**Everything from the browser is re-derived.** Every term id must resolve to a
term that exists; dates are parsed and compared back to what they parsed from,
so `2026-02-30` cannot roll into March; strings are capped and stripped of
markup; and the picture must be an attachment, be an image, and already be in
the calendar folder. **The status is named in the code**, never read from the
form, and `post_author` stays 0 because nobody logged in made it.

> **Rate limiting takes two subjects, not one.** Per address and per client on
> the link, per token and per client on the submission. Without the per-address
> limit one inbox can be filled; without the per-client limit every address in
> the organisation can be hit once each. Neither closes it alone.

**The picture chooser is not `wp.media`, and cannot be.** The frame needs a
logged-in user with `upload_files`, so on this page it would not open. It is
radio buttons over the same `SFAF_Media_Folder` query the editor's picker uses.
**Picking one sets the featured image**, because everything in that folder is
already an approved picture. Sending one of your own does not: see "Files from
people with no account" below.

**It is a picker rather than a grid** (3.65.0). Every picture in the folder was
drawn at once, which is fine at a dozen and unusable at two hundred. The closed
control shows the picture chosen or says none is; opening it gives a search box
and a scrollable list, one row per picture, each with **its file name visible**.
Two photographs of the same event look alike at 64px and the file is often the
only thing that separates them, and it is what the search matches. Search is not
needed at today's number and was built anyway, because retrofitting it into a
list control later is more work than including it now.

> **WITHOUT JAVASCRIPT IT IS STILL A COMPLETE CONTROL, and on this page that is
> not a nicety.** The form is reached by a link and used by staff who are not
> logged in to WordPress, so a control that posts nothing without scripting is a
> form somebody cannot finish, and nobody finds out from a screenshot. It is a
> native `<details>` full of radio buttons: the browser opens and closes it on
> its own and the radios post whether it is open or shut. Script adds the
> filtering and closes the panel on a choice, and creates none of it. The search
> box is hidden until portal.js can act on it, because a box that cannot filter
> is a control that lies, which is the same reason the notification picker's
> tabs stay hidden until they can be switched.
>
> It wears `.uc-picker`, the disclosure the notification picker and the teams
> screen already use, and it filters through the same `initFilterLists()`. What
> is new is the difference between a row of people and a row of pictures.
> `.claude/request-picture-picker-test.php` renders the control and parses what
> came back.

**The requester names the team that should be able to edit it** (3.66.0). Teams
only, never individuals: membership resolves at read time, so adding somebody to
a team hands them every event that team owns and removing them takes it back,
while a typed name or address is a string that is correct on the day it is typed
and nobody's job afterwards. The requester sees team **names** and never who is
on them.

- **The cap is `SFAF_Teams::MAX_PER_EVENT`, asked rather than restated.** A
  second number here would be a second answer to the same question, free to
  disagree with the one caladmin enforces.
- **Over the cap is an error, not a silent trim.** With scripting off nothing
  stops a third box being ticked, and dropping one quietly would leave the
  requester believing they had said something they had not. The ticks are kept
  so the form comes back showing what they chose.
- **It is written through `SFAF_Teams::set_access_for_event()`**, the call the
  portal makes. `_uc_event_teams` is never touched from this file, and
  `request-form-test.php` asserts that absence.
- **It is written on a PENDING row, which is the point.** Access resolves at
  read time, so the named team can open the request in caladmin before anybody
  approves it.

> **Showing team names on an unauthenticated page is safe because of the gate
> that is already there.** The form is only ever rendered after a token sent to
> a verified sfaf.org mailbox has resolved, so anybody who can see the list has
> already proved an sfaf.org address. Mark confirmed team names may be shown
> once that is true.

**Hierarchy on both forms comes from the Subhead step** (3.66.0). Both forms
used the type scale and the scale is not flat; they reached for the **wrong
step**. A heading over a group of fields was set at `.uc-field-label`, 13/600,
which is the step the labels of the fields inside that group are already at, so
a heading and the thing it headed rendered identically and twenty fields read as
one column. Subhead, 16/600, sat unused between Field label 13/600 and Section
20/700, and `.uc-field-group-title` on a `<legend>` is that pair, declared once.
**No new size exists.**

**A section is one thing, spelled one way** (3.68.0). Every section on both
forms is a `<fieldset class="uc-form-section-group">` with a
`<legend class="uc-field-group-title">`, and **no section carries `.uc-field`**.
There were three spellings before that: an `<h2 class="uc-form-section">`, a
`<fieldset class="uc-field-group">` and a `<fieldset class="uc-form-section-group">`.

> **THE CASCADE FAULT THIS FIXED, AND IT IS THE RECURRING ONE.** Two of the
> staff form's six sections carried `.uc-field` as well, and
>
> ```
> .uc-request-card fieldset.uc-field   (0,2,1)   border: 0; padding: 0
> .uc-form-section-group               (0,1,0)   border-top: 1px; padding: 18px 0 0
> ```
>
> One class loses to one class plus one type. So the picture section and the
> team section drew no divider and had no top padding while the four `<h2>`
> sections beside them did, and the form read as an unbroken column of white
> boxes. **The remedy taken was to stop the reset matching**, by removing
> `.uc-field` from the sections, rather than raising the section rule: that
> class was written for a fieldset that IS one field, which is the Categories
> checkboxes, and a section never was one. `request-form-test.php` fails by
> name if a section takes it back.

**A section is a neutral panel, and that is what makes it allowed.** One tint,
one hairline, the same on every section of both forms. DESIGN.md's decoration
rule keeps colour where colour is the sole carrier of information; a surface
that groups carries none, so it must not look as though it does. It cannot be
read as a state, a category or a warning because every section has it.

**A section declares its own `display`, and that is not a detail** (3.68.1).
`.uc-form-section-group` is `display: flex; flex-direction: column`, which
stacks the legend above the fields inside the padding and keeps the panel's edge
unbroken. **There is no float anywhere in these rules, on purpose.**

> **THE SAME FAULT ONE LEVEL ALONG, AND IT SHIPPED.** A legend is placed inside
> its fieldset's top border by every browser, so 3.66.0 floated it to lift it
> out. **That float never ran:** the only two fieldsets carrying the rule also
> carried `.uc-field`, which is `display: flex`, and **float computes to `none`
> on a flex item**. 3.68.0 then took `.uc-field` off the sections to stop the
> reset above reaching them. The reset was `border`, `padding` and
> `margin-inline`, none of which mattered here; **the class carrying it was also
> the section's layout mode**, and removing it switched the float on for the
> first time.
>
> **A live float and the first field could not share a line.** Every first child
> of every section on both forms is `.uc-field`, `.uc-field-row`, `.uc-check` or
> `.uc-check-grid`, and all four are flex or grid containers. Each establishes
> its own formatting context, so it refuses to overlap a float and is placed
> beside it, and a flex item's `min-width: auto` stops it shrinking away. The
> heading rendered on the left with the first field in a roughly 40px column at
> the right. **Only the first child sits at the float's vertical position**,
> which is why every field after it was correct and made the fault look like a
> property of one field rather than of the section.
>
> **The remedy is to declare the layout mode rather than inherit one**, so a
> float on a child is impossible instead of merely inert.
> `.claude/section-layout-test.php` fails by name if a legend is floated again
> or if the section stops declaring a `display` of its own. It proves the rules
> and no geometry; `TESTING.md` 1.39 is the other half.

> **When a class is removed to escape a rule, ask what else that class was
> doing.** The reset was the reason and the `display` was the casualty, and
> nothing about the reset's three properties would have led anybody to it.

**The series is the first question, and it is what the picture chooser answers**
(3.68.0). It was the fifth, after the description and the categories, so a
requester met the picture chooser before being asked the thing that decides it.

- **Choosing a series shows that series' photo** on the picker's "nothing
  chosen" row and on the closed control, **only while no picture is chosen**.
  Picking one leaves both alone.
- **Nothing is copied.** The radio still posts 0. An event in a series with no
  picture of its own already resolves to the series photo every time it is
  displayed, so a copy would be a value that goes stale when the series photo
  changes, and an attachment id the server would have to check against the
  calendar folder again. `create_event()` has said this since the form existed.
- **It is not the code New Event runs, and cannot be.** caladmin's
  `initSeriesPrefill()` fills six fields through two hidden inputs and a
  wp.media preview; this page has no logged-in user, so it has neither, and its
  picture control is radio buttons over the calendar folder. The two screens
  share the QUESTION, the RULE that a filled field is never overwritten, and
  the data in `SFAF_Series::prefill_data()` and `SFAF_Series::image_url()`.
  They do not share a control, because they do not have one.

**A title WordPress invented is not a title.** WordPress sets an attachment's
title from its filename on upload, so a picture nobody titled comes back as
"harm reduction 2026 a" and reads as a caption. Given the file that is an exact
question rather than a guess: drop the extension, turn dashes and underscores
into spaces, compare. `looks_like_a_filename()` asks that first and keeps its
two older guesses as the net for a title somebody typed that reads like a file.
It was guesses alone until 3.65.0, and neither of them caught the shape the
function's own comment used as its example.

**The description is rich text from 3.46.0, and is sanitised on the way in.**
3.43.0 stripped markup from everything here and said why. Staff behind an
emailed link is a lower risk than that rule was written for, so the description
alone is prose now. What arrives is still a POST body whoever the form was drawn
for, so it goes through `SFAF_Submissions::prose()` rather than being trusted
because an editor drew it.

**Repeating is recorded in words and never as `PATTERN_META`.** Generation is a
creation-time action that makes N independent posts, so half-filling the pattern
from an unapproved request would put fifty-two events one button press away. The
approver reads the sentence and sets the schedule on the screen built for it.

**A request is marked in the queue, not given a queue.** Pending has meant "an
import that needs an image and a description"; a request arrives filled in and
needs reading. The row carries a badge and the requester's name, and the editor
carries a read-only panel with who asked, when, the repeat sentence and the
notes. **Two queues is one queue somebody stops checking**, and that held when a
third kind arrived: a community submission is a third badge, not a third queue.

> **WHAT KIND A ROW IS, IS ASKED RATHER THAN INFERRED.** The badge tested
> `'' !== _uc_request_email`, and that stopped being an answer the moment a
> second form wrote an address to the same key: every community submission would
> have read as a staff request. `SFAF_Submissions::kind()` is the one place that
> decides. It answers import first, because that is decided by another subsystem
> entirely and a row can only be one thing, and it falls back to the address
> test last, which is what keeps requests made before the marker existed badging
> correctly with no migration.

**Nothing is sent after the confirmation.** No reminder, no approval notice.
Chasing is a person's job, and a system that nags on somebody's behalf teaches
people to filter it.

**NOTHING DECIDES WHO COUNTS AS AN APPROVER EXCEPT THE ADMIN ROLE**, and
there deliberately is no second list. Whoever holds Admin on the Users screen is
notified about requests and can approve them; adding somebody means giving them
Admin, and removing them means taking it away. A separate list of approvers
would be a second answer to the same question, free to disagree with the first
and certain to fall out of date.

### The repeat control is one control, and a request captures it without arming it

**BOTH FORMS THAT ASK "DOES THIS REPEAT" ASK IT THE SAME WAY** from 3.72.0.
`SFAF_Recurrence::render_control()` draws it and `SFAF_Recurrence::from_post()`
reads it back, and caladmin's New Event and the public staff request form both
call those two. Neither owns a copy.

**WHAT THE REQUEST FORM HAD INSTEAD, AND WHY IT WAS NOT ENOUGH.** Five options in
a select: it happens once, every week, every two weeks, every month, something
else. **"Every week" names no day.** So a requester picked a frequency, then an
end date sitting under a frequency that had not said what it was repeating on,
and whoever approved it read the notes to find out. "Tuesdays and Thursdays"
could not be said at all, and "the first Monday of the month" fell into
"something else".

**WHY IT IS ONE RENDERER RATHER THAN TWO THAT AGREE.** Two copies of a control
that writes a recurrence pattern would differ in **what somebody is ALLOWED TO
SAY**, and nothing would notice until a request arrived expressing a schedule
caladmin cannot store. That is the reasoning `SFAF_Rich_Text` was made a class
for, applied to the one other control in this plugin with its own grammar. The
reader moved with the renderer and had to: a second parser for the same field
names is free to disagree about what `repeat_mode=weekly` with no days ticked
means, and the disagreement surfaces as wrong dates at approval.

**THE PATTERN IS CAPTURED AND NOT ARMED. THIS IS THE PART TO PROTECT.**

A request stores what `from_post()` produced under **four keys of its own**,
`_uc_request_pattern`, `_uc_request_pattern_until`, `_uc_request_pattern_limit`
and `_uc_request_pattern_dates`. It **never** writes
`SFAF_Recurrence::PATTERN_META`, and nothing on that path generates a post.

> **WHY THE KEY NAMES ARE THE GUARANTEE.** Writing the real key would make an
> unreviewed request indistinguishable from a schedule a manager built. Every
> reader of `PATTERN_META` would start answering yes about a row nobody has read,
> and the schedule screen's generate button would be one press from creating a
> year of posts for an event still awaiting review. **An unapproved request must
> never put fifty-two events one press away.**

**THE APPROVER MEETS IT AS A STARTING POSITION.** Opening the event in caladmin
fills the control in from those four keys, with the summary saying how many dates
it would make, and **pressing Save is what creates them**. The prefill is offered
**only while the event has no schedule of its own**: the moment it carries a real
pattern or a recurrence group, it returns nothing. A prefill that kept
reasserting the requester's answer would silently undo an approver who had
deliberately changed it, on every reload, which is worse than not prefilling.

**AND HIDING THE FIELDS ON "IT HAPPENS ONCE" NEEDED NO CODE**, which is itself
the answer to why the old select did not do it. The shared control's script has
always hidden every panel when the mode is Never. A bare select and a bare date
input had nothing to hide and nothing hiding them.

### FAQs on the staff request form, and why the community form has none

**The staff form offers a FAQ set picker and a repeater, and they combine**
(3.52.0). Either is optional. When both are used the **set's questions come
first and the requester's are appended**, with no interleaving, and a question
already in the set is not asked twice: `SFAF_FAQ_Sets::fingerprint()` decides
that, the same comparison `apply()` uses, rather than a second rule on the form's
side.

**A SET IS COPIED, NOT LINKED.** That is `SFAF_FAQ_Sets`' existing decision
followed rather than a new one, and it is the answer to the question people
actually ask: **editing a set later changes nothing already submitted.** The
answers drift year to year, so a link would mean one edit silently rewriting
past events that were correct at the time. Nothing on a submitted event records
which set it came from, which is also what makes deleting a set harmless.

**The cap is `SFAF_FAQ_Sets::MAX_ROWS`, read from that class**, so the combined
list is bounded where every other FAQ list is.

**A set's rows are re-cleaned through the anonymous rule.** They were authored in
caladmin under `wp_kses_post()`, which is wider than anything a public form
accepts. Running them through `SFAF_Submissions::prose()` keeps one answer to
"what may arrive from this form" whatever the text's origin, and costs nothing:
the short toolbar cannot produce anything that list rejects. **Nothing was
widened to carry a set across.**

> **THE COMMUNITY FORM DELIBERATELY HAS NO SET PICKER, AND SIMPLICITY IS NOT THE
> REASON.** Listing the sets would tell a stranger what programmes this calendar
> runs. Set names are written by managers, for managers, about recurring
> programming, and this calendar carries HIV, substance use and trans health
> programming, so **the names are themselves the disclosure**.
>
> It is the same refusal that form already makes: an address naming an unknown
> series says the link is not right and does not list the series that exist. A
> picker would give away through one control what the other is careful not to.
>
> **The two forms differ because their readers do.** The staff form is behind an
> emailed token to an sfaf.org address, so whoever reads it already works here.
> Do not add the picker to the community form for consistency; the note in
> `SFAF_Submit` says so where somebody would otherwise add it.

### Files from people with no account

**The riskiest thing in the plugin, handled in one place both forms call.**
`SFAF_Uploads` is the whole of it. 3.43.0 refused to carry an upload at all and
was right to; the risk is handled rather than avoided now, and the reasoning
that made it a refusal is what the checks are built from.

**A submitted file is a WORKING COPY and is never the published image.** It
lands in `calendar-submissions/`, a different folder from the `calendar/` one
the pickers offer, and it is offered by no picker at all. The pending row shows
it, approval does not copy it, and the published picture is still chosen at
approval from the approved folder. **Nothing points at the submissions folder
permanently**, so it can be emptied at any time; a row whose file has gone shows
no thumbnail and nothing else changes.

> **The two folders must not overlap, and that is why the name is not a child.**
> `calendar-submissions/` sits beside `calendar/` rather than inside it. A
> submissions folder underneath would fall inside `SFAF_Media_Folder`'s own
> anchored prefix, so every raw upload would appear in the caladmin picker,
> which is the one thing the split exists to prevent. Both prefixes are anchored
> at the front, so `photos/calendar-submissions/` is neither of them.

**The order of the checks is the design**, and `store()` numbers them 1 to 13.
Each runs only on input the previous one has narrowed, so nothing expensive or
credulous happens to a file that was never going to be accepted. The two that
carry the most weight: the **rate limit comes before the file is touched**,
because otherwise the work is the denial of service; and **`is_uploaded_file()`
comes before anything reads the path**, because without it a crafted request
naming a local file would have the rest of the routine copy that file into the
media library.

**Nothing the browser says is believed.** Not the name, which is discarded and
regenerated from the type that was found; not the claimed MIME type; not the
reported size, which is read from disk instead. The type is decided by
`getimagesize()` and confirmed by `finfo` against the same list, because a file
crafted to fool one reader is far easier to make than one that fools both.

**Four formats, and no SVG.** An SVG is a document: it carries script and
external references, and it would be served from our own domain, so accepting
one from an anonymous form is accepting stored XSS. There is no setting that
adds it.

### The community submission form

**`SFAF_Submit` is not the request form with the email check removed.** The
request form's gate is an sfaf.org mailbox, and its questions assume a colleague
who knows what a series and a venue are. This has no gate at all, the submitter
is a stranger, and the questions are the ones a stranger can answer. The two
share `SFAF_Submissions` and nothing else.

**The URL names the series, and that is the whole of the configuration.**
`/?uc_event_submit=cycle-to-zero`. A series record already carries a name and an
image, so pointing the form at one gives it a heading, a banner and the series
every submission joins. **A second campaign is a URL and a series, not another
build**, and there is deliberately no separate banner setting, because a second
place to put the picture is a second place for it to be wrong.

- **An unknown slug says the link is not right and stops.** Listing what exists
  would turn a submission form into a directory of every campaign the calendar
  knows about.
- **No REST route**, for the same reason the request form has none.
- **The status is named in the code** and `post_author` stays 0.
- **Cost and age are closed lists with one escape each**, not open boxes, and
  the list is read by the control and the validator so a value the form offers
  is exactly a value the validator keeps. The stored value is the WORDS, not
  the key: a key is a form's private business and everything that reads these
  is showing them to somebody.
- **Cost is REQUIRED and has no "not saying" entry**, and the escape's box is
  required when the escape is chosen. An event either costs something or it
  does not, and Free is worth saying because it is the question people ask. The
  list's first entry is a disabled prompt, because a required `<select>` whose
  first entry is a real answer pre-selects that answer.
- **FAQs and a capacity come with the submission.** The FAQs use the editor's
  repeater and land on the event's own FAQ meta, so they review like any other
  field; their answers take the NARROW allow-list, never `wp_kses_post()`. The
  capacity records what was asked for and **does not switch registration on**,
  because whether this calendar takes the registrations or the submitter's own
  link does is a decision taken at approval.
- **The event contact is three fields and is public. The submitter's own name
  and address are two fields and are not.** Different keys, different labels,
  and only the first is readable from a template.
- **One tick joins them, and it copies in one direction only.** "Use my name
  and email as the contact on the event page" hides the three fields and makes
  the submitter's own details the public ones, which is the truthful answer for
  most submitters. **The default is off**, so nothing anybody typed becomes
  public because a control was left alone, and **nothing ever copies the other
  way**: the public contact is what prints on the page, the submitter is who we
  reply to and who gets the registrations, and the second must never be set from
  the first. The copying is done by `validate()` rather than by the browser,
  because a hidden field posts nothing and because the answer then has to be the
  same with no script at all. No phone comes with it: the form never asked the
  submitter for one.
- **The picture chooser is on this form too, from 3.74.0**, filtered to the
  series the URL already names. See "One picture chooser". A chosen folder image
  becomes the event's thumbnail; an upload still does not.
- **The About you email takes up to five addresses, and the submitter's own is
  the FIRST of them rather than additional to them.** An external organizer
  wanting colleagues told when somebody RSVPs says so here. The first address
  is stored where every reader already looks, `SFAF_Request::META_EMAIL`, so
  `kind()`, `submitter()`, the confirmation and the pending queue are untouched
  by the field taking more than one; the whole list goes to
  `SFAF_Submit::META_NOTIFY_EMAILS`, and only when there is more than the
  submitter. `SFAF_Submissions::notify_addresses()` is the one reader, and the
  approval tick adds every one of them. **The confirmation goes to the first
  address only**, because that is the person who filled the form in, and the
  others get no message from the form at all.
- **The cap is `SFAF_Submit::MAX_EMAILS`, read by the control and the
  validator.** The Add button is not rendered at the cap, and portal.js removes
  it there: `data-repeater-max` on the standard repeater. **Gone rather than
  disabled**, because a disabled control is still a thing to read and wonder
  about. An address past the cap is dropped; an address that is not an address
  is an ERROR, because dropping it would promote the next one to first and the
  first is who the submission is recorded as being from.
- **Five addresses are not five allowances.** The rate limiter counts POSTS,
  keyed on the client and on the campaign, and neither key has ever been an
  address.
- **A submitted address does not create a venue.** The venue list is offered
  first; the parts are stored as the submitter's answer, and promoting one is a
  decision taken at approval by somebody who knows it will be used again.

**THE PUBLIC CONTACT IS STORED TWICE AND ONE READER DECIDES.** 3.47.0 replaced
`_uc_public_contact`, a single open box, with `_uc_contact_name`,
`_uc_contact_email` and `_uc_contact_phone`. The old key is still read, because
events submitted on 3.46.0 have one and there is no migration.
`sfaf_event_public_contact()` is the single reader: **the three-part answer
wins where it exists**, and anything that wants to show what a submitter gave
asks that function rather than a meta key. The pending row read the old key
directly for nineteen releases and rendered an empty line for it.

> **caladmin's "Listing detail" card still reads and writes the OLD key**, in
> `render_field()` and `save_event_from_post()`. On a submission from 3.47.0
> onwards its "Contact shown publicly" box is therefore empty while the event
> page shows a contact, and typing into it stores a value the page will not
> prefer. **Reported, not changed:** whether that box edits the three fields or
> stays a fourth is a decision, and its save path carries the `$offered`
> guarantee. See "Open decisions" in `HANDOVER.md`.

### The Listing detail card, and its contact box

**What the card is.** Four text fields, in the shared manager panel, so they
appear identically on the **pending queue** and in the **event editor**: one
render, one save, one list. They are the four lines a **community submission**
puts on the public event page, offered so a manager can correct them, because
the form that wrote them is not somewhere a stranger can go back to and a public
typo would otherwise sit on the calendar permanently.

| Box | Meta | Where it is read |
|---|---|---|
| Cost | `_uc_cost` | `sfaf_event_cost()`, the event page's fact list |
| Age restriction | `_uc_age_restriction` | `sfaf_event_age_restriction()`, same list |
| **Contact shown publicly** | **`_uc_public_contact`** | **`sfaf_event_public_contact()`, but only as a fallback** |
| Registration link | `_uc_rsvp_url` | `sfaf_event_rsvp_url()`, the "Register for this event" link |

**When it is offered.** Only on a saved event, and only when **at least one of
those four keys already has a value**, tested in `manager_panel_fields()`. Four
empty boxes on every event would be four more things to read past. In practice
**Cost is what makes it appear on a community submission**, because the form
requires cost, so the card is present with an empty contact box rather than
absent.

**The defect.** 3.47.0 replaced the single open contact box with three fields,
`_uc_contact_name`, `_uc_contact_email` and `_uc_contact_phone`, and no public
form has written `_uc_public_contact` since. `sfaf_event_public_contact()`
prefers the three where they exist. So on every community submission from 3.47.0
onwards, **the box is empty while the event page shows a contact**, and anything
typed into it is stored and then not preferred: a manager corrects a typo, saves,
and the page is unchanged, with nothing saying why. **A control that appears to
work and silently discards what somebody typed is the worst shape this can
take**, and it is worse than the pending panel's version of the same fault,
which 3.67.0 fixed, because that one only failed to show.

**The three options, and the one that shipped.**

1. **Make it edit the three fields.** One box in, three keys out, which means
   parsing a line back into a name, an address and a phone number. Guessing
   where a person's name stops is exactly what the 3.47.0 split was for.
   Rejected.
2. **Make it three boxes.** Honest and unambiguous: Name, Email, Phone in the
   card, writing the three keys, with `_uc_public_contact` kept read-only as the
   pre-3.47.0 fallback. **BUILT IN 3.72.0**, which is the state described below.
3. **Take the box out and show the contact instead.** The three fields are
   already visible on the pending panel from 3.67.0, so removing this box loses
   the ability to CORRECT them, which is the reason the card exists.

**Why 2 rather than the others.** The card's whole purpose is that a public value
somebody outside SFAF wrote can be corrected, and the contact is the line most
likely to need it. 1 cannot be done correctly and 3 gives up the purpose. The
cost is one card row becoming three, which the simplification job can take up
later.

**AS BUILT.** Three boxes writing `_uc_contact_name`, `_uc_contact_email` and
`_uc_contact_phone`, which are the keys the community form has written since
3.47.0 and the keys `sfaf_event_public_contact()` prefers. So what a manager
types is what the event page prints, which is the whole of what was wrong.

**THE OLD KEY IS NEVER WRITTEN AND NEVER DELETED BY THIS SAVE**, and that is not
timidity. Events submitted on 3.46.0 carry one and there is no migration; a
manager saving this card without touching the contact must not lose an event's
only public contact line. It is shown READ-ONLY, and **only where it is still the
value being read**, which is where the three above it are all empty. Where they
are filled in, the old value is doing nothing and a box labelled "not in use" is
an invitation to work out why, so it is not drawn at all. Filling any of the
three in is what supersedes it, by the reader's own preference rather than by
anything deleting anything.

**THE EMAIL IS SANITISED AS AN EMAIL**, not as a line. It is printed on a public
page and it is the one of the three that has a shape; an address that is not one
is dropped rather than published, which is the answer the community form already
gives.

> **THE SAVE PATH CARRIES THE `$offered` GUARANTEE, AND IT NOW COVERS SIX
> FIELDS.** `uc_listing_detail_present` is the marker that makes an empty box
> mean empty rather than "this screen did not ask", and an emptied box DELETES
> the meta rather than storing `''`. One marker travels with the whole card, so
> splitting one box into three added three fields depending on it rather than a
> new guarantee. A screen that does not render the card posts no marker and its
> save cannot blank a public line it never showed.

> **AND THE CARD'S OWN TRIGGER LIST HAD TO GROW WITH IT.**
> `manager_panel_fields()` decides whether to offer the card by looking for a
> value in the keys it edits, so the list has to BE the keys it edits. Leaving it
> asking only about `_uc_public_contact` would have hidden the card from every
> community submission made since 3.47.0, which is all of them.

### Approving a submission asks two questions, once

**One dialog, two ticks.** Both decisions are about the same person at the same
moment. Two prompts in a row is how a manager learns to press the second without
reading it.

**Until 3.48.0 neither form sent anything after its confirmation**, and both said
so deliberately: chasing is a person's job, and a system that nags on somebody's
behalf teaches people to filter it. That still holds for "still waiting". It does
not hold for **"it is live"**, which the submitter cannot learn any other way and
is the thing they are waiting to hear. It is still a per-event tick rather than a
hook on the status, so an event published by any other route sends nothing.

> **THE SECOND TICK SENDS REGISTRANT DATA OUTSIDE SFAF, AND THAT IS THE
> DECISION RATHER THAN AN OVERSIGHT.** It puts the submitter's address on the
> event's notification list, which already accepts typed addresses for people
> with no account here. That list carries TWO messages and they get both: the
> **registration alert**, naming who just registered and how many places are
> taken, and the **morning-of summary**, which lists everybody registered by
> name and email address. It is ticked by default because an organizer who does
> not receive their own registrations has a real problem. The label says both
> things rather than saying "registrations", because the person deciding has to
> be told what it actually sends.

- **A typed address, treated as one.** `user_id` 0, no caladmin link offered, no
  capability inferred. One more entry on an existing list rather than a
  mechanism of its own.
- **Idempotent, and case is not a second person.** Approving twice leaves one
  entry.
- **Both answers are re-derived from the event at approval.** The POST carries
  two ticks and nothing else: no address, no name. An address arriving in the
  request would be an address anybody who can reach that route could nominate,
  onto a list that is sent people's names and addresses. That is why the
  community form's extra addresses are read back through
  `SFAF_Submissions::notify_addresses()`, which re-checks every one and applies
  the cap again whatever is in the database.
- **The prompt names every address it is about to add.** One tick can put five
  people on a list carrying registrant names, and somebody cannot decide about
  a set they cannot see. `$listed`, which decides whether the published notice
  says "you will start getting mail", still means the SUBMITTER and only the
  submitter: that message goes to the first address and speaks for it alone.
- **No usable address means neither question is offered**, and the prompt says
  so. A tick that cannot do anything still reads as a promise that it did.
- The prompt is a plain panel that portal.js lifts into a `<dialog>`, and its
  inputs carry `form=` so they still post from inside it. With no JavaScript it
  stays beside the button and works exactly as it reads.

### A submission has no author, and that is the decision

**Nobody is logged in when a public form runs, so `post_author` is 0 on every
staff request and every community submission.** That was recorded as
deliberate, and 3.47.0 kept it after the alternatives cost a release.

> **WHY NOT A SYSTEM ACCOUNT, AND WHY NOT THE APPROVING ADMIN.** A system user
> is a new principal to secure: it owns events, appears in every people picker,
> and can be granted capabilities by anything that iterates users. Assigning to
> an admin puts a name against work they did not do, which is the same mistake
> as the badge that read "Staff request" on a stranger's submission, and makes
> "My events" claim a row nobody created. Authorless is honest. The cost is
> that queries have to cope, and the cost is exactly two places.

**Both of them are places that asked an author-shaped question of a post that
has no author, and both shipped as faults.**

- **THE PENDING QUEUE FILTERED ITSELF BY AUTHORSHIP.** It passed no scope, and
  an unset scope in `query_events()` means "your own plus your teams'", so every
  submission was excluded from the one screen built to review them and was
  reachable only by the link in its own notification email. It passes `'all'`
  now, through `pending_query_args()`, which the screen and its nav badge both
  read so the badge cannot count a different set from the list. It was the
  3.19.0 scoping; the 3.35.0 per-event gate is asked per route and was never
  involved, and the status was `pending` throughout.
- **THE ORPHAN ALERT REPORTED EVERY SUBMISSION.** "Nobody has been given this
  yet" and "whoever had it has left" are different states.
  `event_is_orphaned()` exempts a submission that is **awaiting review**, and
  only that: an APPROVED one with no owner still fires, because publishing
  without giving it an organizer is precisely the case the alert exists for.
  The status is the signal, so nothing extra is written and nothing needs
  cleaning up.

> **THE COMMENT ON THAT SCOPE DESCRIBED A CHECK THAT DOES NOT EXIST.** It said
> an unset scope shows everything to anyone who may view all. There is no
> `can_view_all()` on that path and there never was, so the next caller read the
> comment and omitted the argument. A comment describing a capability check that
> is not in the code is worse than no comment.
> **BOTH KEYS OR NO WIDGET IS DRAWN AT ALL.** The panel at Events >
> Integrations > Cloudflare Turnstile takes a site key and a secret, and with
> either one missing the form renders and submits with no challenge on it,
> protected only by the honeypot and the rate limits. Nothing announces this:
> the form still works, and the only place it shows is that panel. Put both keys
> in before the community form's address is shared with anybody.

- **Turnstile stands in for the mailbox**, and is never the only protection: the
  honeypot still runs, both rate limits still count, every field is still
  validated, and the result is still a row somebody has to approve. It **fails
  open on a Cloudflare outage**, which is only tolerable because of that list. A
  missing token is still a refusal.

**What is internal and what is public, decided per field.** The submitter's own
name and address are kept for reaching them and are read by no template. The
contact line is public, because they answered it knowing that. **Cost is free
text, blank by default, and absent rather than empty when blank**: an event with
no cost given is not a free event, and inventing "Free" would put a claim on the
page that nobody made.

> **A public value nobody here can correct is worse than no value.** The four
> lines a submission adds show on the event editor wherever one of them has a
> value, through the shared manager field list. Without that, a submitter's typo
> would sit on the calendar permanently, because the form that wrote it is not
> somewhere they can go back to.

**Anonymous prose is narrower than staff prose, and that is deliberate.**
`SFAF_Rich_Text::sanitize()` is `wp_kses_post()`, which is the right rule for
somebody with an account. `SFAF_Submissions::prose()` allows exactly what the
toolbar can produce: `p`, `br`, `strong`, `b`, `em`, `i`, `ul`, `ol`, `li`,
`h3`, `blockquote`, and `a` with `href` and `title` on http, https or mailto.
**No `img`, no `style`, no `class`, no `id`, no `target`.** None of them can be
produced by the control, and every one is a way to reach outside the box the
prose is drawn in.

### The current month is the floor, and it is clamped where the value is read

The public calendar never shows a month earlier than the current one, in any
display mode. `SFAF_Shortcodes::normalize_month()` clamps it, and that is the
one place the REST route, the ajax month loader, `month_grid_days()` and
`render_calendar_block()` all pass through.

> **Clamp where the value is normalized, not on the route.** The month rides a
> parameter, so before 3.45.0 anything could ask the route for `2019-03` and get
> a payload built for it, with a working grid and a previous-month control to
> keep going. Clamping on the route would have left the ajax loader open.

**Clamped, never refused.** An out-of-range month comes back as the current one
and the response names the month it built. A 400 would be correct and would
break any bookmark of a month that has since passed.

**Two things it must never reach**, and neither calls it: the single event page,
because a past event reached by direct link is a URL rather than navigation and
people arrive there from bookmarks and old reminder emails; and caladmin's
Events list and Archived view, which are staff screens that need the past.

### The filter bar is three rows, each on its own switch

**One switch became three in 3.50.0.** `show_filters` was all or nothing, and
neither answer fitted the cases that come up: a block scoped to one series on
that programme's page wants none, because the block is already the answer; a
block scoped to an organizer wants the SERIES row only, so a visitor can move
between that organizer's programmes.

`SFAF_Shortcodes::filter_rows_available()` is the one list, and it is also the
render order: **category, then organizer, then series**, which is the order a
visitor asks the questions in.

| | Attribute | Means |
|---|---|---|
| new | `filters="category,series"` | exactly those rows |
| new | `filters="none"` | no rows |
| old | `show_filters="no"` | no rows |
| old | absent | all three |

**An absent `filters` falls back to `show_filters`, and that is what keeps every
snippet already pasted on sfaf.org working.** `none` has to be a word for the
same reason: an empty attribute already means "not stated".

> **THE PLUGIN DOES NOT DECIDE A CONTROL IS REDUNDANT AND HIDE IT.** A block
> scoped to one organizer that asks for the organizer row gets the organizer
> row. Whoever generated the block can see the page it is going on and this code
> cannot. The tempting "helpful" version of this was planted as a fault and is
> asserted against, because the combination tests alone did not catch it: every
> block in them is unscoped, so the condition never fired.

**Everything filters through the query, never by hiding rows.** Client-side
hiding only ever sees the page already downloaded, which is what made both the
old search and the old category filter wrong past page one, and the count wrong
with them.

**The scope clamp is unchanged and now covers three parameters.**
`effective_category()`, `effective_organizer()` and `effective_groups()` each
keep a visitor's choice inside what the block was scoped to, so a hand-written
`active_category`, `active_organizer` or `active_groups` reaches nothing the
block does not already contain. It holds with the row switched off entirely,
which is the case such a parameter is actually aimed at.

**No new REST route.** The extra parameters ride the existing embed route,
because `is_embed_request()` compares the route string exactly and a second
route would match none of preflight, the response headers or the
`rest_pre_serve_request` fallback. Same route, more parameters, same headers.

**The sidebar has no filter bar in any configuration** and returns before one is
built. That is not an exception to the toggles: it is a different shape, with a
count rather than a page size and no pagination.

### An event has several organizers, and every writer sets them in one call

**They are equal, with no lead organizer** (3.40.0). `uc_organizer` has always
been multi; what limited an event to one was the controls. `for_event()` returns
them name-ordered through one method, so two surfaces cannot name the same
event's hosts in different orders, and `phrase()` joins them as "A, B and C"
with **no serial comma**, which is AP style for a simple series.

**No tie-break was needed and none was invented.** A category's first
alphabetically supplies the card colour and the placeholder tile. An organizer
carries no colour and no icon, so nothing downstream has a decision to make.

**THE WRITE RULE: one `wp_set_object_terms()` call with the whole set, never one
per id.** It REPLACES by default, so a call inside a loop keeps only whichever
ran last. That is the 3.8.0 categories fault and the 3.40.0 organizers fault,
and `.claude/organizers-test.php` now asserts it against every writer by name.

**The lesson is the sequencing, not the fault** (3.84.0). 3.40.0 fixed every
surface that existed then. The staff request form's organizer field was added in
3.76.0, thirty-six releases later, and was written as a single select, because
the person adding a field reaches for the shape the form already uses rather
than for a decision recorded elsewhere. **A settled decision does not propagate
to code written after it.** The two public forms were the last single-organizer
surfaces and neither could ever have dropped a STORED organizer, because both
create a pending event and neither edits one; what was lost was what the
requester said, before it was ever stored.

The community form has no organizer question at all, deliberately: it is reached
at a series' own address by somebody outside SFAF, who is in no position to
guess which programme is putting an event on. It **inherits** every organizer
the series lends, read off the series' most recent event, and inheriting only
the first put a co-hosted submission under one team's filter and not the other's.

**AND IT INHERITED NOTHING AT ALL UNTIL 3.85.0, BECAUSE THE EVENT WAS ITS OWN
SOURCE.** `create_event()` added the new event to the series and then asked
`organizers_for()`, which answers from the series' MOST RECENT EVENT and counts
`pending` among the statuses it reads. A submission is pending and dated in the
future, so the newest event in the series was the submission itself, holding no
organizer yet. It read its own empty set and wrote nothing, every time, from
3.76.0. **The write was correct throughout**, which is why reading it in
isolation found nothing: the fault was the order, and the resolve now happens at
the top of the method before anything is inserted or joined.

**THE GENERAL SHAPE, worth more than this instance:** a derived value must be
read BEFORE the thing being created joins the set it derives from. Anything that
asks "what does this group look like" after adding a member to the group is
asking a question the member has already changed.

**An organizer is required from 3.85.0**, on the server, and the rule is about
DIRECTION rather than state, which is what protects the hundred published events
that have none. `SFAF_Organizers::requirement()` is the only place that decides:
an event that HAS organizers cannot be saved with none; a new or unpublished one
being published with none is saved and not published; and an event that had none
and still has none is left exactly as it is, published included. A flat refusal
would block somebody fixing a typo over a field they did not come to change.

### Organizers and groups are one control, in the top layer (3.85.0)

**TWO DROPDOWNS ASKED WHAT IS ONE QUESTION.** Programa Latino exists as both an
organizer and a series, so the two lists could show what reads as the same name
twice with nothing to tell them apart. **The headings are what tell them apart**,
which is why the panel is grouped rather than one list of thirty-four names.

**IT IS A `<details>`, AND IT WAS A POPOVER FOR ONE RELEASE (3.86.0).** The
popover version opened in the top left corner of the viewport on sfaf.org. It
was `position: fixed` at 0,0 until a script moved it from the popover's `toggle`
event, and it was HIDDEN by `:not(:popover-open)`, a selector a browser without
the API discards along with the whole rule, so the panel could also stand open
permanently. Two failure modes, one dependency.

> **A CONTROL THAT OPENS MUST NOT DEPEND ON A PLATFORM FEATURE OR ON SCRIPT.**
> `<details>` and `<summary>` open everywhere with nothing running, and absolute
> positioning inside a relative wrapper has placed elements under other elements
> since CSS 2. That is the whole mechanism now. The script adds light dismiss,
> Escape and the reordering: things a menu wants and a `<details>` lacks, none of
> which are how it opens.
>
> **It was never CSS anchor positioning**, which this plugin has never used. The
> diagnosis mattered because the remedy differs: this needed the dependency
> removed, not a fallback bolted beside it.

**A CLOSED `<details>` DOES NOT HIDE AN ABSOLUTELY POSITIONED CHILD.** Measured:
the panel rendered 700x184 with the control shut, because out-of-flow content
escapes the content skipping a closed `<details>` does.
`.uc-who:not([open]) .uc-who-panel { display: none; }` is the remedy and it is a
plain attribute selector.

**TWO COLUMNS, ONE THIRD AND TWO THIRDS, HELD BY THE GRID.** Narrowing can take
the right column from twenty-five names to two, and a template sized by its
contents would jump on every tick. `1fr 2fr` does not care what is left inside
it. The stack breakpoint is a CONTAINER query, because the block is embedded in
a column it does not control and a media query about the window is a lie there.

**What the top layer gave and this does not** is escaping an ancestor's
`overflow: hidden`. The filter bar has none, and a control that is occasionally
clipped is a better failure than one that is reliably in the wrong corner.

**THERE IS NO APPLY BUTTON, AND THE COST OF THAT IS NOT THE REBUILD** (3.87.0).
Every filter on this bar applies as it is pressed, so a button that repeats what
already happened is one people press twice and then distrust. It lives inside
`<noscript>`, so it is absent rather than hidden for anybody with script, and is
still the entire no-script path.

> **THE PANEL HAD TO SURVIVE ITS OWN REDRAW.** `reloadBlock()` replaces the whole
> block and the server renders the control CLOSED, because a `<details>` is
> closed unless it says otherwise. While Apply did the reloading that was
> invisible. With every tick applying, the panel shut on the first box and
> ticking two was impossible. The open state is carried across the swap.

**AND IT IS DEBOUNCED AT 350ms**, longer than the search box's 250ms on purpose:
a search is one field typed continuously, and this is several separate decisions
with longer pauses between them. Narrowing and relabelling stay instant, because
they are local and cost nothing; only the whole-block redraw waits.

**THE NARROWING IS ONE-WAY AND DERIVED FROM EVENTS.** Nothing stores a group's
organizer: a series carries a description, an image and a FAQ set, and an
organizer is a property of the EVENTS in it. `who_counts()` reads them off the
published, non-private events in each group as it counts, so a group appears
under every organizer that runs anything in it, and a collaboration appears
under both. Selecting organizers hides non-matching groups; selecting groups does NOT
narrow the organizers, because then each would hide the other's options and
neither list could be trusted to be complete.

**A GROUP WITH NO ORGANIZERED EVENTS IS ALWAYS SHOWN**, and this is the rule
most likely to be "tidied" later. An empty list is missing information, not a
statement that the group is not that organizer's. On the current data it is
roughly a third of the groups while the hundred are being set by hand, so hiding
them would empty most of the list and read as a broken control. **A ticked group
is never hidden either**, or a filter runs with nothing on screen to clear it by.

**THE LIST REORDERS ON OPEN, NEVER ON CLICK.** Selected items sit at the top; a
list that moves the row just ticked out from under the cursor makes the next
click land on something else. The server emits the order for the state it
renders and the script reorders only when the panel is next opened.

**THE FILTER BAR NOW WORKS WITH SCRIPT OFF, WHICH IT NEVER DID.** There was no
`<form>`, no submit and no `<noscript>` anywhere in the file: the search box, the
organizer select and the group checkboxes were all read by JavaScript. "The
calendar works without script" was true of the LISTS and was never true of the
FILTERS. `popovertarget` opens the panel declaratively, Apply is a real submit on
a real GET form, and the script intercepts exactly as `initLoadMore()` does.

**THIS MADE GROUPS A FIRST-LEVEL FILTER**, undoing the staging that kept them
hidden until a category was chosen so that nobody was looking at two taxonomies
at once. Merging necessarily ends that, because the organizer half was always
first-level. `render_group_row()` has no caller and is kept one release.

### The list view is a table of rows (3.91.0)

**Thumbnail, title, date and time, venue.** It replaced the card rather than
joining it: no large image, no excerpt, no footer button, and no second list
layout to maintain. **No description column**, because a paragraph per row is
what makes rows tall and is the one thing that undoes the density.

**A GRID OF DIVS, NOT A `<table>`, AND THE REASONS ARE STRUCTURAL.** Both
scripts append rows into a div, and a `<tr>` appended into a div is nothing; a
real table meant changing the container and the append target in `calendar.js`
AND `embed.js`, which is the split that has cost five faults. A table also
cannot restack on a phone without scrolling sideways. **The class stays
`uc-event-card`**, so every existing selector in both scripts keeps matching.

**THE CARD CHROME IS TURNED OFF BY NAME**, not left to be wondered about:
`.uc-event-card` still supplies a ground, an 18px pad, a radius, a border and a
lift shadow, and nine of those stacked is the boxiness this replaced.

**Three widths, measured**: 900x90 with four columns at 1100px; at 700px the
venue moves under the title, because the fixed date and venue tracks were
squeezing it to 170px; at 380px everything stacks beside a 72px thumbnail. The
DOM order never changes, so the reading order is the same in all three.

### The month tile shows the whole title (3.91.0, reversing 3.89.0)

**3.89.0 cut it to one line with an ellipsis** and offered the hover preview as
where the full title lived. That was wrong: **a column of ellipses tells nobody
what anything is**, and hover neither helps somebody scanning nor exists on a
phone. The title wraps to as many lines as it needs.

> **A FALLBACK THAT REQUIRES AN INTERACTION IS NOT A FALLBACK FOR SCANNING.**
> The hover preview is a good thing and was the wrong argument for hiding
> content from the only view that shows a whole month at once.

**The time sits under the title**, because pinned beside it the time takes width
the title needs and forces wrapping the title did not need.
`.uc-day-event-text` is what lets those stack, and removing it in 3.89.0 is why
that release had no other option.

### An event on the month grid is a line, not a card (3.89.0)

**A dot, the title, the time**, at 21px a row, measured. Each event was a
bordered box with a 32px picture, a title clamped to two lines and a time on its
own line, which cannot be shorter than 42px; nine on one Wednesday pushed the
rest of the month off screen.

**FOUR THINGS KEEP NINE LINES READABLE AND NONE OF THEM IS A BORDER**, which is
the part to preserve if this is ever adjusted: the dot acts as a left margin so
the titles form a column, one line each keeps the rhythm uniform, the time is
right aligned so the times form their own column, and the hover is the whole row.
Remove any one of those and it becomes nine undifferentiated lines of text,
which is not an improvement on nine boxes.

**THE DOT IS NOT THE ONLY CARRIER OF THE CATEGORY.** The name is emitted beside
it as text in the accessibility tree. Colour alone cannot state a fact, which is
recorded elsewhere in this file and is why the hidden span is not redundant.

**A TITLE THAT WILL NOT FIT IS CUT, NOT WRAPPED**, because wrapping is what made
the rows enormous and uniform height is what makes a stack scannable. Nothing is
lost: the hover preview, the event page and the accessibility tree all have the
whole title. **The day panel wraps**, because it is full width and showing the
whole title is the reason that panel exists. One rule, two contexts, opposite
answers.

`sfaf_day_event_thumb()` is kept for one release with no caller, because the
line treatment is the part most likely to be reversed.

### A stored id that no longer resolves is treated as absent (3.90.0)

**A DELETED PICTURE USED TO PIN A SERIES TO NOTHING.** `SFAF_Series::image_id()`
read the stored attachment id first and never asked whether it still resolved.
Replace a series' picture and the meta pointing at the old one stays; a stale id
is truthy, so it won, `image_url()` ended at a dead attachment, and the tag
fallback never ran. Every surface went dark at once while a correctly tagged
picture sat in the folder unused.

> **AN ORDER PROBLEM, NOT AN EMPTY STATE.** The investigation before it concluded
> nothing was tagged and was wrong. When a chain has a preferred source and a
> fallback, ask whether the preferred one still ANSWERS, not just whether it is
> set. A truthy pointer to something gone is the shape to look for.

**FALL THROUGH, DO NOT CLEAR.** Deleting the stale meta on read would turn a
read into a write, fire on the public calendar for every visitor, and destroy
the only record of what the series was pinned to before anybody could look at
it. A picture that is still there still wins, always.

**TWO READERS HAD THE SAME BLINDNESS**, which is why every surface failed
together: the chain, and the series screen, which previewed the raw stored id
and so did not even fall through to the pasted URL beside it. The screen asks
the chain now, so it cannot disagree with the calendar.

### Setting a series' picture also tags it (3.90.0)

**The Series dropdown on the upload panel is the tag**, so setting a picture and
tagging it should not be two things somebody has to know to do separately.

**WHAT IT COSTS AGAINST 3.81.0**, which made a tag a fallback rather than a
second setting: that survives in the direction that matters, because nothing
makes a tag override a setting and the stored id is still read first. What
changes is that a deliberate choice leaves a tag behind it, so the two facts
agree rather than drifting.

**Nothing reads a tag assuming nobody set it deliberately.** The three readers
are the picker's series filter, the earliest-tagged fallback and the Images
screen's grouping, and all three mean "belongs with this series". The one thing
to keep in view is unchanged and is why the fallback is the EARLIEST rather than
the newest: tagging an older attachment to a series with no stored picture can
change what it falls back to. **Additive, and it never untags**, because a save
quietly unpicking a relationship nobody mentioned is a fault shape this project
keeps meeting.

### The media library's folder is a taxonomy, and it is discovered (3.90.0)

**Both facts were true at once**: pictures sat in `uploads/calendar/` and the
library's Calendar folder showed fewer. WP Media Folder files by TAXONOMY
ASSIGNMENT rather than by location, and this plugin only ever set the path.

**THE TAXONOMY IS DISCOVERED, NOT NAMED.** WP Media Folder is commercial, is not
on wordpress.org and is not on the build machine, so its taxonomy name could not
be verified. A hardcoded guess would either work silently or fail silently with
no way to tell which. The code asks WordPress which taxonomies attachments carry
and looks for a term literally named for the folder.

> **IT CANNOT MISFILE ANYTHING.** The taxonomy must be registered for
> `attachment`, must not be one of ours, and must ALREADY hold a matching term.
> Nothing is created. A site without that plugin is untouched. Appended rather
> than replacing, and only on an upload carrying the calendar flag.

**The folder rule still reads the physical directory.** This adds a second,
independent fact for a library this plugin does not own; it does not become the
rule. Nothing about display depends on it.

### Every name in the picker carries its count (3.92.0)

**The whole panel is two queries.** One `get_posts()` for the ids that match
everything except this control's own answers, and one `wp_get_object_terms()`
that resolves `uc_organizer` and `uc_series` across that entire id set at once,
with the object id on each row so a term can be attributed back to its event.
The tallying is a pass over the rows in PHP. `who_counts()` is where all of it
lives.

> **A COUNT PER NAME WOULD BE THIRTY-FOUR QUERIES ON EVERY RENDER**, and the
> panel re-renders on every redraw. It also **replaced fifty queries that were
> already there**: `group_organizer_map()` asked the database separately for
> each of twenty-five groups, twice. That method still exists and nothing calls
> it from the picker.

**THE COUNTS ARE THE LIST'S OWN QUERY, WHICH IS WHY "UPCOMING" NEEDS NO SECOND
DEFINITION.** `who_counts()` calls `build_query_args()`, so upcoming, published
and non-private mean exactly what they mean in the list, and a block scoped to
one series or one category counts inside that scope. A count that built its own
args would drift from the list the first time either moved.

**THE ACTIVE FILTERS NARROW THE COUNTS, WITH ONE EXCEPTION THAT IS DELIBERATE.**
A category, a search and a block's scope all apply, because a count that ignored
them can say 12 and then show nothing.

- **An organizer's own count ignores the organizer ticks.** Organizer is the
  controlling filter here, and leaving it applied would make every unticked
  organizer read (0) the moment one was ticked: a list of zeros rather than a
  set of choices.
- **A group's count is computed inside the ticked organizers**, because the two
  AND together in the query and the group is what is being narrowed.
- **The map is not narrowed at all.** It is the full relationship, because it is
  what the client-side narrowing reads to decide what to hide in the 350ms
  before the debounced redraw, and narrowing it here would make that decision
  circular.

**A ZERO IS HIDDEN, NOT SHOWN.** The panel already hid a group with nothing from
the chosen organizers, so a name with nothing behind it not being there is the
answer this control already had; adding a second one for the same situation
would be the worse outcome. **Two cases are held out of it:**

- **A ticked row survives its own zero**, or the filter it represents has no
  control left to turn it off by.
- **A group whose events name no organizer at all survives too**, which is the
  rule above. Its count is computed inside the ticks, so it reads (0) as soon as
  one is ticked, and a plain zero rule would then hide it: the narrowing rule
  reversed by a side effect. `$protected` in the render loop is the whole of
  that, and `who_counts()` returns a second `group_all` tally, unnarrowed, for
  it to ask.

**The count is `aria-hidden` and carries a spoken alternative beside it**, "12
upcoming events", because "(12)" read out on its own attaches to nothing.
### Separation is not weight, and measuring first tells them apart (3.91.0)

The Organizers and groups panel was reported as "a big blob of text and boxes".
**The previous complaint about the same control was the opposite**, thick and
boxy, and 3.87.0 lightened it. Going further in that direction would have made
it worse, and only measuring showed why.

**What it was**: headings 11px/600 uppercase, rows 14px/400 at 38px pitch, no
row separator, no divider between the two columns, a `column-rule` computing to
3px and painting nothing because no style was set, and **the heading sitting
0px above the first row**.

> **THAT LAST ONE WAS A CASCADE FAULT, NOT A CHOICE.** `.uc-who-heading` asks
> for a margin at (0,1,0) and `.uc-calendar p { margin: 0 }` beats it at
> (0,1,1), so the headings had no vertical separation from the day the panel
> was built. The recurring fault in section 7, for the fifth time.

**Four separators and no weight change**: the heading margin at a specificity
that applies, a rule under each heading, a hairline between rows, and a divider
between the columns and the sub-columns. There is an assertion pinning the
weights, because the temptation next time will be to move them again.

**A property can be present in a measurement and do nothing.** The
`column-rule` had a computed width and no style. Read what paints, not what is
set.

### A stale embed.js says so, and the URL stays unversioned (3.89.0)

**The script URL must not carry a version**, and this is a decision made by a
defect rather than a preference. That URL is baked into a snippet somebody
pastes once, so a version PINS it to whatever the plugin was on the day it was
copied rather than busting anything. In 2.10.1 that left host pages loading an
old script, and an old stylesheet with it, because the script derived the
stylesheet URL from its own src.

**So the version travels in the payload**, which is fetched fresh on every load
and cannot be pinned, and `embed.js` names the mismatch in the console.

> **IT DOES NOT MAKE A STALE SCRIPT FRESH AND DOES NOT CLAIM TO.** It turns the
> failure from invisible into named. Four fixes have looked arbitrary because a
> release was live and the page was not, and nobody thinks to hard refresh.

**A warning rather than a reload**: a script that refetches itself when it
dislikes a number can loop against a CDN serving two versions from two edges, on
a page this plugin does not own. **And the constant cannot drift**, because the
build fails when it is not `SFAF_VERSION`, which is the only thing that makes it
worth having.

### The embed has its own script, and that is where fixes go missing

**THREE TIMES NOW a correct change reached the shortcode and not the embed**: the
list card was rebuilt into a renderer only the shortcode used, the toggle
rendered into a panel the stacked layout hid, and search was taught to redraw
the month grid on the admin-ajax path while `embed.js` went on asking the REST
route for `mode=items` and nothing else.

> **THE RENDERERS ARE SHARED AND THE SCRIPTS ARE NOT.** `calendar.js` and
> `embed.js` are two files of handlers over one set of markup, joined by nothing.
> A behaviour added to the filter bar has to be added twice, and the second one
> is the one that gets forgotten, because the first one demonstrably works.

**THE TEST IS THE PAIRS, NOT THE BEHAVIOUR.**
`.claude/embed-filters-test.php` asserts that for each thing the filter bar does,
BOTH scripts do it: the merged control handled AND actually called, the month
redrawn from the search handler rather than merely reachable, every narrowing in
both month cache keys, the open panel carried across the redraw and the
narrowing reapplied after it.

> **A PAIR TEST ONLY COVERS THE PAIRS SOMEBODY ENUMERATED, and that is its
> limit rather than a flaw to fix by trying harder.** The file was green in
> 3.88.0 while the open state was missing from one script, because that
> behaviour shipped a release earlier and was never on the list. It was green
> and it was blind.
>
> **THE RULE THAT FOLLOWS: anything touching the filter bar in ONE script adds
> its pair to that file in the SAME release.** Not afterwards, not when it next
> breaks. This is the fourth fix to reach one path and not the other, and the
> only reason the fourth was found is that Mark used it.

**"DECLARED" IS NOT "CALLED", AND THIS FILE HAS MET THAT TRAP THREE TIMES.**
Every assertion about a handler is written against its CALL SITE, because
planted renames and deleted calls leave all the strings a name check looks for
sitting in the file. If an assertion can pass with the feature unreachable, it
is asserting the wrong thing.

**A HANDLER THAT EXISTS IS NOT A HANDLER THAT RUNS.** `embed.js` held the code
for the merged control and never called it during one planted fault, and the
first draft of the test passed: every string it looked for was still in the
file. Assert the call, not the code.

**AND THE EMBED'S CACHES ARE TWO, NOT ONE.** The server's `cache_identity()` has
carried every narrowing for releases. The CLIENT's `monthCacheKey()` carried only
the category, so searching and clearing served the grid cached for the other
state out of a JavaScript object. When a payload looks stale, ask which of the
two is answering.

**A NARROWED RESPONSE IS NOT PUBLICLY CACHEABLE.** `public, max-age=60` on a
response built for one visitor's search makes a filtered calendar a document a
shared proxy may keep. The unnarrowed calendar is the same for everybody and is
still cached; anything carrying a search, an organizer, groups or a category is
`private, no-cache`.

### The organizer filter was a control that did nothing

**Until 3.50.0 it rendered on this site, was left out of embeds deliberately,
and had no handler in either script.** Its own note called it a client-side
stub. Choosing an organizer changed neither the rows nor the count anywhere.

It runs the same server query the category chips run now, through the same
clamp, so there is no longer any reason for an embed to be a special case. Its
options are the block's **own** organizers when the block is scoped, exactly as
the chips are: offering every organizer on the calendar would list dozens that
could only ever empty the block.

> **A toggle for a control that does nothing is furniture.** Adding an
> "Organizer" switch to the generator without this would have shipped a promise
> the block could not keep.

### The second-level series row, and the gate that was mistaken for a fault

**3.11.0 built it and it was never removed.** Reported missing from the live
calendar in 3.50.0, and checked in the SHIPPED zip rather than the working tree:
`render_group_row()` is defined AND called, `available_groups()` derives the
terms, `calendar.css` styles the pills, and both `calendar.js` and `embed.js`
bind them. Nothing was unwired and nothing was broken by a later release.

**Two conditions decide whether a pill appears, and both are data rather than
code:**

- **Until 3.50.0, a category had to be chosen first.** 3.11.0's reasoning was
  that nobody should be looking at two taxonomies at once, which was right while
  the bar was all or nothing. A bar showing categories only is exactly what that
  looks like before the first click.
- **The pills are the series carried by the events the block actually
  contains**, derived through `object_ids`. A category whose events carry no
  series offers none, correctly.

**The category gate is gone**, because a block can now offer the series row and
NOT the category row, and under the old gate that block could never show a
single pill. The row is derived only when its toggle is on, so a block without
it still pays for no query.

> **The word "series" does not appear on the row; it says "Groups".** A visitor
> should not have to know the calendar has a taxonomy called that. The code
> still calls them groups throughout, which is why `available_groups()`,
> `effective_groups()` and `uc_group` all read that way while querying
> `SFAF_Series::TAXONOMY`.

### The combined view is one calendar

The month grid and the sidebar sit in one container: one border, a divider
between them, and the month name spanning the top. **The sidebar lists the month
the grid is showing**, which is what makes them one thing rather than two views
side by side, and navigating moves both in one request.

- The month is passed to `render_sidebar()` by the block, which is the same
  value it passed `render_month_grid()`, so they cannot disagree by
  construction.
- Binding to a month sets **both** ends of the window. An upper bound alone gave
  October's list the last days of August, which is exactly the disagreement the
  change was meant to remove.
- The sidebar DISPLAY MODE is unbound and still spans months. Only the combined
  mode binds.
- The changeover is **864px**, the two flex bases with no gap between them. The
  gap is what made them read as two cards, so it is zero and the divider
  separates them.

**`render_combined_parts()` builds all three pieces, and both the first render
and the ajax redraw ask it.** There is no second composition.

> **The redraw composed it again, and that is how 3.45.0 shipped half applied.**
> It decided whether the grid drew its own heading from a boolean the browser
> sent, so a caller that did not send it, including a browser holding an older
> script, got a heading inside the left column under the spanning one. First
> load was right and navigating was not. **The shape is read from the VIEW
> now**, normalized by the same function and asked the same
> `is_combined_view()`, so a redraw cannot choose a shape the first render would
> not have.

> **This mode has shipped four faults in four releases and the suite passed
> every time**, because each test asserted something about the markup rather
> than the markup. `.claude/combined-outcome-test.php` renders the real
> renderers and reads what came back. Its `WP_Query` honours the date clauses,
> and its self-test proves that: a harness that returns the same rows whatever
> it is asked would pass every assertion in the file while proving nothing.

> **When one thing is produced in two places, compare the OUTPUTS.** A check
> asking whether a string exists anywhere is satisfied by either copy, which is
> what this file's own 3.45.0 note said before 3.45.0 shipped exactly that.

> **THE BOUND MONTH IS A PARAMETER ONE RENDERER SETS, NEVER A FILTER A CALLER
> FILLS IN.** 3.45.0 bound the sidebar with a filter key called `month`, and a
> block already carries an ATTRIBUTE called `month` meaning a different thing:
> which month the grid draws. `render_calendar_block()` hands its whole
> attribute array to `normalize_filters()`, and `SFAF_Embed::normalize_params()`
> fills that attribute in on every request because `normalize_month()` answers
> "which month am I drawing" and so never returns `''`. Every list silently
> gained an upper bound: 27 upcoming events, 5 on screen. The key is
> `bound_month` now, and nothing reads it off an attribute, an embed parameter
> or a POST field. **Two things sharing one key is the fault; renaming one of
> them is the fix, not adding a condition.**

> **A SEAM IS NOT A RENDERER, AND EVERY FAULT HERE HAS BEEN AT A SEAM.** Six
> releases, six faults, and `render_sidebar()`, `render_month_grid()` and
> `render_events()` were correct every single time, so a harness that calls them
> said so honestly and uselessly. What was wrong was what the ENTRY POINT handed
> them, or the CONTEXT they landed in. `.claude/display-mode-scope-test.php`
> therefore calls no renderer directly: every assertion enters through one of
> the four doors a visitor comes through, `render_calendar_block()`,
> `ajax_load_block()`, `SFAF_Embed::build_payload()` and `calendar.css`, and
> reads what came back. **It asserts nothing about source text**, because three
> source-string assertions have now been satisfied by the wrong copy.

> **WHAT NO CHECK HERE CAN SEE, AND WHAT THAT LEAVES FOR A PERSON.** There is no
> browser and no WordPress in the build environment, so nothing is laid out and
> nothing is clicked. Overlap, overflow, stacking order, a band that is wider
> than its rows once a real font has loaded, and anything that only appears
> after two interactions are all invisible to it. Section 4 of that file is
> arithmetic over declared CSS values, which decides that two numbers agree, not
> that the result looks right. **After any release touching this mode, on the
> EMBEDDED calendar on another domain** (four of the six faults were embed-only,
> because first load composes correctly on both and the embed comes in by a
> different door): the list shows more than one month; navigating twice leaves
> one month name and one previous and next with both halves on the same month;
> navigating BACK to a month already seen behaves the same, since that path is
> served from a cache the first visit filled and has been wrong on its own; the
> heading band lines up with the rows in both the combined view and the sidebar
> alone; and the event photos load rather than showing blank placeholders,
> which is how a root-relative URL escaping to another domain shows up.

### caladmin asks for its own assets, and there are two of them

The portal builds its own document rather than going through `wp_head`, so
anything WordPress would normally print has to be requested. Two flags, and they
are separate because conflating them returned a 500 in 3.44.0.

| Flag | Means | Pairs with |
|---|---|---|
| `load_media` | this screen opens the media library | `wp_enqueue_media()` |
| `load_editor` | this screen has a rich text control | `wp_editor()` or `SFAF_Rich_Text::enqueue()` |

Either prints the enqueued styles and scripts. **Only `load_media` prints media
templates**, because `wp_print_media_templates()` is a media-stack function and
is not safe on a request that never loaded the media stack.

> **A flag that means two things will be set for one of them.** FAQ Sets set the
> single old flag to get its scripts printed, having no reason to enqueue media
> and not doing so, and the footer then reached a media function with no media
> stack behind it. The screen fataled halfway through its own footer, so the
> document went out truncated and the chrome looked squeezed rather than absent.

> **The screens that did not break were lucky, not correct.** The event editor
> and the pending queue render the same FAQ control and survived only because
> they call `wp_enqueue_media()` for their image picker, which has nothing to do
> with rich text. Removing the picker from either would have broken them the
> same way. `.claude/screen-assets-test.php` now requires each screen to declare
> what it uses and to use only what it declared.

**This is the third fatal here that lint could not see**, and the contract check
is the closest achievable thing to catching it.

> **A caladmin screen CAN now be rendered in the build environment, and it still
> would not have caught this one.** 3.49.0 stubs enough of WordPress for
> `.claude/pending-queue-test.php` to call `render_pending()` and read the rows
> back out of the HTML, so the blanket claim that used to stand here, that
> rendering a screen is not possible without WordPress, a database and an HTTP
> server, is no longer true and has been removed.
>
> **The original objection survives narrowed, and it is the important half.**
> A stub file DECIDES WHICH FUNCTIONS EXIST, and that is exactly the question
> this bug turned on: `wp_print_media_templates()` is defined unconditionally in
> the harness, so a screen calling it without the media stack renders perfectly
> there and fatals in production. **A rendering test proves what came back; it
> cannot prove that what it called was safe to call.** Those are different
> questions, the contract check answers the second, and loading the screens for
> real stays a manual pass.

### The FAQ editors, and the third way a row arrives

**SOLVED IN 3.74.0, AND THE ANSWER WAS NOT WHERE THREE RELEASES LOOKED.** The
symptom reported from 3.68.0 onward was "the FAQ answers show raw markup until
somebody presses Add FAQ, which then turns every box on the screen into an
editor including the ones that were already there".

**A ROW ARRIVES ON THAT SCREEN THREE WAYS, AND ONLY TWO OF THEM ASKED FOR AN
EDITOR.**

```
from the server   the load pass in initRichText() covers it
+ Add FAQ         `.uc-repeater-add`, matched by initRichText()'s listener
a FAQ set         `[data-uc-faq-apply]`, matched by NOTHING until 3.74.0
```

`initFaqSetPicker()` clones the same `<template>`, fills in the question and the
answer and appends the row. It never asked for an editor, so those answers were
the plain textareas the template holds, and they stayed plain until something
else happened to run `startAll()` over the whole document. **Pressing Add FAQ is
exactly that**, which is why one new row turned every set row into an editor at
the same moment: the SWEEP was doing it, not the add.

**THE CONSOLE WAS SILENT AND THE SILENCE WAS THE FINDING.** The catch added in
3.72.0 names an initialise that threw. Nothing on this path ever got as far as
initialising, so there was nothing for it to name, and the absence of a message
was the evidence that the exception theory was the wrong theory.

**MATCHED ON THE DOCUMENT RATHER THAN CALLED FROM THE PICKER.**
`initFaqSetPicker()` is in another of portal.js's four top-level scopes and
cannot see `startAll()`; calling across is the 3.72.0 fault exactly. A listener
on the document is what the two scopes already share.

**WHAT THE EARLIER WORK GOT RIGHT AND IS KEPT.**

1. **Every FAQ answer takes the browser-started path**, `SFAF_Rich_Text::deferred()`,
   including rows that exist when the page is built, while the description beside
   them is a real `wp_editor()` that starts itself. The docblock used to claim
   otherwise and was corrected in 3.72.0 rather than made true, because forking
   `sfaf_faq_row()` would put the FAQ repeater back to two copies of one control.

2. **The load pass goes through the same deferred task the add pass uses**, with
   a short retry rather than a single turn, because a bare `setTimeout(0)` is a
   guess about timing.

3. **`start()` logs rather than swallowing.** Kept exactly as it is. It is still
   the only route to a diagnosis if a row is ever asked for an editor and does
   not get one, which is a different fault from this one and has not been seen.

**`.claude/rich-text-start-test.js` presses both buttons now** and plants the
3.73.0 arrangement, in which a set row is appended and never started.

### Rich text is a rule, and one control

**Any field where somebody writes more than a sentence and it is DISPLAYED AS
PROSE gets the editor.** Stated as a rule so a field added later inherits it.
`SFAF_Rich_Text` owns the toolbar, the rendering, the sanitising and the
conversion back to plain text.

Both halves of the rule do work. "More than a sentence" excludes labels and
one-line notes, where a toolbar is clutter. "Displayed as prose" excludes
anything read as a VALUE: a title attribute, an ICS field, a JSON-LD string, a
search index. HTML in one of those is not formatting, it is corruption.

**Qualifying today:** the event description, the series description, and FAQ
answers. **Excluded:** the cancel reason (one line, and it goes into an email as
a value), the organizer description (its own label says it is not displayed),
the public request form's fields (anonymous input is stripped on purpose, see
§3), and every email body (below).

> **One control, because the copies would differ in what somebody may TYPE.**
> 3.43.1 found the image picker written out twice and one copy silently
> broken. A rich text control is worse: one screen offering font colours and
> another not is a calendar branded in some places and not others, and nobody
> notices until it is everywhere. `wp_editor()` has exactly one caller and the
> toolbar exactly one definition, asserted by
> `.claude/rich-text-test.php`.

**The browser gets the toolbar from the server**, as JSON printed by
`settings_json()`. FAQ rows are cloned from a template after load, so their
editors are started by `wp.editor.initialize()`, and a second copy of the
toolbar written into `portal.js` would be the same drift in a new place.

> **THERE ARE TWO WAYS AN EDITOR STARTS HERE, AND ONLY ONE OF THEM CAN FAIL
> QUIETLY.** `SFAF_Rich_Text::render()` calls `wp_editor()`, which renders the
> control while the page is being assembled and starts itself. Its four callers
> are the event description, the series description and the description on each
> public form. `SFAF_Rich_Text::deferred()` prints a plain
> `<textarea data-uc-rich>` that only `initRichText()` in `portal.js` can turn
> into an editor. **Every FAQ answer on every screen takes the second path**,
> including rows that exist when the page is built, because they all come from
> `sfaf_faq_row()`. `deferred()`'s own docblock says it is for cloned rows and
> for the template, which is not what it is used for.
>
> That is why a screen can show a working description above a column of FAQ
> answers displaying raw markup: they are not one mechanism, and nothing about
> the first one working says anything about the second.

**Email bodies do not get it.** An HTML email is not a browser: clients strip
`<style>`, ignore most of what they do not strip, and the plain text
alternative has to carry the same message with no markup at all. The bodies are
also token templates, and a token wrapped in markup by an editor stops matching.
They stay plain text, which both halves of a message can carry.

> **Turning plain text into HTML breaks everything that reads it as a value.**
> `strip_tags()` and `wp_strip_all_tags()` join the text either side of a tag
> with nothing between, so two paragraphs become "OneTwo". 3.38.0 found that in
> `wp_trim_words()` before it reached the cards. `sfaf_flatten_html()` puts a
> space where the block tag was, and is the only way prose becomes text here.
> The test refuses any statement that joins a prose field with one of the three.

**What existing content does:** nothing. Everything stored is plain text with
line breaks, and every display path runs `wpautop()`, so it reads as the
paragraphs it always looked like. Nothing is migrated.

**Imported FAQ answers were being stripped on the way in.** `SFAF_Sources`
sanitised them with `sanitize_textarea_field()`, which removes every tag, so a
platform sending HTML had its paragraph boundaries destroyed before storage.
They are kept as markup now. The first fetch after upgrading reports those rows
as changed once, because the stored string genuinely changes.

### There is one image picker, and it is a renderer rather than markup

`render_image_picker()` emits every featured-image control in caladmin: the
event editor's, the pending queue's and the series screen's. `image_picker_atts()`
puts the calendar-folder attributes on the wrapper. Nothing else emits either.

**It was two copies, and one of them silently stopped working.** The picker was
rebound from `getElementById('uc-featured-image-id')` to data attributes so the
pending queue could render several on one page. The event editor's copy was
updated; the series screen's was not. `bindImageField()` requires
`data-uc-image-id`, `data-uc-image-preview` and `data-uc-image-preview-img`,
found none, and returned before binding the click, so Choose Image had no
handler at all.

> **The enqueue was the obvious suspect and was not the fault.** caladmin builds
> its own document, so `wp_enqueue_media()` is a per-screen decision and 3.38.0
> had exactly that shape with TinyMCE. Every screen here was already enqueueing
> correctly. **A control that does nothing is not evidence about loading**: a
> script can be present and still never bind. Start from what the binder needs
> and check the element has it.

> **Rebinding the broken copy would have been worse than leaving it.** 3.42.1
> had already filtered the working copy to the calendar folder and routed its
> uploads there. A fixed second copy would have offered the whole media library
> from one screen and the folder from another, which is harder to notice than a
> button that does nothing.

`.claude/image-picker-test.php` **reads the required hooks out of `portal.js`**
rather than restating them, asserts they are emitted in exactly one place,
asserts every wrapper carries the folder attributes, and walks the call graph so
that a sub-renderer is not asked to enqueue what its caller already did.

### The category palette is closed, and how a colour joins it

**SIXTEEN COLOURS: THE GUIDE'S TEN, AND SIX SHADES OF THEM ADDED IN 3.75.0.**
Shades rather than new hues, because the brand guide is explicit that the
palette is the palette and p.18 permits darker and lighter variations of what
is already there. `sfaf_sanitize_brand_color()` is enforced on save, so an
off-palette hex cannot be stored from any screen of ours.

**TWO FLOORS, AND EACH PROTECTS A DIFFERENT SURFACE.**

**The icon floor, 4.5:1.** `sfaf_event_placeholder_svg()` fills a rectangle with
the RAW category colour and draws the icon and the label on whichever neutral
wins, which is `sfaf_on_color()`. A colour with no neutral over 4.5:1 is one
where both are washed out. **Red and Pink are exempt by name**: they are the
guide's own colours, they predate the rule, and they are already documented as
large-text-only, which the 92px bold label is. A new shade has no such standing.

**The ink floor.** The chip, the ring and the small tile never use the raw
colour; they use the ink from `sfaf_category_shades()`, which has to clear
4.5:1 on white, on the page, and **on its own chip tint**. That last one is the
contrast the shades table exists for: a pair that clears 4.5:1 on white can
still fail on its own tint, which is exactly what the old 12% chip did at
2.05:1.

**AND A DISTINCTNESS FLOOR, WHICH IS NOT A CONTRAST QUESTION.** CIE76 in Lab,
floor 25, every pair against every other pair. Two hexes can differ in every
digit and look identical; a shade nobody can tell from its parent is a choice
nobody can make in a swatch picker and nobody can read on a card. The floor is
chosen against the 28px swatch the picker actually renders at.

> **A 3:1 NEUTRAL CHECK IS UNFAILABLE AND THE FIRST DRAFT OF THIS AUDIT HAD
> ONE.** Clearing 3:1 against Dark Gray needs a luminance under 0.2155;
> clearing it against white needs over 0.30; nothing can be in both bands. The
> worst any colour can do is **3.44:1** at the crossover, so "every colour
> clears 3:1" was a statement about arithmetic rather than about the palette,
> and it would have passed any shade anybody ever added. Caught by the audit's
> own self-test, which is the whole reason a checker gets one. See §7.

**`.claude/palette-audit.php` is where all of this lives**, including a
`--propose` mode that generates candidate shades of each approved hue and
measures them, so the next addition starts from numbers rather than from a
screenshot.

**THE FIRST CATEGORY ALPHABETICALLY SUPPLIES THE COLOUR AND THE ICON.**
`sfaf_event_categories()` sorts by name with `strcasecmp` and
`sfaf_event_primary_category()` takes the first, so an event in two categories
is drawn in the earlier one's colour. This is worth knowing because **Español
sorts before every other category this calendar has** (Fundraising, Health
Services, Program Groups, Support Groups, Volunteer), so a Spanish-language
support group is drawn as Español and not as a support group. That is a real
consequence of a real rule and not a defect; whether a LANGUAGE belongs as a
category at all is an open question and is in §8.

### Which view a block opens on, said in four places

**THE MONTH GRID SINCE 3.75.0, AND IT WAS THE LIST.** The reason it was the list
is recorded and was a real one: real months have entire weeks with no Friday or
Sunday events, and an empty-looking grid is a poor first impression. That was an
argument about a thin calendar, and the calendar is not thin any more.

**FOUR PLACES DECIDE IT AND ALL FOUR HAVE TO AGREE**, because a block with no
view of its own is drawn by whichever route reached it:

```
class-sfaf-shortcodes.php   the shortcode's attributes
class-sfaf-shortcodes.php   the shortcode's render arguments
class-sfaf-embed.php        the REST route's parameter
public/js/embed.js          the fallback in viewFor()
```

One left behind would make the same block open as a grid on the page and as a
list in the feed. `embed-modes-test.php` READS all four and requires them equal
rather than asserting a value, so changing the default deliberately is one edit
in four places and passes, and changing it in three does not.

**A VISITOR'S OWN CHOICE STILL WINS.** embed.js remembers which view somebody
last pressed, keyed per block, and a remembered choice beats the configured
default. So this decides what a FIRST visit opens on and nothing about a
returning one. Sidebar and combined are configurations rather than choices and
are never overridden.

### The month grid's hover preview, and the top layer again

**HOVERING A TILE SHOWS THE REST OF THE EVENT**: the picture, the date, the
times, where it is, and what the tile does.

**IT EXISTS IN BOTH `calendar.js` AND `embed.js`, BYTE FOR BYTE**, between
`SFAF-PREVIEW-START` and `SFAF-PREVIEW-END`, and the build fails if the two
copies differ by a character. 3.75.0 shipped it in `calendar.js` alone, which is
the shortcode's script, and **this calendar has no front end on resources: it is
only ever an embed**. So it never ran. The markup was never the problem, because
both routes call the same `render_month_grid()`.

**A shared third file was rejected.** On a third-party page it means a second
network request, injected by a script already deriving one URL from its own
`src`, with an ordering question attached. The recurrence engine solved the same
problem with markers and a cross-check, and this is that arrangement.

**Which is why the block uses no jQuery and nothing from either file's scope**:
plain `addEventListener` and `closest()`, and `focusin`/`focusout` rather than
`focus`/`blur`, because neither of the latter bubbles.

**IT IS A POPOVER, WHICH IS THE NON-MODAL DOOR INTO THE TOP LAYER.** 3.70.1
established that a floating panel on resources.sfaf.org cannot win on z-index,
because an ancestor has a transform and therefore owns both the containing block
and the stacking context. `showModal()` and `showPopover()` are the two doors
into the layer that is above every stacking context by definition, and only one
of them is right here: `showModal()` moves focus, makes the rest of the page
inert and closes on Escape, all of which is correct for a registration form and
absurd for something that appears because a mouse passed over a tile.

**SO THERE IS NO z-index IN ITS STYLESHEET**, and `.claude/hover-preview-test.php`
fails if one appears. In the top layer a number is inert; out of it, a number
loses. A z-index here would be a thing somebody later RAISES in the belief that
it is doing something.

**NO FALLBACK.** A browser without `showPopover()` gets no preview and keeps a
tile that is still a link. An enhancement that is absent is honest; one that
renders underneath the theme's header is not.

**DESKTOP ONLY, ASKED OF THE DEVICE.** `(hover: hover) and (pointer: fine)`,
never a width: a wide touch screen still cannot hover, and a hover preview on a
touch device fires on tap, which turns a one-tap link into a two-tap one. The
script refuses to build any of it and the stylesheet is guarded as well, because
a stylesheet that is only right when a script agrees with it has a second
dependency nobody can see.

**ONE PANEL FOR THE PAGE, FILLED FROM ATTRIBUTES ON THE TILE.** A busy month
runs to sixty events, and sixty hidden panels is sixty images a browser may
decide to fetch for a preview nobody opens. Every value on the tile is already
on the page somewhere, so nothing there is a second source of truth.

**THE UA POPOVER BOX HAS TO BE OVERRIDDEN IN BOTH DIRECTIONS.** Every
`[popover]` carries `inset: 0` and `margin: auto` from the user-agent sheet, so
setting only `top` and `left` leaves right and bottom pinned and the auto
margins centre the panel in the viewport, ignoring where it was placed. Same
shape as the note on the modal's own UA box.

**THE PANEL HAD NOTHING BEHIND IT (3.76.0).** It shipped with
"View Event Details" as a `<span>`: a pill that looked like a button, was not
one, and had nothing behind it. **The address was never missing** and no
attribute was needed, because the tile the panel describes IS the `<a>`; what
was missing is that nothing read its `href` and nothing in the panel was
clickable.

**TWO TARGETS: THE PICTURE AND THE PILL (3.78.0), AND ONE ANCHOR ROUND
EVERYTHING WAS THE WRONG ANSWER.** 3.77.0 wrapped the whole panel, and the
consequence was visible: every line inside took the anchor's computed colour, so
the theme's link teal tinted the box and the date, the time and the place all
read as links. **A preview whose every line looks clickable says less than one
with two things that are.**

**The title is not a target either**, deliberately: a title that is a link in a
panel where the date beside it is not is the same confusion in miniature, and
the tile underneath is already a link on the title.

**Both destinations come off the tile**, copied rather than re-derived and
applied from ONE loop, so the two cannot disagree with each other or with the
tile about where they go or how they open.

**Nothing else in the panel inherits a link colour**, checked rather than
assumed: the title, the three fact lines and the cancelled line have always
stated their own, the pill states its own pair, and the picture's link holds no
text at all.

**`tabindex="-1"`, AND THE PANEL STAYS `aria-hidden`.** A focusable element
inside an `aria-hidden` container is invisible to a screen reader and still a
tab stop. A keyboard user reaches the TILE, which opens this and carries the
same destination, so a stop in here would be a second per event and sixty extra
on a busy month. The mouse gets a target; the keyboard already had one.

### The event page on a phone

**THE CARD GOES FIRST, WHICH IS WHERE THE DESKTOP LAYOUT ALREADY PUTS IT.** The
sidebar holds the date, the time, the place and **Register**. The two-column
layout puts it beside the TOP of the content, so a visitor sees when, where and
register before reading anything; stacked into one column it landed after the
whole description and the FAQ, and the primary action on the page was the
furthest thing from the top of it. `order: -1` is the whole fix, and it is the
same reading order expressed in one dimension rather than a new design.

**NOT A STICKY BAR WITH ITS OWN REGISTER.** That was the other option and it is
a second control that has to stay in step with the first: the same event, two
places to say "full", two places to disable, two places to get wrong. The card
moves, and there is still exactly one of everything on it.

**IT STACKS AT 860px, NOT 601px.** The grid is `1fr 320px` with a 32px gap, so
at 601px the content column is 249px and every paragraph is reading at about
thirty characters a line.

### Filling a form in from the last event in a series

**THE SAME OFFER ON TWO SCREENS: ONE DATA SOURCE, TWO APPLIERS.** caladmin's
prefill card has existed since 3.64.0 and the staff request form got the same
control in 3.77.0. Both read `SFAF_Series::prefill_data()`, which is pure
server-side PHP with no logged-in user and no media library in it, so the data
half costs the form nothing.

**WHAT DIFFERS IS THE WRITING, AND IT GENUINELY DIFFERS.** caladmin has a
location-mode radio group, a wp.media hidden id, a rich text editor it controls
and several organizers; the request form has a venue select and a text box,
radios over the calendar folder, a deferred editor and one organizer. So
`initSeriesPrefill()` and `initRequestPrefill()` are two functions with the same
shape and different field maps.

> **A FLAG INSIDE ONE OF THEM WOULD HAVE BEEN THE OTHER WAY, and it is the
> arrangement this project refuses on permission-sensitive renders for the same
> reason.** A function with two field maps in it is two functions sharing a
> body, and the one nobody is looking at is the one that rots. Where two screens
> share the QUESTION and not the CONTROLS, share the data and split the applier.

**NOTHING POSTS, ON EITHER.** Every write lands in a field already on the page.
A control that applied by posting and redirecting discards every unsaved answer
on the form, which is the 3.3.0 fault that taught people not to press the FAQ
set picker. `request-prefill-test.js` asserts there is no submit, no navigation
and no fetch anywhere in the function.

**THE DATE IS NEVER FILLED IN**, on either, and neither is the title. Setting
the date is the reason somebody is on the form, and a prefilled one is a past
date pretending to be a new event.

**A FIELD ALREADY ANSWERED IS MARKED, NOT SKIPPED.** The row stays ticked and
says it would replace what is there. The information is the point and the
decision is the requester's; skipping it silently would be the software deciding
that what they typed was more correct than what they just asked for.

**AND IT IS PROVED BY RUNNING IT.** The caladmin card sat dead for twenty-six
releases because a variable was used before it was assigned: the call was
present, the file parsed, the callable audit matched, and nothing drew.
`.claude/request-prefill-test.js` executes the real function against a document
and reads back both what it rendered and what it wrote, and
`request-form-test.php` holds that document to the shape the form actually
renders. **Neither is worth much alone.**

### The featured image picker offers one folder, matched on the file path

Event photographs live in `wp-content/uploads/calendar/`, a folder made with WP
Media Folder. The caladmin picker offers **only** what is in it, so every
selectable image is already 16:9 and already the right weight, and nobody has to
remember the rule. `SFAF_Media_Folder` owns it.

**It filters on `_wp_attached_file`, never on WP Media Folder's own API**, and
that is the decision worth keeping. Files in that folder are on disk there, so
core's own attachment meta reads `calendar/latino.jpg`. Core wrote it and core
maintains it, whatever plugin put the file in the folder.

> **Depend on the metadata, not on the plugin that produced it.** If WP Media
> Folder is removed, every file stays put, the meta still starts with
> `calendar/`, and the picker needs no change. What is lost is the ability to
> MANAGE the folder, which is the plugin's actual job. Going through its
> taxonomy would have made a third-party plugin a load-bearing dependency of the
> event editor.

The match is **anchored** (`REGEXP '^calendar/'`), so `photos/calendar/x.jpg` is
not a calendar image. `path_is_inside()` is the same rule in PHP and the test
runs both over the same cases, because two implementations of one rule drift.

**The gate reads `$_REQUEST`, not the filtered arguments.**
`wp_ajax_query_attachments()` intersects the incoming query against a whitelist
of core's own keys BEFORE `ajax_query_attachments_args` fires, so a custom flag
is gone by the time the filter sees it. Reading the filtered array would find
nothing and the picker would silently show the whole library.

**Narrowing is opt-in per request, and it has to be.** Both hooks are global.
`upload_dir` runs for every upload anywhere and `ajax_query_attachments_args`
runs for the WordPress media library itself, so an ungated version would hide
most of the site's images from somebody writing an unrelated page. That failure
is silent and would not look like the calendar's doing.

**A PICTURE UPLOADED FOR AN EVENT IS TAGGED WITH THAT EVENT'S SERIES** (3.87.0),
and the fault it fixes is worth keeping because the obvious diagnosis was wrong.
An image uploaded from the event editor was reported as going nowhere. It went
exactly where it should: the folder flag rides the uploader's multipart params,
so `upload_dir` fires and the file lands in `uploads/calendar/`.

> **IT DISAPPEARED FROM THE PICKER THAT UPLOADED IT.** wp.media refreshes its
> library the instant an upload finishes, with that frame's own arguments, and
> for an event in a series those include `uc_series`. A picture uploaded two
> seconds ago has no term, so the refreshed query could not return it. Nothing
> was broken; the picker was asking a question the new file could not yet answer.

**THE GENERAL SHAPE:** when a control both narrows a list and adds to it, the
thing it adds has to satisfy the narrowing, or it adds into somewhere it cannot
see. Tagging at upload is what makes that true here, and widening the picker
afterwards would have answered a different question from the one asked.

**Choosing is narrowed; DISPLAY never is.** An event whose image predates the
folder, or was set in the WordPress editor, renders exactly as before, and the
URL field still takes any address. The test asserts that no display path
mentions `SFAF_Media_Folder` at all, because the day one does is the day older
events start losing pictures.

Uploads made from caladmin land in the folder, deliberately overriding the
year-and-month setting: the folder IS the organisation for these, and a date
directory underneath would scatter the same pictures across twelve places a
year. Without it an upload would be invisible to the picker that made it.

### A picture's name, and when the heuristic is not consulted

**EVERY PICKER SHOWS A PICTURE'S TITLE WHERE IT HAS A REAL ONE AND ITS FILE NAME
WHERE IT DOES NOT.** `SFAF_Media::row()` decides which, and 3.65.0's
`looks_like_a_filename()` is what refuses a title WordPress derived from the
file on upload: "Dsc 0043" is not a name anybody chose and is worse than showing
`dsc_0043.jpg`.

**A NAME TYPED ON THE IMAGES SCREEN IS TRUSTED WITHOUT BEING ASKED ABOUT.**
`rename()` writes `META_NAMED` and `row()` skips the heuristic for a marked
title. Clearing the box clears the mark, so emptying a name really does put the
row back to its file name.

**WHY THAT MARK EXISTS, because it looks like belt and braces and is not.** The
heuristic compares the title against the file with separators turned to spaces,
so a name somebody typed that MATCHES its file was thrown away:

```
"Cycle To Zero"             on  cycle-to-zero.jpg             thrown away
"Strut SFAF San Francisco"  on  strut-sfaf-san-francisco.jpg  thrown away
```

A well-named file is usually named after the picture, so that is the commonest
case rather than an edge. Without the mark, the name box added in 3.76.0 could
be used, saved, and have no effect, which is the remedy for a complaint not
working on the complaint. See §7.

**THE SCREEN SAYS WHY A CARD SHOWS A FILE NAME**, in one line pointing at the
box rather than explaining the rule, plus a marker on the cards where it is
true. It had been reported as a fault twice.

### The image library, and tags that are series

**WHY IT EXISTS.** Contributors and editors never see wp-admin, so the WordPress
media library is not available to most of the people who maintain this calendar.
An organizer who wanted to know what pictures already exist for their programme
had nowhere at all to look, and the only route to an image was the picker inside
an event, which shows the whole folder and cannot say which programme anything
belongs to.

**THE TAGS ARE THE SERIES TAXONOMY, NOT A SECOND VOCABULARY.** `uc_series` is
registered for attachments as well as for events, so an image carries the same
terms an event does. There is no tag to create, no list to keep in step and no
way for the two to disagree about what a programme is called: a new series makes
a new tag available the moment it exists, and renaming a series renames the tag.
An image may carry several, because a photograph of a group at a clinic is
genuinely the picture for two programmes.

**DELETING A SERIES DOES NOT TOUCH THE IMAGES, AND THIS IS HOW THAT IS
GUARANTEED RATHER THAN INTENDED.** `wp_delete_term()` deletes the term and its
rows in `term_relationships`. It does not read, write or delete a single post,
and an image is a post. That is core's half.

**Our half is that nothing in this plugin may hook a term deletion and go
looking for attachments**, which is the half a future build could break, by
adding a tidy-up that deletes "orphaned" images. `.claude/media-tags-test.php`
reads every term-deletion callback in the plugin with a tokenizer and requires
that none of them calls anything that writes a post. One IS registered,
`SFAF_Embed::flush_cache_for_term()`, and it writes none.

**What is left is an image with no row in the taxonomy at all**, which is exactly
what the **Untagged** filter asks for, so it surfaces rather than disappearing.
That filter is the one that matters most: a library gets organised by somebody
being shown what has not been done yet.

**AND THE SERIES ARCHIVE STAYS EVENTS ONLY.** `uc_series` is public and has a
rewrite, so attaching a second object type to it would put images into whatever
the theme renders at `/event-series/<slug>/`. `SFAF_Media::keep_archive_to_events()`
sets `post_type` back to `uc_event` on that archive's main query, and only on
the main query, because a `tax_query` somebody else built is theirs.

**THREE PERMISSIONS, DELIBERATELY NOT THE SAME SHAPE.**

```
UPLOAD   administrators
TAG      administrators and editors
PICK     everybody with caladmin access
```

An editor can tag images they cannot upload. That is intended: organising a
library and adding to it are different jobs, tagging is reversible and changes
nothing on any page, and an upload puts a file on the server forever.

**THE UPLOAD GATE IS A CAPABILITY FILTER, NOT A HIDDEN TAB.** portal.js hides
wp.media's Upload Files tab for anybody who is not a calendar admin, and a hidden
tab is a hidden tab: the upload endpoint is a URL and a POST to it is a request
anybody can construct. `SFAF_Media_Folder::gate_upload()` is the refusal, and it
is narrow in two directions. It only answers when the request carries the
picker's own flag, so an upload made anywhere else on the site is untouched, and
it only ever REMOVES the capability, so it cannot hand `upload_files` to somebody
who does not have it. **It is not a role write**: nothing calls `add_cap`,
`set_role` or touches `wp_capabilities`, and the answer is gone when the request
ends.

### One picture chooser, and where each kind of file lives

**THE SELF-BUILT PICKER IS `SFAF_Media::picker()` AND BOTH PUBLIC FORMS CALL
IT.** It is a `<details>` of radios with a search box portal.js reveals, built
for the staff request form in 3.67.0 and moved here in 3.74.0 when the community
form needed the same control. Neither public form has a logged-in user, so there
is no wp.media to open and no capability to open it with; radios in a `<details>`
work with no script at all, which on a page reached by a link on somebody's phone
is the difference between a control and a decoration.

**IT LEADS WITH THE EVENT'S OWN SERIES AND LISTS EVERYTHING ELSE UNDER IT.** Two
groups in one list rather than a toggle, because a toggle needs script and hides
half the library behind a control somebody has to discover. The series names are
part of what the search matches.

**THE CALADMIN PICKER IS STILL wp.media**, because there IS a logged-in user
there and the library's own search and upload are worth having. It opens on the
event's series, with **All calendar images** beside it as the way out; the term
id rides the library query the way the folder flag already does, and
`SFAF_Media_Folder::restrict_query()` reads both off the raw request for the same
reason.

**WHICH FOLDER A FILE LANDS IN IS THE WHOLE DECISION.**

```
calendar/               curated, approved, offered by every picker
calendar-submissions/   working copies from people with no account
```

**A picture chosen from the calendar folder becomes the event's thumbnail
straight away**, on both public forms, because it is already approved and already
the right shape. **An uploaded one does not**, because it is a working copy in a
folder meant to be emptied, and nothing may point at it permanently. "Use this
image" on the pending row is how one gets promoted.

**`SFAF_Uploads::inspect()` is the one guard between a form and the disk.** Is
there a file, did PHP finish it, is it really an upload, is it small enough, do
two readers agree it is an image, and is it wide enough for a card. It was split
out of `store()` unchanged in 3.74.0 so caladmin's own upload gets exactly the
same checks and a different destination. **Two copies of that is the one
duplication worth refusing outright.**

**What differs between the two callers, and only this:** where the file goes,
what it is called and who it belongs to. A stranger's file name is discarded and
replaced; a name somebody typed in caladmin is kept, because it is what the
picker's search matches and what tells two photographs of the same event apart
at 64px.

### The event editor's actions follow the event's state

**EVERY EVENT USED TO GET THE SAME TWO BUTTONS**, Save and Publish, in every
state. On something already published those are two labels for one outcome,
because `keep` keeps `publish` and `publish` sets `publish`. Two controls that
look like a choice and are not teach people to stop reading the pair, and the
pair is what a draft genuinely needs.

```
published, scheduled   Save changes
pending submission     Save changes, Approve, Reject      (calendar admins)
pending, not a sub     Save changes, Publish
draft or new           Save draft, Publish
```

**ON A PENDING SUBMISSION THE OLD PAIR WAS WORSE THAN REDUNDANT.** Publish took
the route a draft takes and put the event on the public calendar, and that is
**not** the route the queue's Approve takes: no address joined the notification
list and no published notice was sent. Somebody who reviewed a submission
properly, by opening it and reading everything, published it in a way that told
the person who sent it nothing at all, and could not reject from that screen
because Reject was not on it.

**THE EDITOR POSTS THE QUEUE'S OWN TWO FORMS.** Same action, same nonce, same
prompt, same two ticks. The buttons carry `form=` because a form may not nest
inside the event form, which is the same reason the ticks in the prompt already
do. **There is no second way to approve**, which is the point rather than a
detail.

**WHO DECIDES A SUBMISSION IS WHO DECIDES ONE ON THE QUEUE**, and that is a
calendar admin. Both routes `wp_die()` on anybody else, so offering the decision
to somebody the route would refuse is a button that fails. An editor still saves
and still reads everything. A pending event that is NOT a submission is a
different thing, a contributor's own event waiting for review with nobody outside
to tell, and it keeps the Publish it has always had.

**NO UNPUBLISH.** Cancelling is how an event comes off the calendar and it tells
the people who registered. A second quiet route to making one disappear is a way
to do that by accident. Weighed and decided against; if the need arises it gets
built deliberately.

**DELETE IS A CARD AT THE FOOT OF THE SCREEN, AFTER THE CANCEL CARD.** Not in the
row of actions, because Save is pressed dozens of times a day and deleting cannot
be undone from that screen. After cancelling rather than before, because the two
read as a ladder in the order somebody should try them: cancelling keeps the
registrations, closes new ones and offers to tell everybody who signed up;
deleting keeps nothing and tells nobody. It posts the same route the events list
posts, so it inherits the same refusal on an event with registrations that has
not been cancelled, and says why rather than offering a button that bounces.

### Descriptions are rich text, with a deliberately short toolbar

Event descriptions are `post_content` and have been plain text until 3.38.0.
They are now `wp_editor()` in teeny mode with **bold, italic, links, both list
kinds and one heading level**, and nothing else. No font colours, no sizes, no
alignment: the brand guide governs colour and type, and a full toolbar is how a
calendar ends up with events in purple Comic Sans that cannot be unpicked
because the styling is inline on every paragraph.

**The one heading is h3.** The event page's title is the h1 and its sections sit
at h2, so a heading typed into a description has to start below both or it
breaks the reading order for anybody navigating by headings. `block_formats`
offers exactly Paragraph and that level, so a competing level cannot be chosen.

**It degrades to a textarea.** caladmin builds its own document rather than
running through `wp_head`, so TinyMCE is being started somewhere it usually is
not. If its scripts do not run, `wp_editor()` leaves a plain textarea holding the
same content, and `wp_kses_post()` on save means the stored value survives
either way.

**Nothing carries over badly and nothing was migrated.** `wp_editor()` and the
event page's `the_content()` both run stored content through `wpautop()`, so a
plain-text description written before this reads as the paragraphs it always
looked like.

**Emails are unaffected, because the description is not in any of them.** The
confirmation, reminder, alert, summary, cancelled and changed messages are built
from the event's title, date, time and location plus an optional per-event
`custom_body`. `SFAF_Notifications` never reads `post_content`.

**What rich text did break, and what fixed it.** `wp_trim_words()` strips tags
with `strip_tags()`, which joins the text either side of a tag with nothing
between: `<p>One</p><p>Two</p>` becomes `OneTwo`. Card summaries have always been
plain text so this never bit, and it would have bitten every card on the public
calendar and in every embed the day this shipped. `sfaf_flatten_html()` turns
block tags into spaces **before** stripping, which is the only order that works.

### "Has this field been filled in" is asked in two languages, off one list

`SFAF_Sources::completeness_fields()` is that list. The server reads storage
through `field_is_filled()`; the browser reads the FORM, because the warning has
to clear as somebody types rather than stating what was true at page load.

**The entry's `inputs` is the `name` attribute exactly as the editor emits it,
brackets and all.** That is the string a DOM selector needs, and the `$_POST`
key is that minus a trailing `[]`, derived by `post_key_for_input()`.

> **3.49.1: THE LIST HELD THE POST KEY, AND THE TWO ARE THE SAME STRING FOR
> EVERY FIELD EXCEPT THE TWO THAT TAKE SEVERAL VALUES.** Categories have been
> multi-select since 3.8.0 and organizers since 3.40.0, so the controls are
> `category[]` and `organizer[]`. `[name="category"]` matched nothing, the live
> check took its "these controls are not on this form" branch, and that branch
> answers from stored state. On an imported event nobody has saved, stored state
> is empty, so ticking every box on the screen never cleared the warning.
>
> **The fallback branch is what made it silent.** A selector matching nothing is
> indistinguishable, at runtime, from a field that genuinely is not on this
> screen, and the second is a real case worth keeping. So the branch stays and
> the TEST carries the weight: `.claude/completeness-test.php` renders the real
> manager controls and asserts every declared input matches a real name
> attribute. Nothing else can see the mismatch, because neither file is wrong on
> its own.

**A rich text field does not keep its value in the control that carries its
name.** TinyMCE holds the content in an iframe and writes it back to the
textarea at submit, so reading `.value` gives the page-load value forever. The
browser side asks the editor when one is running and not hidden, and binds to
the editor's own events, because typing in an iframe fires nothing on the form.
A hidden editor means the plain-text tab is showing and the textarea is then the
truth.

**A checkbox carries its value whether or not it is ticked.** `checked` is the
only thing that answers for a checkbox or a radio, and asking a category box for
`.value` returns a term id, which reads as filled. That was not the shipped
fault but it was one edit away from being the next one.

**The three shapes are named in the engine's own comment**, between the markers
`.claude/completeness-test.php` slices it from. Keep the markers.

### There is no way to save a half-finished imported event

**An omission, not a decision, and it is worth knowing before somebody loses an
afternoon.** The editor's left button is decided by `$keep_status`, which is
true for `publish`, `pending` and `future`. An imported event sits in the custom
`uc_imported` status, which is in none of those, so the button is **"Save
Draft"**, and `save_mode=draft` sets the status to `draft`.

**What that costs.** `SFAF_Sources::queue_ids()` matches `uc_imported` and
`uc_dismissed` only, and the unified pending list of 3.49.0 merges
`uc_imported` with `pending`. A draft is in neither, so saving work in progress
takes the event **out of the queue it was being reviewed in**, and it is then
findable only through the Events list under a status filter.

**NOTHING IN CALADMIN WARNS ABOUT UNSAVED WORK, ANYWHERE.** There is no
`beforeunload` handler in `portal.js` at all, so closing a tab, following a link
or pressing Back mid-edit discards everything typed, silently, on every screen
rather than only on this one. It is worth stating as a property of the portal
and not of imports: the imported event is simply where it costs the most,
because there is no way to save that work in place either.

**And nothing protects the alternative.** There is no `beforeunload` handler
anywhere in `portal.js`, so a manager who fills in half an imported campaign and
closes the tab, or follows a link, loses everything typed with no prompt.

> **The one-line version of the fix is adding `uc_imported` to `$keep_status`,
> and it is deliberately NOT in 3.49.1.** It changes which events stay in the
> queue after a save, which is a behaviour change rather than a bug fix, and it
> wants deciding rather than slipping into a patch release. The queue's own
> manager panel already saves these fields in place, through
> `save_manager_fields`, without touching the status, so the capability exists;
> what is missing is the same thing from the full editor.

### The pending queue is one list, and the filter is not the badge

**One list, with controls over it (3.49.0).** It used to stack three blocks:
imported events, dismissed imports, then a separate table headed "Submitted for
review". Three blocks meant knowing which block a thing would be in before you
could look for it, and the two that held actual work were ordered by different
rules and drawn two different ways, one a list of rich rows and one a
five-column table. **Nothing about "this needs a decision" differs between an
import and a submission**, so nothing about the row does either.

**Kind and shape are different questions and are answered separately.**

| | What it answers | What decides it |
|---|---|---|
| `kind` | what this IS | `SFAF_Submissions::kind()` |
| `shape` | which actions it takes | which queue it came out of |

An imported event that was published and then set back to pending is kind
`import` and shape `submission`: it is still an import, and Publish and Dismiss
are no longer what it needs. Conflating the two is the same mistake as the three
faults below, one level up.

**The two sources cannot overlap.** Imports sit in the custom `uc_imported`
status and submissions in WordPress's own `pending`, so the queries are disjoint
by construction. The id is still the array key, because "cannot overlap" is a
fact about today's statuses and a row printed twice is a worse failure than one
missing.

> **A ROW WHOSE KIND MATCHES NO FILTER IS STILL UNDER "Everything".** An event a
> contributor set to pending by hand is kind `local`, carries no badge, and
> belongs to none of the three filters. It is in the unfiltered list, and that is
> the whole point: **the one thing that must never happen on this screen again is
> a pending row that is in no list at all.**

**It opens newest first, and that is a decision about what this screen is.** A
work list is not a calendar. What arrived most recently is what nobody has
looked at yet, and it matters more than what happens soonest: an event three
months out submitted an hour ago needs a decision, and an event next week
reviewed yesterday does not. The Events list sorts by when things HAPPEN because
it answers a different question. Event date is offered as a second sort, and
**anything dateless sorts last in both directions**, because imports arrive
without one by design and reversing a sort must not park all of them on top.

**Dismissed is still its own card, below, and that is not an exception.**
Dismissed is a STATUS, not a kind. Those rows have had their decision taken and
are kept only so a fetch never offers them again, so folding them into a work
list would put things nobody must act on among things somebody must.

**The badge identifies and the filter narrows; they are not alternatives.** The
kind badges from 3.46.0 stay on the rows.

**One "still needs something" state, and it covers submissions too** (3.68.0).
The amber row and the pencil began as the imports' state: a GoFundMe Pro
campaign arrives needing an image and a description **every time, by design**,
which is why the mark is amber and a pencil rather than red and an exclamation.
It answered nothing at all for anything else, because
`SFAF_Sources::missing_manager_fields()` returns `array()` for an event with no
source, by its first guard.

- **A submitted event can arrive with no location.** Neither public form
  requires one, and the staff form will accept a request with neither a venue
  nor a typed address. **That stays deliberate:** both forms land here and a
  manager decides before anything is published, and a field somebody cannot
  answer means an abandoned form rather than an incomplete submission.
- **So the state was extended, not duplicated.** `missing_fields()` takes a
  named list and `missing_manager_fields()` delegates to it, so the mark, the
  amber row and the wording all still come from `field_phrase()` and
  `field_is_filled()`. A second mechanism would be a second answer to "what is
  this event still missing".
- **An online event is not missing a location.** `SFAF_Online::set_online()`
  deletes `_uc_location`, because there is nowhere to be, so the bare meta test
  would have marked every online event forever. That correction applies to the
  import path as well, where the same thing was true and nobody had met it yet.

> **THIS QUEUE HAS HAD THREE MARKING OR FILTERING FAULTS AND NOT ONE WAS VISIBLE
> IN SOURCE.** A badge that asked "does it have a request email" when both forms
> write one; a query that asked "did you write it" of a post nobody wrote; and
> the same queue scoping itself to the current user. Every one shipped with
> checks in place that proved a string existed somewhere.
>
> So `.claude/pending-queue-test.php` **renders the screen** and reads the rows
> back out of the HTML by their `data-uc-id`, asking the question a person asks:
> is my thing in this list, and is it under the right tab. Six faults were
> planted to prove it can fail, including the two real ones above. All six were
> caught. See the note in §1 on what a rendering test can and cannot prove.

### Bulk actions on the events list, and the one rule that decides publishing

The events list carries **two** bulk actions, added in 3.73.0 and 3.79.0: add a
category to the ticked events, and publish the ticked drafts. They share one set
of tick boxes and they do **not** reach the same rows, and that asymmetry is the
whole of what there is to understand here.

**ONE SET OF TICKS, BECAUSE A CHECKBOX ASSOCIATES WITH EXACTLY ONE FORM.** The
boxes sit in table rows that already contain their own forms for Duplicate and
Remove, and forms cannot nest, so they are associated by the `form=` attribute
instead and `form.elements` is what reads them back. A second action cannot
bring a second form without bringing a second column of boxes. So `uc_action`
names the form and `uc_do` names the button: one route, one nonce, two verbs,
and anything that is not `publish` files under a category, which is the route
that changes no status.

**THE CATEGORY CONTROL REACHES EVERY ROW THE VIEWER CAN EDIT.** Past events,
imports and submissions included. Filing changes how an event is **filed**; it
publishes nothing, and on an event that is not public it reaches nobody. Each
publish exclusion was asked about separately rather than copied across, and the
reasoning is in the block above `SFAF_Portal::bulk_plan()`.

**PUBLISHING IS DECIDED BY ONE METHOD, AND IT IS NOT ON THIS SCREEN.**
`SFAF_Series::publish_skip_reason( $id, $today )` refuses a past date, an event
with no date, a submission awaiting review, anything carrying source provenance,
and an import parked as a draft because it vanished at its source. It was
written for the schedule screen in 3.71.0, and it is written **per event with
the date passed in**: it asks nothing about a series and takes no term id, so it
was already the general rule and the events list is simply its second caller.

> **A second copy is the thing to refuse.** Two screens that each decide what
> may reach the public calendar will drift, and the drift is invisible until
> something is published that should not have been. `.claude/bulk-ticks-test.php`
> asserts that both callers **call** it and that neither restates any of the
> five tests itself.

**WHY IT IS ON THIS SCREEN AT ALL.** 3.71.0 put bulk publish on a series'
schedule, which works one series at a time. The import left 287 drafts across 32
series, and 32 visits to 32 screens is the thing the button exists to stop.

**THE TICK STAYS ON ROWS THAT CANNOT BE PUBLISHED, and this is the one place the
two screens differ.** The schedule screen gives an ineligible row no box at all.
Here the box is shared, so taking it off a past import to protect the publish
button would take the category control's reach away with it. The row says what
it cannot do instead: a **draft** that the publish button may not touch carries
the reason beside its tick. Published rows say nothing, because "not a draft"
over a page of published events is the page restating itself.

**SO EACH BUTTON COUNTS ITS OWN SUBSET.** The category button counts every tick;
the publish button counts only the ticks with no block on them, and disables at
zero even when forty rows are ticked. A button whose number includes rows it is
about to skip is the failure this arrangement exists to prevent, and it is why
`initTickPickers()` in `portal.js` learned about a second submit rather than a
second form. **The server decides eligibility and the script only counts it**:
the attribute on the box is a label on a decision already made, and a box with
it stripped by hand is still refused at the write.

**NOTHING IS TRUSTED FROM THE FORM.** `can_edit_event()` is re-asked per id and
`publish_skip_reason()` is re-asked per id, both after the form has spoken, and
both against one `$today` so a press that straddles midnight judges every row
against one date. The ticks narrow; they never widen.

### The picture picker hides by series, and the two forms filter in different places

From 3.80.0 the public forms' picker shows **only** the chosen series' pictures.
It grouped them before, the series' own first and everything else under a second
heading, and that was the wrong answer to the question: at forty or fifty
pictures a heading tells somebody where to stop reading without saving them the
reading.

**There is no route back to the full list, deliberately.** A route back is the
grouping again with a click in front of it. The cost is real and was weighed:
somebody who wants a picture tagged to another programme cannot reach it. The
remedy for that is the message, not an escape.

> **A SERIES WITH NOTHING TAGGED GETS A SENTENCE, NEVER THE WHOLE FOLDER.** A
> quiet fallback is indistinguishable from the filter not working and was
> reported as exactly that twice. The "no picture" row stays either way, so the
> form can still be sent without one and the approver picks.

**WHERE THE FILTERING HAPPENS DEPENDS ON WHETHER THE SERIES CAN CHANGE**, and
this is the part not to flatten into one path:

| form | series | filtered by | with no script |
|---|---|---|---|
| community | fixed by the URL | the server | correctly filtered |
| staff | a `<select>` | `portal.js` | the whole folder |

Filtering the staff form on the server would be right on arrival and stale the
moment somebody changed the select with scripting off. **A list confidently
showing the wrong series is worse than one showing all of them**, so the server
writes every row out carrying `data-uc-image-series` and the script hides what
does not match. Same rule as the FAQ set peek: nothing is hidden that a script
has to come back and reveal.

**An untagged picture is hidden by every series.** It is not a picture for
everybody; it is one nobody has filed yet.

**TWO FILTERS, ONE LIST, ONE OWNER OF `hidden`.** The search box and the series
narrowing act on the same rows. Both assigning the property is two answers to
"is this row on screen" with the later one winning, so typing in the search box
would restore every picture the series had just removed. The series filter sets
`data-uc-off-series` and asks for a re-run; `initFilterLists()` owns the
property. The same discipline as computing a cascade before rewriting a rule,
one layer up.

**caladmin is not this picker and does not change.** The event editor, the
series screen and the pending queue use `render_image_picker()`, which opens the
WordPress media modal scoped to the calendar folder. There is no series-grouped
radio list there to hide. **It should not gain one**: an admin or editor can tag
an image themselves, so the empty state is theirs to fix in one click, and the
approver of a community submission is precisely the person who needs a picture
from outside the series when the submitter sent none. Hiding would put a round
trip through the Images screen in front of the one person fixing everybody
else's missing pictures.

### A series' picture: what is set, and what is inferred

A series' picture is `SFAF_Series::image_id()`, and from 3.81.0 it has two
sources in a fixed order.

1. **The picture the series was given**, term meta `_sfaf_series_image_id`, set
   on the series screen. This always wins and nothing about it changed.
2. **The earliest picture tagged to that series**, if it has none of its own.

**These were two different facts in two different places and only one was read**
until 3.81.0. Tagging writes a term relationship on the ATTACHMENT, in
`SFAF_Media`'s taxonomy; the series' picture is term meta. So a picture could be
tagged and named against a programme and produce no banner, no picker default
and no event fallback anywhere.

> **It showed on the pre-existing series and not the imported ones**, which is
> what made it look like a fault in the banner rather than in this. Cycle to Zero
> and Strut Community Events were built by hand and somebody set their picture.
> The thirty the import created came from `SFAF_Series::create( $name )`, which
> takes a name and nothing else, so they have no term meta at all.

**A TAG IS A FALLBACK, NOT A SECOND SETTING.** It answers only the case that used
to answer nothing. And it is **the earliest** tagged rather than the newest,
because the fallback has to be stable: "the newest" would mean adding a picture
to the folder silently changed a programme's banner, its picker default and
every event fallback across it, from an action nobody would connect to it.

**Removed pictures are never the fallback**, for the same reason they are in no
picker: a picture the calendar does not offer must not become a programme's
picture by a route nobody can see.

### Remove takes a picture out of the folder. It is not a delete.

From 3.81.0 the Images screen can remove a picture, and the word is chosen:

- **The file is not deleted and neither is the attachment.** What changes is the
  set this calendar offers: gone from the grid, from every picker, and not
  eligible to be a series' fallback. wp-admin's Media Library still has it.
- **A marker, not a file move.** "Out of the folder" could be literal, and moving
  a file has permissions, half-done states and a changed URL, which breaks
  anywhere the URL was copied rather than the id referenced. `META_REMOVED` is
  atomic and is undone by deleting it.
- **A Removed view in the filter is the way back**, and it is what makes the
  action recoverable in practice rather than only in principle.
- **Refused while anything is using it**, with the refusal naming what: an event
  whose own picture it is, or a series it has been given to. Same shape as the
  venue and team deletion rules.

> **A series that merely TAGS it is not using it.** A tag is filing. Removing the
> picture moves that series on to the next one tagged, or to nothing, which is a
> change in what is offered rather than a dangling reference.

### Name and alt text are two jobs, and neither is the other

- **Name** is how somebody FINDS the picture in a chooser. Written for the person
  picking it: "Cycle To Zero".
- **Alt text** stands in for the picture for somebody who cannot see it, and is
  what a search engine reads. Written about the PICTURE: "three cyclists on a
  coastal road".

**ALT TEXT IS NEVER FILLED IN FROM THE SERIES.** A programme's name is precisely
what it must not say. Wrong alt text is worse than none, because a screen reader
announces it as though it described what is there, and the page around it
already says which programme this is. It is stored in WordPress's own
`_wp_attachment_image_alt`, so a picture described in the Media Library arrives
here already filled in.

**There is no caption, and that is a decision.** Nothing in this plugin renders
one, so it would be a third box collecting text no surface reads, on a card
3.78.0 cut from two forms down to one. The two fields above cover being found in
the chooser and being read out or indexed, which is the whole of what was asked
for.

**The definitions live in one block above the grid.** At fifty images a line of
explanation per card is fifty copies of one paragraph.

### The banner is a live preview of the event

The community form has carried the series picture across the top since 3.47.0.
From 3.80.0 it **follows the picker**, on both public forms, so what is at the
top is what the event will look like rather than decoration. `render_banner()`
is one renderer shared by both; what differs is where the picture comes from.

- **No picture means no banner**, and the element is absent rather than empty. A
  grey box saying nothing is worse than a form starting at its heading.
- **AN UPLOAD DOES NOT CHANGE IT.** A file attached on these forms is a working
  copy that lands outside the calendar folder and an approver decides about.
  Putting it in the banner would say it is already the event's picture, which is
  the one thing these forms must not say.
- **So the upload gets a thumbnail of its own and one line.** Somebody who
  attaches a photograph and sees nothing whatever change will believe it failed.
  The thumbnail says it arrived; the line says an approver decides, which stops
  the thumbnail saying more than that.
- **260ms.** Somebody comparing three programmes changes that dropdown three
  times, and a reveal that is a pleasure once is an obstruction by the third.

### The form links are on the dashboard, and they are not a secret

**Nothing in caladmin linked to either public form until 3.49.0**, so sending
somebody one meant remembering the URL. The staff form's is a single query var;
the community form's names a series, so it is a different link per campaign and
exactly the thing nobody should be assembling by hand.

**On the dashboard, not on Pending, and offered to everyone who gets there.**
Pending is admin-only, and a contributor has as much reason to send somebody the
community form. There is no capability check on the control for that reason:
**reaching caladmin at all is the gate**, and neither link is a secret. The forms
are not protected by the obscurity of their addresses. The staff form emails a
token to an sfaf.org address before it shows anything, and the community form is
rate limited and produces a pending row somebody has to approve.

- **The option's value IS the URL**, built by `SFAF_Submit::url()` in PHP.
  Nothing in JavaScript assembles an address out of parts, which would be a
  second copy of that format, in a second language, free to drift from the one
  the form answers to.
- **A `<noscript>` prints every campaign's link.** Without JavaScript the picker
  cannot rewrite the box, so the box would keep showing the first campaign's
  link whatever was chosen: a wrong answer wearing the shape of a right one.
- **No series means no link, and it says so.** The form refuses an unknown slug,
  so an empty picker would produce an address that lands on the "that link is
  not right" page.

### caladmin has its own favicon, and only caladmin

The portal builds its own document, so its `<head>` is the only one in the
plugin and a `rel="icon"` written there reaches caladmin and nothing else.
resources.sfaf.org, the event pages and both public submission forms are
rendered by the theme through `wp_head()` and are untouched.

**It is the calendar glyph this plugin already draws**, in Dark Gray `#373433` on
brand Yellow `#FFD900`, measured 8.92:1, which is already the primary button
treatment. **Bundled in the plugin rather than uploaded**, the same reasoning as
the email banner: it travels with the code and cannot be deleted from the media
library by somebody tidying up.

**SIMPLIFIED FOR 16 PIXELS, AND THE SIMPLIFICATION IS THE POINT.** The sidebar
glyph is a 2 unit stroke on a 24 unit grid with two hanging tabs above the body.
At 16 device pixels the stroke is 1.33px and the tabs are two nubs three pixels
long, and `node .claude/build-favicon.js --preview` shows them smearing into the
head rule and into each other. So **the tabs are dropped and the head rule is
drawn as a solid band**: a filled band survives downsampling that a hairline does
not, and a rounded box with a dark cap is still unmistakably a calendar. The
mark is the same mark; what changed is what it can afford to say at that size.

SVG first and PNG second, in that order, because a browser that understands
`image/svg+xml` takes the first and one that does not ignores it. The 180px PNG
is the iOS home screen icon, which is a real thing managers do with a tool they
open daily.

### Decoration that carries no information

Until 3.38.0 every card in caladmin carried a 3px teal left border with an
asymmetric `4px 12px 12px 4px` radius. An accent that every card has
distinguishes nothing, and a curved coloured edge on a box is the most
recognisable tell of generated UI.

The test for keeping one is not whether it looks nice. It is: **is the colour or
the shape the only carrier of a fact?** If the same information is in the words
beside it, the edge is decoration and goes. Two survive, and
`.claude/decoration-audit.php` is a whitelist of exactly those two:

- **`.uc-single-header`**, the category colour on the event page. The category
  system is the one place in this plugin where colour is the signal: the same
  colour is the chip and the placeholder tile, and the event page names the
  category nowhere in words.
- **`.uc-field-attention`**, which of roughly thirty fields is still empty. The
  publish banner names *what* is missing; only this says *where*.

**AND THE SAME TEST TAKES A COLOUR OFF A SURFACE, NOT ONLY AN EDGE (3.49.0).**
The month grid's thumbnails wore a 2px ring in the category's ink. It passed the
edge audit above, because that audit matches thick coloured edges and a ring is
not one, and it failed this test the moment it was asked: **nothing on the grid
tells a visitor what the colour means.** A key with no legend is worse than no
key, because it invites the belief that something was communicated.

Both the month grid and the sidebar thumbnail now take a **neutral 1px hairline
in `--uc-border`**, on the CONTAINER rather than on the picture, so the photo and
the placeholder cannot drift apart and the declaration is not sitting on the one
element every host stylesheet writes a rule for. Measured 1.26:1 on white, which
is not a contrast failure: the 3:1 floor is for a graphic carrying information,
and a hairline that distinguishes nothing is not asked to.

This **reverses half of 3.31.0**, whose reasoning is kept in `calendar.css`,
`DESIGN.md` §4 and `.claude/category-ring-contrast.php` rather than deleted.
3.31.0 was right that the raw category hue cannot carry a shape, six of ten
measuring under 3:1 on white; it was never established that a colour belonged
there. Both files now enforce the reversal, so the old rule cannot be restored
from its own surviving argument.

## 2. Data model

The reasoning matters more than the shape here. Each of these was arrived at by
a defect, and knowing why is what stops the next person undoing it.

### A series is a taxonomy term, not an event

`uc_series` is a taxonomy. Until 3.0.0 a series was a `uc_event` post that was
simultaneously the template every occurrence was generated from **and** its own
first occurrence.

**A term cannot be an event, and that is the entire argument.** As a post, the
series appeared in the Events list because it *was* an event; deleting it
orphaned every occurrence pointing at it; canceling one week had to be recorded
on the parent as a date because a flag on the occurrence died with the post. The
old code excluded series from event queries in about a dozen places and got it
wrong in some of them. A term has no date, is never returned by a `uc_event`
query, and cannot be found as an event, because it is not one.

A series carries name, description, image and a default FAQ set. **A series with
no events is valid** and is not cleaned up.

In practice there is **one series per repeating event**. The series screen is
where the schedule is edited, and schedule writes are **upcoming-only**.

**What a series lends a new event is COPIED, never linked.** `SFAF_Series::prefill_data()`
gathers the offer for the "Is this part of a series?" card on New Event: the
description, image and default FAQ set from the term, and the location, times,
category and organizers from the series' most recent event, because those are
properties of the events and not of the umbrella. Pressing **Fill these in**
writes them into the fields on the page. Nothing posts, nothing redirects, and
nothing already typed is lost, which is the FAQ set picker's shape and 3.3.0's
reason for it. **The date is never in the payload at all**: setting the date is
why somebody is on that screen.

The consequence to know is that **a later change to the series image no longer
reaches an event filled in from it**. That is intended, it is how the default
FAQ set has always behaved, and **Reset to series image** on the Edit screen is
what undoes it for a single event. Because the picture is the event's own from
that moment, the tag beside **Featured Image** reads "Event-specific" as soon as
the button runs, and the preview shows the picture exactly as it does after
Choose Image (3.64.2).

`prefill_data()` returns `image_url` and `image_preview` and they are different
jobs. `image_url` is the value COPIED into the event's URL field, and it is
legitimately empty when the picture is a chosen attachment, since the id is then
what carries it. `image_preview` is a URL for the SCREEN, resolved from the
attachment when there is one, and is never written into a field. Reading one key
for both is what leaves a library picture with a blank preview.

### The recurrence group is separate from series membership

`_uc_recurrence_group` is a random string marking "these events were generated
together from one pattern".

The two groupings answer different questions and must not be merged:

- **Series** is the umbrella: filtering, browsing, page embeds. It may hold
  *different kinds of event* (an educational session one week, a social the
  next), so it is **never** a target for a bulk edit.
- **Recurrence group** is what "edit all upcoming occurrences" targets, and is
  the only reason the marker exists.

The group ID is a random string rather than a post ID, deliberately: an ID would
imply one event in the group is the important one, which is precisely the
mistake the old parent post was.

**A group is a marker, not a container.** No settings, no screen, no lifecycle.
Remove every event carrying the ID and the group is gone.

Within a group, dates are of two kinds. A date generated by the pattern carries
no marker; a date added by hand carries `_uc_recurrence_extra`. A weekly
Wednesday group may also meet on one Saturday: that Saturday is in the group, a
bulk edit reaches it, a time change applies to it. But a later pattern edit
moving Wednesdays to Tuesdays must **leave it exactly where it is**. Without the
marker the Saturday would be shifted to a Sunday nobody chose.

**An event may be in a series and in NO group at all**, and that is an ordinary
state rather than an edge case: it is what assigning an existing event to a
series produces. Everything holds, because nothing that reads a series ever asks
where the event came from. `bulk_targets()` returns nothing for an empty group,
so no scope choice is offered; `upcoming_in_group()` returns nothing, so a
pattern edit, an extend and a time change all skip it; and the schedule screen
labels its row **one-off** and says why. `SFAF_Follow` is keyed on the term
alone, so such an event is in a followed series on exactly the same footing as a
generated one.

**The schedule screen's SEED must come from the group, not from the series**
(3.64.1). Its list of dates is term-scoped, but the pattern, the cadence
controls, the times and the sentence describing the schedule all describe the
group. Reading them off whichever event is soonest mixes the two: a hand-added
event dated before the next occurrence became the seed, carried no pattern, and
the pattern form stopped offering a frequency for a series that plainly had one.
The save was never affected, because `schedule_pattern_from_post()` has always
read `upcoming_in_group()`. **The general rule that produced the defect: where a
screen shows one grouping and edits another, every value it derives has to say
which one it came from.**

### Categories are multi-select

An event has zero or more categories. Color and icon are term meta
(`_uc_category_color`, plus a stored icon key) edited in `/caladmin`.

Before 3.15.0 the color had no form field anywhere: only the sample-data
seeder ever wrote it, so every hand-created category was default teal,
permanently. The icon was not stored at all but mapped from five exact category
*names*, so a sixth category could not have an icon and renaming one silently
took its icon away.

**A name map that has to be edited in PHP every time somebody invents a category
is not a feature, it is a list of the categories that existed when it was
written.** Color is a closed palette and every icon key is validated.

### Organizers, and which deletion rule a taxonomy gets

An organizer carries **name, slug and description, and nothing else**. There is
no term meta on `uc_organizer` at all, which makes it the simplest of the four
taxonomies: categories carry a colour and an icon, venues four address parts,
series an image and a default FAQ set.

**An event may have several**, because events are sometimes co-hosted (3.40.0).

**The taxonomy always allowed it. The caladmin picker did not, and that was
silently deleting data.** `uc_organizer` is non-hierarchical and its `show_ui`
defaults to `public`, which is true, so the WordPress post editor's own
Organizers box has always been able to put two on an event. The caladmin picker
read `[0]` and its save wrote an array of that one back through
`wp_set_object_terms()`, whose default **replaces**. So a two-organizer event
showed one in caladmin and lost the other the moment anybody pressed Save, with
nothing said. That is the fault categories had until 3.8.0, on a different
taxonomy, reached the same way.

**There is no primary organizer**, and nothing needs one. A category's first
alphabetically drives the card colour and the placeholder tile; an organizer
carries no colour and no icon, so nothing downstream has a decision to make.

**They are ordered by name, always**, through `SFAF_Organizers::for_event()`.
Term relationships come back in an order nothing guarantees, so without a rule
the same event could name its hosts one way on its page and the other way on a
card, in an email or in a satellite payload. Alphabetical is the order a reader
can predict and the order the Organizers screen already lists them in.

**`SFAF_Organizers::phrase()` is the one place the wording is decided**, so the
event page, the card byline and the search-engine listing cannot join two names
three ways:

| | |
|---|---|
| one | `The Stonewall Project` |
| two | `Black Brothers Esteem and The Stonewall Project` |
| three | `Black Brothers Esteem, Elizabeth Taylor 50 Plus Network and The Stonewall Project` |

**No serial comma**, which is AP style for a simple series and therefore the
house style. An event with **one** organizer renders exactly the string it
always did, which is what makes the whole change invisible unless somebody uses
it.

**The JSON-LD is the exception, and deliberately.** `schema.org/Event` declares
`organizer` as accepting one value or many, so several hosts emit an **array of
Organization objects** rather than a phrase: a search engine reading "A and B"
gets one organisation with a strange name. Prose joining is for people. One
organizer still emits a single object, not an array of one.

**What needed no change, and why.** The organizer filter on the public calendar
and in both generators is a `tax_query`, which has always matched an event where
the term is one of several, so a co-hosted event appears under **both** hosts
and their counts both include it. Embed blocks scoped by organizer resolve
through the same query, so a block scoped to one host includes an event if
**either** of its organizers matches. Search covers the taxonomy by term name
and reads every term on the event. Duplicate-as-template copies all terms.
`SFAF_Organizers::events_using()`, which drives the per-organizer count and the
deletion confirmation, is the same `tax_query` and has always counted "one of
several".

**Imported events are unaffected, structurally.** `organizer` is on
`manager_fields()` for both adapters, so no fetch has ever written it and none
can. If a source ever supplies several organizers, nothing happens: the adapter
path does not write this taxonomy at all, and a platform's organizer is its own
record rather than a term here. Making one arrive would be a deliberate piece of
mapping work, not a thing that starts happening.

It appears publicly in five places: **"Hosted by" on the event page**, the
byline on a card (only when the event is not imported, since an imported event
names its platform instead), the **organizer filter** on the public calendar and
in both generators, `schema.org/Event` `organizer` in the JSON-LD, and the
`/event-organizer/<slug>/` archive. It is also searchable, through
`SFAF_Search::TAXONOMIES`, and travels in the satellite feed payload.

`SFAF_Organizers` (3.37.0) is a **wrapper, not a registration**: the taxonomy
stays exactly as it was, public, with its `event-organizer` rewrite and
`show_in_rest`. The WordPress term screen stays reachable by URL as the
fallback, the same reasoning that kept Calendar Users in wp-admin.

**A rename never moves the slug, and since 3.38.0 that is pinned rather than
inferred.** `wp_update_term()` derives a slug from the name when its args carry
no `slug` key, so "does renaming move it?" was a question about a WordPress
internal rather than about anything this plugin stated. For a value other
people's embed blocks resolve through, and whose users this plugin cannot
enumerate, that is the wrong thing to leave to inference: `SFAF_Organizers::save()`
now passes the existing slug back on every rename.

The slug is therefore **not editable and not shown as a field**. It is reference,
not something anybody managing events needs to read, an editable field invites a
change that empties somebody else's calendar, and a warning beside it would be a
sentence explaining why a control is risky rather than telling anybody what to
do. With the value unable to move, there is nothing left to warn about.

**Which deletion rule a taxonomy gets depends on what the event keeps.** The two
precedents genuinely differ and the difference is not stylistic:

| | Rule | Because |
|---|---|---|
| **Venue** | **Refused** while in use | An event keeps no address of its own. The term is the only record of where it happens, so deleting it leaves the event with nowhere to be. |
| **Category** | **Allowed** | The event keeps its date, time and location and is merely uncategorised. |
| **Organizer** | **Allowed** | The same. Every fact survives; what is lost is a byline. |

The confirmation names the count either way, which is what makes an allowed
deletion honest rather than merely permitted. It also names the one thing the
category case does not have: an embed block filtered by that organizer stops
showing events, and this plugin cannot enumerate those blocks to warn about them
individually.

The count comes from a query, **not from the term's own `count`**, because
WordPress counts only published posts and a manager with three drafts against an
organizer would be told "0 events" and then surprised by the confirmation.

**One is made on the Organizers screen, and nowhere else.** 3.37.0 added a
"Not listed? Add one" field to the event editor's organizer card, on the
reasoning that a new programme meant abandoning a half-typed event to go
elsewhere, and made it a FIELD riding the ordinary Save rather than a button
that posts, because the FAQ set control before 3.3.0 applied by posting and
redirecting, discarded every unsaved edit, and taught people not to press it.
That half of the reasoning still stands and applies to the next control anybody
adds to that card.

**Reversed in 3.41.0**, because the premise went with it: the same release gave
organizers their own screen, so the picker lists something curated, and a text
box beside a curated list invents entries in passing. A name typed mid-event
gets whatever spelling was in somebody's head, the list acquires "Stonewall
Project", "The Stonewall Project" and "Stonewall", each owning some events, and
merging them afterwards is manual. The friction removed was real and is now two
clicks; the cost added is permanent and lands on somebody else. Both halves are
asserted gone, the field and the save that read it, because a save still reading
`organizer_new` is reachable by anything that posts to `save_event` whether or
not a field is drawn.

**Imported events are unaffected.** `organizer` is on `manager_fields()` for
both adapters, so no fetch has ever written it and none can: a platform's
organizer is its own record, not a term in this taxonomy, and guessing a mapping
would create duplicate terms nobody asked for.

### Closures are not events, and that is a storage decision

A closure is a day SFAF is shut: a date or a date range and a label. It has no
page, nothing to click, nothing to register for, and nothing scheduled about it.

`SFAF_Closures` stores them in **one option**, not a post type, and the reason
is the whole of the "must not leak" requirement. Every subsystem that touches
events reaches them through a `WP_Query` over `uc_event` or through a post id
that must resolve to a `uc_event` post: the pending queue, the .ics feed,
search, RSVP, reminders, the pre-event summary, the REST feed, sitemaps, SEO,
recurrence, series, teams, organizers, venues, FAQ sets and cancellation. **None
of them can see a row in an option**, so none of them needed a new exclusion.

A post type would have inverted that: nineteen places to find and exclude, and
the failure mode of missing one is a "Closed for Thanksgiving" event with a
permalink, an RSVP button and a row in the import queue.
`.claude/closures-test.php` asserts the storage choice and then asserts that
none of those nineteen files has grown a reference, so the property is checked
rather than remembered.

**A multi-day closure is one entry spanning dates**, not one row per day.
Somebody closing for the winter break enters it once and edits it once; four
rows would be four chances to type it differently. The two renderers then ask
different questions of that one entry: the month grid asks **per day** and marks
four squares, because there a square is a day; a list asks **per span** and shows
one card reading the range, because four identical cards is four times the noise
for one fact.

It reaches the embed by not being special: the embed payload is built by calling
the same shortcode renderers, so a closure the shortcode draws is a closure the
embed serves.

**A closure can carry a free text note** (3.84.0), because SFAF can be closed
overall while one site stays open and "Closed for Labor Day" alone is then wrong
for whoever is standing outside the 6th Street Center. It is optional, and a
closure without one renders exactly as it did.

Both renderers read the same stored string, so they can differ in LENGTH and
never in CONTENT. The list card has the width and shows it in full on its own
line. The month grid shows `note_short()`: cut at 32 characters, backed up to
the last space so it never breaks mid-word, with an ellipsis marking the cut.
The cell's `title` carries the whole note and the cell's `aria-label` already
speaks it in full, so the shortening never costs a reader the content and the
spoken label is never the truncated one.

**The note is NOT in `text()`.** That method is the closure's name sentence and
the WordPress admin table shows it as such; a note belongs to the day being
described, which is the grid's question rather than `text()`'s. Appending it at
that one call site keeps `text()`'s contract and its committed assertion intact.

**`all()` rebuilds every row from a fixed set of keys**, and a field added to
`save()` and not to that list is written, stored, and then silently dropped by
every reader, because `get()`, `covering()` and `spans()` all come through it.
The note behaved exactly that way for its first draft. Anything added to a
closure goes in both places.

**Naming venues was considered and set aside.** A closure saying which sites it
applies to turns a sentence somebody wants to write into a data model with its
own rules about a site that is half open. Free text first; the structured
version is not built and is not owed.

### FAQ sets are made in one place and copied, never linked

An option (`sfaf_faq_sets`), not a taxonomy and not a post type. `SFAF_FAQ_Sets`
owns it.

**THE MODEL, AND IT IS THE WHOLE OF IT (3.63.0).** On an **event** you apply a
set and write questions specific to that event. On the **FAQ Sets** screen you
create, edit, duplicate and delete sets. Nothing on an event adds to the shared
list.

"Save these as a set" lived on the event editor until 3.63.0 and is gone, with
`create_from_event()`, its hidden form, its route and its script. Three things
were wrong with it and removing it disposed of all three rather than repairing
them: the browser collected the rows on screen and the server built the set from
the last **saved** state, a refusal redirected with the success message key, and
it was gated on `can_edit_event` while editing and deleting a set were
`can_view_all`. **Every operation on the shared list takes `can_view_all` now**,
duplication included, because a contributor should not be able to add a row to a
list they cannot then correct.

**APPLYING COPIES AND NEVER LINKS**, which is the decision everything else here
follows from. Answers drift year to year, so a link would mean a manager
correcting a 2027 answer silently rewriting the 2025 and 2026 events sitting on
the calendar as past events. `apply()` writes one key, `sfaf_faq_meta_key()`, and
**nothing on an event records which set its questions came from**. That is what
makes deleting a set harmless and what makes the deletion notice true.

**DUPLICATION EXISTS BECAUSE OF THAT SAME ARGUMENT, ONE LEVEL UP.** "Next year's
version of this set" has to be a new set that starts from this one's text, never
a live reference. `duplicate()` reads the set through `all()` and writes it back
through `save()` with `' - copy'` appended, so a copy passes through the same
cleaner, the same row cap and the same id-collision handling as a set typed from
scratch, and after the call there is nothing joining the two.

**Duplicate NAMES are allowed and are not a collision.** `save()` resolves
collisions on the **id**, walking `name`, `name-2`, `name-3` until one is free,
and never on the name. Refusing would put a failure state in front of something
that is about to be renamed anyway: the copy opens with its name field focused,
which is what stops the list filling with sets called "copy".

**The list is collapsed** (3.63.0). Each set is a native `<details>`, showing its
name and question count in the apply dropdown's format. **No `name` attribute on
them**, so two can be open at once: somebody comparing two sets needs both, and a
named group is exclusive. `<details>` rather than script for the reason the
dashboard's form-link control uses it: it opens with scripting off and the
browser supplies the keyboard and screen-reader behaviour.

### Venues are stored by reference and resolved at display

`uc_venue` is a taxonomy. The address is **term meta on the venue**, read at
display time by `sfaf_event_location()`. An event that names a venue stores the
term and nothing else: no address, no snapshot.

So when a venue moves, or somebody corrects a suite number, every event held
there is correct immediately, including past and already-published ones. The
alternative, copying the address onto each event at save time, is easier to
write and wrong in the way that matters: a corrected address would fix the venue
and none of the forty events pointing at it, and nothing could tell an event
whose address is deliberately different from one whose address is simply out of
date.

**An event has either a venue reference or its own location text, never both.**
Choosing a venue clears the text; choosing "a different location" clears the
term. One source of truth per event, so nothing has to decide which wins. A
one-off in somebody's back garden is not a venue and should not become one.

An **imported** event keeps its text: the platform owns that field and writes it
on every fetch, so the venue picker is not offered there.

### Online events, and a meeting link that is a credential

One checkbox on the event, default off (`_uc_online`). `SFAF_Online` owns it.

**Online is a THIRD case of the venue/text rule, not an exception to it.** An
event has a venue reference, or its own location text, or neither because it is
online. `SFAF_Online::set()` clears the term, the composed line and all four
address parts in one call, so no combination survives for something added later
to read. **Turning the tick off does not restore an address**, because nothing
is kept to restore it from; it also clears the link and the delivery ticks, so
an in-person event never carries an orphan credential.

**"Online Event" is answered once, in `sfaf_event_location()`.** That function
is what the event page, the sidebar, the cards, the month grid, the five emails,
the `.ics`, the JSON-LD, the satellite payload, the WordPress admin box and the
caladmin lists all read, so one branch puts the phrase in all of them and none
of them can be forgotten. `sfaf_event_map_html()` returns nothing for an online
event: the Google frame loads on view, and asking for a map of two words that
are not a place buys nothing and attributes a visit.

**The meeting link is a credential and is treated as one.** Anybody holding it
can join, and this calendar carries HIV, substance use and trans health
programming. It is on no public surface: not the event page, the cards, the
embed payload, the REST feed, the satellite feed, the search index or the
structured data.

**That is asserted as a WHITELIST, the same inversion private events use.**
`SFAF_Online::link()` is the one reader of the meta;
`.claude/online-events-test.php` sweeps every file for the key, the constant and
the four functions that emit it, and **anything not on the list fails**. A field
added later is caught by default, because it will be neither excluded nor
listed. A second pass builds every public payload for an online event carrying a
link and searches the bytes for it, because a whitelist proves nothing new reads
it and only rendering proves the existing readers do not print it.

**The delivery is decided per message, not per person, and that is deliberately
where the seam is.** Two tickboxes, both off by default, stored as the set that
is ON: the confirmation, the morning-of reminder, both, or neither.
`build_confirmation()` and `build_reminder()` each ask
`SFAF_Online::sends_with()` for their own kind. Per-registrant approval, when it
arrives, is a gate in front of that same call; nothing about today's build
assumes every registrant gets the link.

**With the tick on and no link entered, the ticked messages say a link will be
sent before the event.** The tick selects the message; whether that message
carries a URL or the promise is decided by whether a link exists.

**The `.ics` carries the link only on the confirmation's copy, and that is wider
distribution than the email.** A calendar entry syncs to the person's phone,
their laptop and any calendar they share, so people who never registered can
read it. Mark decided this for the case where the person already holds the link.
It is **not** done for the reminder, because the `.ics` is offered by the
confirmation and by nothing else.

The `.ics` endpoint is public and addressed by post id, so the join copy
requires `j=`, an HMAC over the event id keyed on `wp_salt( 'auth' )`, built only
by `SFAF_Online::ics_url_with_link()`. It is keyed on the id and not on the link,
so correcting a Zoom URL does not break the button in confirmations already
sent, and it is stateless: no row, nothing for a per-registrant gate to unpick.
`LOCATION` stays "Online Event"; the link goes in `DESCRIPTION` and in the RFC
7986 `CONFERENCE` property.

**The structured data says `OnlineEventAttendanceMode` and a `VirtualLocation`.**
schema.org would put the meeting link in that node's `url`; the event permalink
goes there instead. The `PostalAddress` is replaced rather than blanked, because
an empty street with a defaulted "San Francisco, CA" asserts an address the
event does not have.

**An imported event is not offered the tick, and refusing costs nothing.** The
platform owns the location field and rewrites it every hour, so a tick that
emptied it would be undone by the next fetch. The control is not rendered, the
save is gated on the same lock the location field is, and
`SFAF_Sources::import_event()` refuses all three keys outright the way it
already refuses `_uc_private`. An online event from a platform says so in the
text the platform sends.

**The tick travels with a repeating event**, in `SFAF_Recurrence::$copied_meta`
and in `SFAF_Portal::apply_to_group()`, like the address it replaces. The
recurrence list holds literals rather than a call, because a static property
initializer is a constant expression; the test asserts the two lists agree.

### Private events are unlisted links, not access control

One checkbox on the event, default off (`_uc_private`).

**It is an unlisted-link scheme and everybody involved knows a link can be
forwarded.** What it must guarantee is that the event cannot be *found* by
somebody who was not sent the link. That is a claim about *every* route into an
event, not the routes anybody happened to think of. The permitted routes are
enumerated
in the readme and asserted as a **whitelist** in
`.claude/private-events-test.php`, so a query added later cannot quietly surface
a private event.

**Privacy lives on the event and never on the series.** There is deliberately no
series-level setting: two settings that can contradict each other are worse than
one rule, because the moment a series says private and an event says public,
something has to decide, and whatever it decides is wrong for somebody. A wholly
private series is every event in it marked private.

**The URL is the credential, so it has to be unguessable.** Making an event
private replaces its slug with 32 hex characters from `random_bytes()`. The
previous slug is kept so making it public again restores the old address.

**WordPress keeps old slugs alive, and that cuts both ways.** Core hooks
`wp_check_for_changed_slugs()` to `post_updated`: when a published,
non-hierarchical post's slug changes, the previous slug is stored as
`_wp_old_slug`, and `wp_old_slug_redirect()` then 301s any 404 matching one to
the post's current address. `uc_event` qualifies on every count. The *same*
mechanism gives the right answer in one direction and a disclosure in the other,
so neither can be left to it:

- **Private to public: kept deliberately.** The token is retained and redirects
  to the restored readable address, so every link already sent to a donor keeps
  working. Nothing is cleared on that branch, and a delete placed there would
  destroy exactly the links the feature exists to serve.
- **Public to private: had to be killed.** The readable address was retained and
  redirected to the *token*. `/events/donor-reception` kept resolving and handed
  the secret address to anyone who tried it, in a `Location` header, as a cached
  301. Confirmed on the live site.

So retained slugs are deleted in `randomize_slug()`, after the update that
creates the row, never in `set()`. Every caller of `randomize_slug()` is by
definition making an address unguessable, and putting it there also covers the
occurrence path that `set()` never touches.
`SFAF_Privacy::block_old_slug_redirect()` on `old_slug_redirect_post_id` is the
second, independent mechanism, scoped to `uc_event` because that filter is
global and the rest of the site needs the redirect.

**Each occurrence of a private series gets its own token.** Occurrence slugs are
normally `{seed-slug}-{date}`, so one shared token would mean that being sent
one date hands you every other date by editing the URL.

That guarantee was being defeated by the same core mechanism. An occurrence used
to be *inserted* at `{seed-slug}-{date}` and randomized a moment later, and for
a private seed the seed's slug is its token, so the predictable address was
retained and redirected. **The slug is now decided before the insert**, by
`occurrence_slug()`, from `readable_base()` (the address the seed would have if
it were public, never its token), so nothing guessable is ever the post's name.
Each occurrence also stores the readable address it would have had, so a date
made public again lands on words instead of staying a token.

**Privacy is a scoped field like any other.** The editor's scope modal already
asks "this event" or "all upcoming occurrences"; privacy used to ignore the
answer and touch only the row it was ticked on, while its label promised it hid
the event everywhere. It travels through `apply_to_group()` now, per target, via
`set()` rather than as a meta copy, because copying `_uc_private` without
replacing the target's slug produces an event claiming to be private at a
guessable address. Past occurrences are never reached.

Everything else works normally for anybody holding the link: registration, all
four emails, add-to-calendar, the map, capacity, cancellation. The `.ics` export
needed its own gate.

**A private event page tells search engines not to index it, and always has.**
Being absent from a sitemap only means nobody was told the page exists; it does
not stop a crawler that finds the address another way, and a link gets
forwarded. There are three independent mechanisms and they were all there before
3.68.0 asked the question:

- `SFAF_Privacy::robots()` on `wp_robots` sets **noindex and nofollow** on the
  page itself, and unsets `index`, `follow` and the three `max-` directives so
  nothing else can put them back. **nofollow as well as noindex**, because a
  crawler that reached the page must not walk on from it.
- `set()` stamps **Yoast's own `_yoast_wpseo_meta-robots-noindex`**, which is
  what covers the tag Yoast emits and the sitemap Yoast builds in one write.
  `wpseo_exclude_from_sitemap_by_post_ids` is registered as well, for an event
  made private before that meta existed.
- Core's XML sitemap is filtered on `wp_sitemaps_posts_query_args`.

**The control says nothing about any of it, on purpose.** It is not a decision a
manager makes and not a consequence they meet; it happens whatever they do. The
copy is three sentences (3.68.0): the event will not appear anywhere on the
site, only people sent the link can find it, and **turning it on gives the event
a new link so any link already shared stops working.** That third sentence is
why it is not one sentence: ticking the box breaks a link somebody may already
have sent to a room full of people, and nothing else on the screen would say so.
Both screens carrying the control use that wording. What was dropped was the
mechanism: which surfaces it is left out of, and what happens to the old address
if it is switched back off.

---

## 3. Integrations

### What a shared event link produces

Established by reading `SFAF_Seo` in 3.56.0, as an investigation. **Nothing here
was changed**, and the two open questions at the end are decisions rather than
defects.

**The tags are ours, and both families are emitted.** `SFAF_Seo::output()` runs
on `wp_head` at priority 5 and only on `is_singular( 'uc_event' )`. Open Graph:
`og:title`, `og:description`, `og:type` (the literal `event`), `og:url`,
`og:site_name`, `og:image` when there is one, and `event:start_time` /
`event:end_time`. Twitter: `twitter:card` as `summary_large_image`,
`twitter:title`, `twitter:description`, `twitter:image` when there is one. **An
image tag is emitted in both families**, conditionally on an image resolving.

**Absent, and worth knowing before anybody debugs an unfurl:** `og:image:width`,
`og:image:height`, `og:image:alt`, `twitter:image:alt`, `og:locale`, and
`twitter:site`. Facebook and LinkedIn infer dimensions by fetching the image
when width and height are not declared, which is slower and is why a first share
sometimes unfurls without a picture.

**Nothing at all on a private event**, by design: not the tags, not the JSON-LD.
That is what stops a forwarded link unfurling into a titled preview card.

**Three JSON-LD blocks**: `Event`, `FAQPage` when the event has FAQs, and
`BreadcrumbList`. The Event carries `name`, `description`, `eventStatus`,
`eventAttendanceMode`, `url`, `location` (a `Place` with a `PostalAddress`),
`startDate` and `endDate` when the event has them, `organizer` and `performer`
when it has one, `image` when one resolves, `offers` when RSVP is enabled, and
`superEvent` when it is in a series.

**Fields present but effectively always empty or wrong:**

- **`postalCode` is hardcoded to `''`.** Nothing fills it.
- **`addressLocality` and `addressRegion` fall back to `San Francisco` and `CA`**
  by splitting the location string on commas. An event elsewhere with a
  one-part location is asserted to be in San Francisco.
- **`performer` is malformed.** It is built as
  `array( '@type' => 'Organization', 'name' => $org )` where `$org` is already
  an Organization array (or an array of them), so `name` holds an object rather
  than a string. `organizer` beside it is correct.
- **`eventStatus` is always `EventScheduled`**, including on an event
  `SFAF_Cancellation` has marked cancelled, where schema.org has
  `EventCancelled`.
- **`eventAttendanceMode` is always `OfflineEventAttendanceMode`**, with nothing
  asking whether the event is online.

**The image, and what the tag points at.** `SFAF_Seo::image_url()` calls
`sfaf_event_image_url()`, which resolves in order: featured image →
`_uc_image_url` → `_uc_external_image` → the series image → `_uc_remote_image_url`.

> **THE FIRST BRANCH IS THE ONE THAT RESIZES.** A featured image is returned as
> `get_the_post_thumbnail_url( $id, 'large' )`, and `large` is a WordPress
> built-in whose default is a **1024px bounding box**. So a 1200x675 upload is
> shared at **1024x576**. Every other branch returns a stored URL untouched, at
> whatever size it was, so the size an unfurl gets depends on where the image
> came from. **The plugin registers no image sizes of its own** — there is no
> `add_image_size()` anywhere in it — so this is entirely WordPress's `large`,
> and a site whose Settings → Media has been changed will serve something else
> again.

**The 16:9 question, for a decision and not a fix.** Uploads are specified as
**1200x675 (16:9)** in both editors' guidance. Facebook and LinkedIn want
1200x630 (1.91:1) and Twitter's large card wants 2:1, so 16:9 matches neither
and is cropped slightly by each rather than badly by any. **Every existing image
is already 16:9**, so changing the target is a re-crop of the whole library, and
the two things actually worth deciding are separate from it: whether the
featured-image branch should stop passing through `large`, and whether
`og:image:width` / `og:image:height` should be declared.

### The import framework

Both platforms import through one framework (`SFAF_Sources`). Imported events
land in a **pending queue**; somebody approves them; a fetch may run again
afterwards.

**A source is an adapter and the framework does not know which platform is
which.** It walks a registry, calls `fetch()` then `normalize()` on each, and
imports whatever comes back in the common shape. `SFAF_Source_Adapter` is the
abstract base: `slug()`, `label()`, `is_active()`, `inactive_reason()`,
`fetch()`, `normalize( $item )`, `owned_fields()`, `manager_fields()`.

**Adding a platform means writing an adapter and registering it. No file in
`class-sfaf-sources.php` changes.** GFMP proved that when it was added: the
framework was untouched. Registration is in `sfaf_init()`, or externally through
the `sfaf_source_adapters` filter. **EveryAction is expected to arrive this
way** (see §8).

`normalize()` returns a common shape keyed `external_source`, `external_id`,
`title`, `description`, `start_date`, `start_time`, `end_date`, `end_time`,
`timezone`, `location`, `source_url`, `image_url`, and an optional `meta` array
of `_uc_`-prefixed keys. The date and time split matches the meta the calendar
already stores, so an imported event is an ordinary `uc_event` from the moment
it exists and every existing screen reads it without knowing.

**Imported events live in two custom post statuses**, `uc_imported` (awaiting
review) and `uc_dismissed` (passed on, kept, never re-imported). Both are
registered `public => false`, `publicly_queryable => false`, `protected`,
`exclude_from_search`.

**Exclusion from public display is by construction, not by a filter.** Every
public query names `post_status => 'publish'`: the shortcodes, the embed, the
REST feed, the `.ics` endpoint, the series listings, the RSVP guards. All
`uc_event` queries in the plugin were enumerated and verified when the statuses
were introduced.

Provenance is meta on every imported event: `_uc_external_source`,
`_uc_external_id`, `_uc_source_url`, `_uc_external_image`,
`_uc_external_timezone`, `_uc_imported_at`.

**The no-duplicates rule, and a trap in it.** `find_existing()` matches source
plus external ID against an **explicit status list**: `publish, pending, draft,
future, private, trash, uc_imported, uc_dismissed`. Do not "simplify" that to
`post_status => 'any'`. `'any'` silently omits every status registered
`exclude_from_search`, which both queue statuses are, so it would miss the
entire queue and re-import everything on every run. Trash is in the list
deliberately, so a trashed import does not come back either.

**What is allowed to become an event, since 3.58.0.** Two rules, applied at the
one moment a row would be created, in `SFAF_Sources::import_refusal()`.

**One: the adapter's own verdict, which outranks any date.** GoFundMe Pro
returns far more than events, and its `type` field says which is which:
`ticketed`, `registration`, `reg_w_fund` and `fund_for_entry` are events;
`donation`, `crowdfunding`, `peer_to_peer`, `dynamic`, `gfm_npo` and `gfm_p2p`
are ways of collecting money. `is_general` is asked as well, because that is the
platform's own word for a campaign about no particular occasion. **A type not in
that list is imported**, on the reasoning that a stray row is one click to
dismiss while an event silently refused is invisible until somebody asks why it
never arrived.

**Two: no event date, or a date that has gone, means no import.** Today is not
past, and a multi-day event is judged by its end date so a conference is not
refused in the middle of itself. `date_has_passed()` is the single definition
and the queue sweep below reads the same one.

**WHICH DATE COUNTS, AND WHY THE TYPE DECIDES IT (3.59.0).** `last_day()`
believes an end date only when the item's type says the end date means an
event's end. `SFAF_Sources::EVENT_TYPES` is the one list: `event`, which is
everything Eventbrite returns, and GFMP's `ticketed`, `registration`,
`reg_w_fund` and `fund_for_entry`. Anything else, **and any unrecognised type**,
is judged on its start date, so a type the platform adds later cannot default
into being treated as an event.

3.58.0 had this rule prefer the end date whenever one was present, and four rows
never cleared as a result. `_uc_end_date` is written only by a source, and on a
GFMP campaign it holds `ended_at` — the close of the **fundraising window**. A
donation page collecting until December reported a December last day while the
screen showed a start date from April. **It was the same finding as the type gate
above, one field over**: `started_at` had been established as a window boundary
rather than an event date and the multi-day rule was then built on `ended_at`
without applying that. The comment above the rule asserted an event meaning the
data does not carry, which is the half that does the damage, because a comment
claiming a meaning is believed rather than checked.

**The type is stored on the row** as `_uc_source_type`, at import and refreshed
on any later fetch that still returns the campaign — quietly, outside the
changed-fields report, or the first run after 3.59.0 would tell a manager every
still-returned event had changed. It is provenance: no screen shows it, no form
writes it, and it has no manager-owned gate for the same reason the source slug
has none.

**An unknown type judges on the start date**, which covers every row imported
before 3.59.0 and every campaign the source has stopped returning. Those can
never have a type learned, and are judged on their start date permanently. That
is accepted rather than worked around.

**Unknown resolving to the start date here is the opposite of what the import
gate does with an unknown type, and both are deliberate.** Each errs towards the
outcome a person can see and undo: a stray row in a queue is one click to
dismiss, an event silently refused is invisible, and a row cleared a little early
sits in Dismissed with a Restore button.

**WHY THE TYPE TEST EXISTS AND A DATE TEST WAS NOT ENOUGH.** The adapter maps
GFMP's `started_at` into `_uc_event_date`. On the Campaign schema that field is
"Date/time the campaign begins" — the fundraising window, not an occasion. On a
ticketed campaign the window *is* the event, which is why the mapping is correct
and stays. On a donation page the window opens when somebody creates the
campaign, which is why the queue filled with rows like "SFAF Website Donations,
Feb 23 2026 11:29 pm": not a creation timestamp, but very nearly one. A past-date
rule alone would have cleared those and none of next week's.

**WHERE THE RULE LIVES IS THE SAFETY OF IT.** It runs in the branch of
`run_adapter()` that has established there is no existing event, *after* the
item's id has gone into `$seen_ids`. Refusing in `normalize()` instead would drop
the id out of that set, and `handle_removals()` reads an id missing from it as
deleted at the source — so every refusal would have unpublished the live event
that came from that campaign. Nothing already imported is re-judged either: a
published, queued or dismissed event is a decision somebody made.
`.claude/import-gate-test.php` asserts both, and the published-event case was
found by planting the mistake and watching a live event become a draft.

**Dateless imports, and the trap in those.** New dateless campaigns are refused
by the rule above, but rows imported before 3.58.0 still have empty dates and a
manager still sets the date at approval. So `queue_ids()` **must not order by the
`_uc_event_date` meta**: setting `meta_key` in `WP_Query` implies that meta must
exist, which silently hides every dateless import. It sorts in PHP instead,
dateless last. The queue sweep leaves dateless rows alone for the same reason —
a row with no date has no date to have passed, and filling it in is the job.

**The queues clear themselves, since 3.58.0.** The last piece of the original
import design, agreed 2026-07-29. `SFAF_Sources::sweep_queues()` runs on the
15-minute runner as a task of its own — not inside the fetch, because a row
expires when a day passes rather than when a source says anything, and automated
fetching is not always on.

An expired **pending** row is moved to **dismissed**. Not deleted, which would
let the very next fetch import it again, and not trashed, which would work for
thirty days until WordPress emptied the trash and the fetch imported it again.
`uc_dismissed` is in `all_statuses()`, which is what `find_existing()` searches,
so a swept row is matched on the next pass and takes the not-updatable branch.
`queue_ids()` additionally **hides** spent rows from both lists, which is the
half a status move cannot do: it is what makes an already-dismissed expired row
disappear from the Dismissed list, and what makes a row that expired an hour ago
gone the moment somebody looks rather than whenever the runner next fires.
`queue_count()` counts the list rather than the database, or the badge and the
list disagree.

**THE BOUNDARY: THE SWEEP REACHES THE QUEUES AND NOTHING ELSE.** Its query names
the two queue statuses. A **published** event that expires simply becomes a past
event and stays exactly where it is; a published event removed at the source is
unpublished and kept as a record, which is `handle_removals()`'s job and is
unchanged.

**Publish is deliberately not one-click.** The platform owns title, description,
times, location and image; category, organizer and series are local decisions
and are never guessed.

**Removal is guarded.** "Not in the response" and "the fetch broke" look
identical from inside, and getting it wrong silently pulls live events off the
public calendar. So unpublish-on-removal only runs when the adapter can say the
run was clean: every request succeeded, pagination reached the last page, and at
least one item came back. Any doubt and the whole removal step is skipped for
that source, and the report says why. It is per source.

Each adapter declares two lists, and they are the whole contract:

- **`owned_fields()`**: what the platform writes on every fetch. The editor
  locks exactly these.
- **`manager_fields()`**: what a person owns permanently. `update_event()`
  **refuses** these outright, whatever a payload sends.

The test for `owned_fields()` is not "does the platform have a version of this
field" but **"does `update_event()` write the meta key that editor control
writes"**. That is why GFMP declares `donate_url` and Eventbrite does not:
GFMP's `normalize()` really does send `_uc_gofundme_url`.

**Why a refetch must never overwrite a manager field.** A fetch runs hourly and
unattended. A manager's decision was taken once, by a person, on purpose. If the
fetch can win, a manager who turned something off finds it back on an hour
later, with nobody present to notice. Declaring the field is what makes the
exclusion a rule the framework enforces rather than a coincidence of the current
mapping. That is what it used to be, and it was one well-meaning "fix the gap"
commit away from silently overwriting somebody's copy.

### GoFundMe Pro

Supplies: title, dates and times, location, source URL, donate URL, FAQs, and
campaign aggregates.

Does **not** supply: campaign image, or the About-section copy. GoFundMe Pro
support confirmed both live in their design/theme layer and are not exposed on
the public API. There is no `theme_id` on the campaign object and
`/campaigns/{id}/stories` returns zero. This plugin does not scrape pages.
**If you are here to add an image or description mapping, check with GoFundMe
Pro first.**

On the image specifically: the Campaign schema exposes exactly two image URLs
and neither is the banner. `logo_url` is the small logo mark, which is what
2.6.0 imported and why campaigns showed a logo where a banner belonged.
`team_cover_photo_url` was tried next and returned artwork that is neither the
campaign banner nor any image on the campaign page. So the candidate list is now
**deliberately empty** and campaigns fall through to the calendar's own branded
placeholder. **A wrong image on a public calendar is worse than no image.** The
`sfaf_gfmp_image_fields` filter is left in place so a candidate can be restored
on a live site without a rebuild, the moment the probe shows which field is
right.

**Auth is OAuth2 client_credentials, and the token host is not the data host.**
`POST api.classy.org/oauth2/auth` for the token; data calls go elsewhere (see
below). Support confirmed `api.classy.org/oauth2/auth` is the correct and only
token endpoint and that `pro.gofundme.com` does not serve tokens. Four releases
were burned guessing at this. Do not "fix" it back.

**The `x-integration-id` header is why auth works at all.** Their edge security
answered with a Cloudflare 403 that read like a credential failure but never
examined the credentials. Support issued the header as the fix. It is set in
`request_args()` so every request carries it, with the value in
`SFAF_GFMP::DEFAULT_INTEGRATION_ID` and overridable through the
`sfaf_gfmp_integration_id` filter.

**There are three registered GFMP apps and only one is ours to use.**

| App | Client ID | Use |
|---|---|---|
| Integration - SF | `GE190fglqytdNw1h` | **production, the real account with real campaigns** |
| Integration - SF - Sandbox | `cCncdH3QS4Oxe7wU` | sandbox, and **it holds no events**, so a fetch against it proves nothing |
| ClassyPress | `jjPN4eyJepmO4yao` | belongs to Mittun, a third party. Leave it alone. |

The organization ID is `98313` for all of them.

**The failure this arrangement produces, once already:** the Client Secret field
is write-only, so blank means keep. Changing the Client ID from sandbox to
production while leaving the secret blank pairs a production ID with the sandbox
secret and returns `HTTP 400 Invalid client authentication`. That reads like a
credential or endpoint problem and is neither. Retype the secret whenever the ID
changes.

Campaigns are listed at `{data_base}/organizations/{org_id}/campaigns`,
paginated in the spec's `PaginatedResponse` style. Campaigns that are not active
or published are skipped, which is correct and adjustable through the
`sfaf_gfmp_import_statuses` filter (default `active,published`). Other filters:
`sfaf_gfmp_max_pages`, `sfaf_gfmp_per_page`, `sfaf_gfmp_fetch_raised`,
`sfaf_gfmp_raised_lookup_limit`, `sfaf_gfmp_campaign_overview_path`.

`apiv2-public-gfmp.json` is their OpenAPI spec and the source of truth for
field names. **It is kept on disk and deliberately not in the repository:** it
is GoFundMe Pro's document rather than ours, it carries no licence granting
redistribution, and it is 2.2 MB. Download it again from their developer
documentation if it is not in the project root. It is 2.2 MB and too large to read whole: query
it with a small Node script against `.paths` and
`.components.schemas.Campaign.properties`. Treat it as a starting point, not an
authority, for the reasons below.

Manager-owned: `image`, `description`, `category`, `organizer`,
`fundraising_progress`, `private`.

`fundraising_progress` is the most important entry in that list, because it is
the only one where a fetch could plausibly want to write and must not. Whether
this calendar repeats a campaign's raised-and-goal figures in public is an
editorial decision about a fundraiser's page. The hourly fetch has an opinion
about the numbers and **no standing whatsoever** on whether to publish them.

**The GFMP OpenAPI spec understates what the live API does, and has been wrong
or silent four times:** the token endpoint, the raised amounts, the campaign
image, and the campaign description. Two specifics worth keeping:

- The host. The live account answers on `api.classy.org/2.0/resource` while the
  spec says `pro.gofundme.com/api/2.0`. The spec's value is the default and the
  host is editable in settings.
- `/campaigns/{id}/overview` **is supported**. Support confirmed that its
  absence from the spec is a documentation gap they are fixing. It returns
  `gross_amount`, `total_gross_amount`, `net_amount`, `fees_amount`,
  `percent_to_goal`. There is **no** `raised_amount`, `progress_bar_amount` or
  `total_online_funds_raised`; those are `CampaignAggregates` names from the
  spec, and reading them was the actual cause of the "no raised amounts were
  available" report. The request succeeded every time and the body was then
  searched for keys that were never in it.

There is a read-only `[PROBE]` tool that reports what the account actually
returns, verbatim, mapping nothing. It exists because the spec cannot be
trusted, and it is marked for deletion in one commit once the payloads are
settled.

### Eventbrite

Supplies: title, dates and times, location, source URL, image, description.

**Auth is a single long-lived private token** sent as `Authorization: Bearer`.
No OAuth round trip. The endpoints are `/users/me/` (connection test),
`/users/me/organizations/`, and
`/organizations/{org_id}/events/?time_filter=current_future&expand=venue,logo,organizer&status=live`.
The old `/users/me/events/` is **deprecated and deliberately not used**. An
account can own several organizations, so the events call runs once per
organization and the results combine; one organization failing records its error
and leaves the others intact.

The image comes from `logo.original.url`, the full-resolution one, not
`logo.url`, which is Eventbrite's crop.

Manager-owned: `category`, `organizer`, `private`, and only those.

Category and Organizer are **this calendar's own taxonomies**. No platform
supplies them and none ever can. Eventbrite has an organizer of its own, but it
is Eventbrite's record rather than a term in our Organizers taxonomy, and
guessing a mapping between the two would create duplicate terms nobody asked
for.

### Pardot / Salesforce, pending

Events carry `_uc_pardot_campaigns`, a multi-select of campaign IDs, and there
is a settings panel to define the campaign list. **Nothing talks to Pardot.** It
is storage only, with a hook (`SFAF_Optins`) for a future integration to attach
to.

This is **blocked on Salesforce credentials**, not on code.

### Where credentials live

Every platform credential is in the **`sfaf_credentials`** option, never in
`uc_settings`. `uc_settings` is rebuilt from an empty array on every settings
save: `sanitize_settings()` copies across only the keys it explicitly names, so
anything it forgets is silently dropped. That is what kept losing the Eventbrite
token, once across a plugin update, which is unworkable when three platforms are
in play.

`sfaf_credentials` is autoloaded and never rewritten wholesale: `set()` is a
read-modify-write of a single key. Adding a credential means adding it to
`SFAF_Credentials::keys()` with a type (`secret`, write-only and blank-means-
keep; `url`; or `text`) and reading it with `SFAF_Credentials::get()`.
`absorb()` runs at the top of `sanitize_settings()` and pulls credentials out of
the submission without adding them to the returned array. `migrate()` runs on
`init` and copies anything still in `uc_settings` across without overwriting, so
it is idempotent and self-healing.

Connection *state* is separate again: `sfaf_gfmp_token`,
`sfaf_eventbrite_status`. Those are disposable and are meant to be.

**Nothing in the plugin deletes credentials, and it must stay that way.** There
is no `uninstall.php` and no `register_uninstall_hook`. Deactivation only
flushes rewrites. The activation routine, which reruns after every update, only
creates the RSVP table, registers the post type, seeds sample data once and
flushes rewrites. It carries a comment saying it must never touch credentials.

Credentials must survive all three of: a settings save, installing a newer
version, and deactivate/reactivate. If they ever vanish again after that, the
cause is outside this plugin (a host-level option reset, a staging-to-production
database sync). Say so rather than patching here a second time.

### Inert panels that are not dead code

The **Galaxy Digital** and **Webhooks** settings panels save their settings and
store their credentials properly, and **nothing calls those APIs**. There is no
Galaxy request anywhere in the plugin; the "Sync Now" button raises a JavaScript
alert. Similarly `gofundme_auto_import` is a stored setting that is wired to
nothing (the unattended fetch is `auto_fetch_enabled`, which is a different
setting and is wired). These are kept on purpose rather than removed, in the
same spirit as the satellite feed. Do not report them as working, and do not
delete them assuming they are.

---

## 4. Email

### Delivery is not this plugin's problem, and must not become one

The site has the **ActiveCampaign Postmark** plugin active, which overrides
`wp_mail()` and hands the message to Postmark. A WordPress password reset
already arrives cleanly by that path.

So every message here calls `wp_mail()` and stops. No SMTP settings, no
transport, no library, no second delivery mechanism to keep in step with the
first. If delivery ever moves, it moves underneath `class-sfaf-email.php`
without a line changing in it.

**From address:** currently `websites@sfaf.org`, as
`SFAF_Email::DEFAULT_FROM_EMAIL`. `events@calendar.sfaf.org` is **pending DNS**.
It is a *setting with a default* rather than a constant used directly, precisely
so that when the mailbox exists somebody types it into Settings and nothing is
deployed.

Reply-To is per event: the event's address, or the person who created it, or the
site default. The registration alert is the one exception and replies to the
person who just registered, because that is who a staff member pressing reply
means to write to.

**The one caveat that cannot be resolved from here.** Every message is built
twice, HTML and plain text. WordPress has exactly one supported way to attach
the text alternative: set `$phpmailer->AltBody` on `phpmailer_init`. That works
with core's mailer and with any SMTP plugin routing through PHPMailer. It
**cannot** work with a plugin that replaces `wp_mail()` outright and posts to an
HTTP API, because PHPMailer is never constructed and the action never fires.
Postmark is the second kind. The hook is attached anyway because it is correct
wherever PHPMailer is involved, and whether a `text/plain` part actually arrives
is visible in a delivered message and nowhere else.

### Icons in email are rasters, and never a platform mark

Every icon on the website is an inline SVG from `sfaf_icon()`, and an inline SVG
does not render in Gmail, Outlook or Yahoo. Where a message needs one, it is a
PNG rasterised from **the same path data** by `.claude/build-email-icons.js`, at
2x for a retina screen, in a baked foreground colour because an email has no
`currentColor` to inherit. A second hand-drawn glyph for mail would drift from
the one on the site, which is the two-renderers fault this project has paid for
before.

**Platform logos are never used.** The confirmation's two Add to calendar
buttons say Google and Apple or Outlook, and the obvious icons are each vendor's
mark. Those are registered trademarks with published brand terms, SFAF is a
nonprofit with a brand guide of its own, and nothing here has been cleared to
reproduce them. One generic calendar glyph on both says the same thing. The
email render test enforces it as a **whitelist** of the image files this plugin
ships, so a vendor mark added later is caught by default.

**Nothing in a message may depend on an image loading.** Many clients block
pictures by default. The banner carries real alt text; button glyphs carry
`alt=""` and are decorative, with `width` and `height` as attributes so a
blocked one reserves its exact box and a pair of buttons stays the same height
either way. The test checks this by **stripping the images and reading again**,
not by reasoning about alt text.

**The confirmation is the ONLY route to the calendar file on an event that takes
registrations** (3.64.0). `sfaf_add_to_calendar()` returns nothing when
`sfaf_event_takes_rsvps()` is true, so the button is off the event page. It sat
directly under the RSVP button, and two things to press with no order between
them is a control somebody presses believing it is how you sign up, coming away
with a calendar entry and no place held. No wording fixes that; two buttons is
the problem.

The removal is only safe because the other route already existed and already
runs at the better moment, when the place is actually held.
`.claude/addcal-rsvp-test.php` therefore checks that the confirmation still
carries both destinations, so taking them out of the email fails the build
rather than leaving an event with no calendar file at all.

**On an event that takes no registrations the button is unchanged**, because
then it is the only thing there is. `_uc_show_calendar` still records what the
manager wants for that case: the editor's tick is greyed while registration is
on, with a line saying where the link goes instead, and **`save_event_from_post()`
skips the field on its own test rather than trusting the disabled attribute**.
A disabled input posts nothing, so reading it would silently write "off" over a
setting nobody touched.

**Add to calendar sits directly under RSVP in the Display card** (3.64.2), and
the order of `$feat` is the order on the screen. A control whose availability is
decided by another belongs beside it, so the pair can be watched working: the
tick greys the moment Accept RSVPs is ticked, without a save. What that cost is
worth recording, because it was a trade rather than an oversight: the card used
to follow the rough order these appear on the public event page, and that match
is gone. Nobody reads the card with the event page open beside it.

The greyed line **names the cause before the consequence**: "Because this event
takes RSVPs, the calendar link goes out with the registration confirmation
instead." It opened on the confirmation email until 3.64.2, which left an
organizer who is not in this calendar every day to connect a grey tick and a
sentence about mail on their own.

`sfaf_event_takes_rsvps()` is that question asked in one place. It was written
out longhand in three, and a fourth reading of it would have been the first
chance for two of them to disagree.

### Cancelling, and why it is meta rather than a post status

An event that is not happening still has to exist: somebody registered for it,
and the registration is the record that they did. Deleting it strands them, so
since 3.36.0 **deleting an event that has registrations is refused** and
cancelling is the operation that exists instead.

`SFAF_Cancellation` stores `_uc_cancelled`, `_uc_cancelled_visibility`
(`stay`|`hide`) and `_uc_cancelled_at` on the event, plus two pieces of prose
described below. **Not a post status**, and
the reason is the one `SFAF_Sources` already writes down in another context:
this plugin names `post_status => 'publish'` **by hand** in the shortcodes, the
embed payload, the REST feed, the .ics, the reminder query, the summary query
and the series listings. A new status is invisible to every one of those until
each is found and changed, and the failure mode of missing one is an event that
is cancelled everywhere except the place nobody checked. A meta flag inverts
that: nothing changes about which queries return the event, the two places that
must behave differently ask, and everywhere else keeps working. The organizer's
`stay`/`hide` choice needs a second field anyway, which settles it.

**TWO PIECES OF PROSE, AND THEY ARE TWO AUDIENCES (3.72.0).**

`_uc_cancelled_reason` is **public**. It renders on the event page under "This
event has been cancelled", for anybody who arrives at the address, and it is
carried in the email as well. It is written whether anybody is emailed or not.

`_uc_cancelled_message` is for **the people who registered and nobody else**. It
appears only in the cancellation email. On a calendar carrying HIV, substance use
and trans health programming, what an organizer wants to say to the twelve people
who set an evening aside is frequently not a sentence for a public page, and
before this there was one box and it was the public one.

**THE SECOND IS COLLECTED BY THE CONFIRMATION, NOT BY THE FORM**, and that is
what makes the state "I wrote a message to the registrants and then chose not to
tell them" impossible rather than merely unlikely. The box is on the far side of
the answer that sends it: pressing **Cancel and email them** opens a second step
of the same dialog, and **Cancel without telling them** never reaches it. The
handler asks `sfaf_should_notify()` again before storing anything, because a
POST is a request anybody can construct and a message stored on an event nobody
was emailed about would sit there until the next cancellation picked it up.

**BOTH ARE DELETED WHEN AN EVENT IS REINSTATED.** Each describes a cancellation
that is no longer in force, and leaving either behind means the next one inherits
a sentence somebody wrote about a different one. The public reason was outliving
its event before 3.72.0.

**THE VISIBILITY ANSWER CAN BE CHANGED WITHOUT RE-CANCELLING.**
`set_visibility()` writes the one key, refuses on an event that is not cancelled,
and cannot send. Before it existed the `stay`/`hide` question was asked once, at
the moment of cancelling, so somebody who left an event listed for the people who
registered and wanted it gone three weeks later had to reinstate it and cancel it
again, which runs back through the prompt that offers to email everybody.

> **IT IS NOT THE PRIVATE SETTING AND THE TWO ARE NOT INTERCHANGEABLE.** A
> private event is unlisted and reachable, deliberately, because the URL is the
> credential and somebody was given it. A cancelled event hidden from the
> calendar is unlisted and **still answering at its own address with the
> cancellation notice**, which is what somebody arriving from an old email or a
> printed flyer needs to see. An event can be both.

**AND IT SAYS SO ON EVERY SURFACE, NOT JUST ITS OWN PAGE (3.72.0).**
`SFAF_Cancellation::exclude()` only removes the ones somebody chose to hide, so
an event cancelled and left listed, which is the default and the recommended
answer, rendered exactly like a live one on the list card, the month grid and the
sidebar. All three carry the word **Cancelled** above the title.

> **THE WORD, NEVER THE COLOUR, AND HERE THAT IS MORE THAN THE USUAL RULE.** Six
> of this calendar's ten category hues are reds and oranges, so a red-tinted card
> is not reliably distinguishable from a card in the Red category sitting beside
> it. The colour is a second signal and could not be the only one even if the
> rule allowed it.
>
> **AND IT IS NOT THE CLOSURE TREATMENT.** A closure is a filled block on a day
> cell saying the office is shut, which is a fact about the day. This is one
> event being off while everything around it goes ahead.

What cancelling does:

| | |
|---|---|
| Public listing | The organizer chooses. **`stay`** (listed, marked cancelled) is the default: somebody who registered may come looking, and a vanished event tells them nothing. |
| Registrations | Kept, all of them. |
| New registrations | Refused at the write in `SFAF_RSVP`, and the button is not rendered. Both, because a form removed from a template is not a refusal. |
| Reminders | Neither the morning-of nor the two-hour summary. **Both queries ask for `publish` and today's date, and a cancelled event satisfies both**, so the exclusion is an explicit first line in each loop rather than something the query can express. |
| Refetch | `update_event()` refuses a cancelled event outright, so the source cannot move an event that is not happening. It could never clear the flag: `update_event()` writes an explicit field list and `post_status` is not on it. |

**Cancelling is for native events only** (3.38.0). An imported event is cancelled
where it lives: if a campaign or listing is called off at the source it leaves
this calendar through the unpublish-on-removal path, and telling the people who
signed up is that platform's job, because they registered there and this plugin
holds none of their addresses. 3.36.0 did offer it, with a warning that it would
not reach the source, and that warning was a reason to remove the control rather
than to caption it: what it produced was a half-cancellation that looks whole.
The control is refused at the render **and** at the write, since a form that is
not drawn is not a refusal.

Deleting a **series** with registered events offers to cancel them all instead,
and asks whether to email. Once cancelled, deleting is allowed: cancel, notify,
then delete.

### Telling registrants, and the one-email guarantee

`SFAF_Announce` sends the two messages. **One person gets one email, whatever
they registered for**, and that is why it is a class rather than a loop at each
call site: changing a recurrence pattern can move twelve dates at once, and
somebody registered for six of them would get six near-identical emails from the
obvious implementation. A run gathers every affected event, resolves every
registrant across all of them, **groups by address**, and sends once.

**The audience is `status = 'confirmed'`, and the invariant is that three reads
agree on it.** `SFAF_Announce::registrants()`, `SFAF_Announce::has_registrations()`
and `SFAF_Reminders::recipients()` must ask the same question, because the
second is the **delete guard** as well as the change prompt's trigger: if they
part company, one direction refuses a deletion nobody would be told about and
the other allows one that strands people. Between 3.39.0 and 3.53.0 the clause
was `IN ('confirmed','subscribed')`, to catch somebody who had pressed Get
Reminders; 3.53.0 removed that status from the table, so the three agree at the
narrow end instead. Somebody who released their place is `cancelled` and is not
written to.

**Following a series is not in this audience and never was meant to be.** A
follower hears about new dates and nothing else. See "Following a series".

**Teams are not notified, and neither is the notification list.** They were
presumably part of the decision.

The two messages differ deliberately:

- **Cancelled** names the event, its date and time, says plainly it is
  cancelled, and carries **no cancel link**: there is no place to release, and
  offering one reads as though something were still required of them.
- **Changed** names **what moved, old value to new value**, for whichever of
  date, time or location changed. A registrant should not have to remember what
  it was before. It carries the full new details **and the cancel link**, since
  somebody who cannot make the new time should be able to release their place in
  one click.

**Only date, time and location trigger anything.** Those three decide whether a
person turns up. Description, category, series, capacity and image do not, and a
notification that goes out for those is one that gets filtered, taking the date
change with it. What is compared is the **formatted** value, so a change
invisible to a reader cannot produce an email.

### Consent to send is one answer, given at the moment of saving

**Sending is a decision somebody makes, never a state a form is left in.** Until
3.42.0 it was a checkbox on the event form, ticked, among thirty other controls,
plus a second one on the cancel card. The reasoning was that somebody changing a
date is thinking about the date rather than about who needs telling, so the safe
default was that people are told. **That was the wrong way round**, because it
made the common outcome the irreversible one: the box was not noticed, mail went
to everybody registered, and nothing can recall it.

`sfaf_should_notify()` in `includes/sfaf-notify-consent.php` is the one answer,
asked by all three paths that can send manager-caused mail: the save, the cancel
and the series cancel. **Only `notify_choice=send` sends.** No value, an
unrecognised value, or a form posted with scripting off all mean silence.

> **The default direction of an irreversible action is not the convenient one.**
> Not sending leaves a manager able to send; sending cannot be taken back, and
> what it reaches is people being told, wrongly, that something changed. A
> ticked box fails open, so anything that posted to the save route mailed
> everybody. This fails closed.

**Asked only when there is something to ask about**: one of the four fields
actually changed AND somebody is registered for it. Otherwise the save goes
straight through. A click in the way of a save that cannot email anybody
teaches people to dismiss dialogs, which is how the next real question gets
dismissed too. In the bulk case it asks **once**, naming the total across every
affected date.

**Because it fails closed, the save says which of the two happened**, in the
same number the dialog asked about. A dialog can be mis-clicked or dismissed by
a browser nobody tested, and silence about silence is how somebody comes to
believe twelve people were told when they were not. Nothing is said when nothing
moved, because then there was never a question.

**The browser decides whether to ASK; the server decides what is SENT.** The
dialog compares formatted values against the ones the server stamped on the
form, which is `movable_diff()`'s own rule, so it needs a second copy of
`sfaf_ap_date()` and `sfaf_ap_time_range()` in `portal.js`. Those are held to
the PHP ones by `.claude/ap-format-crosscheck.php`, which slices the real
functions out of both files. What actually goes out is still decided after the
write, from the before-snapshot, so an answer of send on an event that did not
move sends nothing.

**None of this touches automatic mail.** Registration confirmations, the
morning-of reminder and the two-hour summary are the thing the person signed up
for, are not caused by an edit, and are never gated on an answer.

### The five message types

All five are **on** by default. `_uc_notify_off` records only what somebody has
switched off, so a default costs no writes and an event created before any of
this existed behaves like one created after.

| Kind | To | When |
|---|---|---|
| `confirmation` | the person registering | immediately |
| `alert` | the event's notification list | one per registration, as it happens |
| `cancel_alert` | the event's notification list | one per cancellation, as it happens |
| `reminder` | everybody registered, list copied in | 6am on the day (midnight if the event starts earlier) |
| `summary` | the notification list | two hours before; nothing sent if nobody registered |
| `reinstated` | everybody registered | when a cancelled event is put back on, and only on an explicit yes |

**`kinds()` is a shared field list**, so adding a key to it is the whole of the
wiring: `on()` reads it, `set_off()` intersects against `array_keys( kinds() )`
so the form saves it, `off_count()` counts it, and the caladmin card renders it
by iterating. That is how `cancel_alert` was added in 3.56.0 without a new
mechanism.

> **`cancel_alert` IS NOT `cancelled`.** `cancelled` tells REGISTRANTS the EVENT
> is off. `cancel_alert` tells STAFF that one REGISTRANT has dropped out. The
> keys were deliberately not made near-identical words.

**`reinstated` IS NOT IN `kinds()` EITHER, AND FOR A DIFFERENT REASON FROM
THE SUBMISSION ONES (3.73.0).** `kinds()` is the set of per-event switches a
manager can turn off in advance. This one is not a standing preference: it is
a decision taken at the moment of putting an event back on, in the same
confirmation that asks about cancelling, and it defaults to NOT sending. A
switch that could pre-authorise it would be the opposite of the consent rule.

**IT LEADS ON THE EVENT BEING ON, WHICH IS WHY IT IS NOT `changed`.**
`changed` opens on what moved, and "the date moved" means nothing to somebody
who believes the thing is not happening. The reinstate message says it is
back, then says what the date is now if it moved, then says the registration
still holds. It carries a cancel link in both cases, because a registration
made for a Wednesday and reinstated onto a Thursday is a commitment nobody
re-made.

**AND IT CLOSES AN ORDERING THAT WAS TWO PATHS.** Reinstating and then
changing the date offered the ordinary save's change notice; changing the
date and then reinstating offered nothing, because reinstating had no prompt.
Reinstating always asks now. The first order is still two messages, and that
is correct rather than a leftover: two things happened and the person is told
about both. What is gone is the case where NEITHER was sent.

**THE SUBMISSION MESSAGES ARE NOT IN `kinds()`, AND THAT IS THE DISTINCTION THE
LIST IS FOR.** `kinds()` is the set of **per-event** switches a manager sees on
one event's notification card. The three messages about a submission are about
the workflow rather than about an event, they are decided per submission by
whoever is reviewing it, and there is no event card they could sensibly appear
on. They use the same builder, the same `SFAF_Email::send()` and the same
`wp_mail()`; what they do not do is join a list whose meaning is "which of these
does THIS event send".

| Message | To | Decided by |
|---|---|---|
| Somebody submitted an event | `SFAF_Submissions::alert_recipients()` | a setting, not a role |
| Your event has been published | the submitter, community submissions only | a tick on Approve, off by default |
| Your event was not published | the submitter, community submissions only | a tick on Reject, off by default |

**WHO IS TOLD A SUBMISSION ARRIVED IS A SETTING FROM 3.72.0.** It was every user
holding Admin on the calendar, so the audience was a consequence of who had been
given a role rather than a decision anybody took: giving somebody Admin so they
could fix one event signed them up to every submission from then on, and no
screen said so or could undo it. `alert_recipients()` reads a named list from
`uc_settings`, defaulting to `websites@sfaf.org`, and **falls back to the old
audience when the field is empty**. Emptying the box widens the audience rather
than silencing it, which is the safe direction for a message saying a stranger
has submitted an event to a public calendar.

**THE TWO OUTCOME NOTICES ARE COMMUNITY SUBMISSIONS ONLY**, checked in the sender
as well as on the control, because a control that is not drawn has never been a
permission in this codebase. A staff requester already has a confirmation saying
the team will look at it and can open caladmin to see what happened; somebody
outside SFAF has neither, and a message is the only thing that can tell them.
Both go to the **submitter alone**, never to the other addresses a community
submitter may have named: those were named as people who should receive the
RSVPs, which is a different request from "tell me what happened to what I sent".

> **THE REJECTION IS THE ONLY MESSAGE THIS PLUGIN SENDS THAT TELLS SOMEBODY NO**,
> and its tick is **unticked by default**, which is the opposite of the
> registrations tick beside it on the approval prompt. That one has a useful
> default because an organizer who does not receive their own registrations has a
> real problem. A message that goes out because nobody untangled a default is not
> a decision anybody took, and this one cannot be recalled.

**What `cancel_alert` discloses, and to whom.** The name and email address of
the person who cancelled, plus the resulting count, to the event's notification
list. That is the same information the registration `alert` already gives the
same list about the same person, which is what makes it consistent rather than a
new disclosure; it is written down here because the list is gated on nothing and
this is registrant data. It carries no button and no caladmin link, so unlike
`alert` and `summary` it is built once for the whole list.

**Its Reply-To is the event's, not the person's.** The registration alert replies
to the registrant because a staff member reading it may want to answer them.
Somebody who has just cancelled is not waiting for a reply, and putting their
address in Reply-To on a message to a whole list invites one they did not ask
for.

**The hook it listens to predates it by many releases.** `uc_rsvp_cancelled` has
been fired by `SFAF_Reminders::cancel_rsvp()` since the cancel link was built and
had **no subscriber at all** until 3.56.0. `cancel_rsvp()` clears the count cache
immediately before firing, so the count read in the listener is the
post-cancellation number; that ordering is load-bearing and is the same fault
"the request count cache goes stale on write" records.

**One legacy migration deliberately does not reach it.** The `_uc_notify_organizer`
migration in `sfaf-calendar.php` translates an old "don't email the organizer
about registrations" checkbox into `alert` being off. It does **not** also switch
off `cancel_alert`, because that checkbox was ticked when this message did not
exist and inferring an intention about it would be inventing one.

### The notification list, and why two messages ask different questions

The list is gated on **nothing**. It holds calendar contributors, people reached
through a team, and typed addresses that are not accounts at all.

So the `alert` and the `summary` are both built **per recipient**, because both
carry a link into a gated screen:

- `alert` links to `/caladmin/rsvps`, gated on `can_view_all`.
- `summary` links to `/caladmin/events/edit/N`, gated on `can_edit_event`, which
  is `can_view_all` **or** being the event's author.

Testing the summary against `can_view_all` would leak nothing and still take the
link away from the contributor whose own event starts in two hours, the person
most likely to want it. Anybody without the capability gets the public event
page instead.

**The capability is asked of the resolved user record, never of the address.** A
team is resolved to people and each person is checked individually, because a
team is a set of names and not a permission. A free-text address is never
offered the link even when it happens to match somebody's account, because it is
a string typed in a box rather than that person.

There are only ever two versions of each message, so a list of thirty people
costs two builds.

### Cancellation tokens

A registration gets a **128-bit token** (`SFAF_Reminders::new_token()`). The
cancel link is `?uc_rsvp_cancel={token}` on the site root. The token is the only
credential, which is what lets cancellation work with no account.

**A GET never cancels anything.** Mail clients, security scanners and link
previewers fetch the URLs in an email without a person ever clicking, so a
one-request cancel would drop people's places for them. The link opens a page
that asks; the POST from that page acts.

### Following a series

**Three lanes of mail exist and must not be conflated.** A **registration** is
somebody holding a place: they get the confirmation, the morning-of reminder and
the two announcements. **Following a series** is somebody who wants to know when
new dates are added to it, and that is the only thing they ever receive. The
**SFAF newsletter** is marketing consent, recorded in `uc_optins`, destined for
Pardot and blocked on Salesforce credentials.

`SFAF_Follow` is lane 2, and its table is `uc_series_followers`. Added 3.53.0,
which is also when the button on the public event page stopped being lane 1
wearing lane 2's label.

**What it replaced.** "Get Reminders" wrote a row into `uc_rsvps` at status
`subscribed`, against a single event id, for somebody who held no place. Three
things followed from that one decision, and they are the argument for the
separate table: they were mailed the morning-of reminder for one occurrence,
every query asking "who is registered" had to remember a second status, and two
pieces of email copy plus the whole cancel flow had to branch on
`$is_subscriber` to stop telling them they had released a place they never took.
The storage was making a claim the code then spent its time denying. There were
no live rows, so nothing was migrated.

**A follower is recorded against the term, never against an event.** Uniqueness
is `(term_id, email_key)` where `email_key` is a sha256 of the **lowercased**
address: following twice leaves one record and case is not a second person. The
hash rather than the address itself is the reminder ledger's reason, an index
limit, not secrecy. The same address may follow several series; each is its own
standing instruction.

**Pending until confirmed, and a pending record is in no audience.** Typing an
address is not permission to mail it and anybody can type anybody's, so a
submission writes a pending row and an email asks the address to confirm.
`active_followers()` selects `status = 'active'` only, so no caller anywhere has
to remember to filter.

**Two tokens, and they are not interchangeable.** `confirm_token` activates the
record and is **cleared the moment it is used**, so the link cannot be replayed.
`token` unsubscribes, is issued at the same moment, **is in that very first
email**, and never expires. The mechanism this replaced minted a token at
subscribe time and delivered it to nobody; the only message carrying one arrived
on the morning of the event, by which point stopping reminders stops nothing.

**Lifetimes, and what each was matched to.** The unsubscribe token never
expires, matching the RSVP cancel token: a credential whose only power is
removing somebody from a list must still work the day they go looking for it.
The confirmation link lives **30 days**, matching `SFAF_Request::COOKIE_DAYS`,
which answers the same question with a different verb — how long after somebody
typed their address into a public form is it still reasonable to act on. It is
deliberately not `SFAF_Request::TOKEN_TTL`, which is 60 minutes because that
link opens a form that can create an event and is priced as a login link. This
one grants no capability and creates nothing.

**Unconfirmed records are swept on write, not on a schedule.** They can only
accumulate while submissions arrive, and a submission is when the sweep runs, so
the pile is bounded by its own inflow. An out-of-time row is also rejected at
resolution, so it is inert before anything deletes it. No entry in
`SFAF_Cron::tasks()`, for the same reason `SFAF_Request` keeps its tokens in
transients.

**A GET never acts, in either direction.** The cancel link's rule, applied to
both links here: a previewer following the unsubscribe URL would drop somebody
off a list they wanted, and one following the confirm URL would activate a
record the person never answered, which is exactly what confirming exists to
prove. Both open a page that asks; the POST acts.

**The same answer whatever happened.** Sent, already following, and rate limited
all render one sentence, which is the staff request form's rule and its
reasoning: a different screen for any of them answers "is that address known
here", and on a calendar carrying HIV testing and trans health programmes that
is not a question a public form should answer. A malformed address is the one
thing said plainly, because it discloses nothing and somebody who mistyped their
own needs telling. Rate limited on **address and client, both required**.

**The button renders only on an event in a series**, because a one-off has no
future dates to hear about. The lookup is `get_the_terms()`, which reads the
object term cache the single template has already primed for the "Part of
series" link, so the gate costs no query. Whether it renders at all is
`_uc_show_reminders`, the Display tick it always was; the **key is deliberately
unchanged**, because an absent value means on and a new key would have switched
the control back on wherever somebody had turned it off.

**Nothing writes to `uc_optins` and nothing feeds the newsletter.**
`.claude/follow-test.php` asserts that structurally, along with the absence of
any read of `uc_rsvps`, because those are the invariants the release exists for.

### The cron trigger, and why WordPress cron is not enough

There is **one** runner, `SFAF_Cron`, on a **15-minute** recurrence, for every
unattended job. `SFAF_Cron::tasks()` is the single list of them: the reminder
pass, the pre-event summary, the third-party fetch, the queue sweep and the
orphan check. `run()` iterates it and
the Automation screen iterates it, so a job cannot be run without appearing on
the screen and cannot appear without being run. Anything added later joins that
list rather than scheduling its own event, so there is one lock and one log.

**The fetch is reported in two places and recorded in one.** Since 3.57.0 the
caladmin Pending queue carries a box for the last automatic fetch: when it ran,
what each source found in that source's own words, which source failed, and
whether anything has succeeded within the hour. It exists because the person
reviewing imports stands in caladmin and the run log is rendered in wp-admin,
so the queue could be empty for two entirely different reasons and look the
same either way. **Both screens read `SFAF_Cron::task_report()`**; there is no
second store, and the two cannot come to disagree about a run because there is
only one record of it.

What the log entry gained for this is the per-source breakdown it was already
carrying as one joined sentence: `run_fetch()` now records each source's label,
state and line separately as well. **That breakdown is not recoverable from the
joined summary** and must not be reconstructed by splitting it — a failed
source's line contains the platform's own error text, which may hold the
separator. `'last'` and `'last_ok'` are likewise kept apart, because a fetch
failing on every pass has a fresh `'last'` and a stale `'last_ok'`, and reading
the first as the second reports a broken fetch as a healthy one.

**"Fetch updates" in caladmin is a different path and always was.** It calls
`SFAF_Sources::run_all()` directly and reports through a per-user transient, and
it never touches the run log. So the Pending box does not move when that button
is pressed, which is why it is headed "Automatic fetching".

**The 15-minute interval exists for the pre-event summary**, which is due two
hours before an event starts; an hourly run can be up to an hour late for it.
Every job is idempotent and most passes find nothing due, so a run with no work
costs one `WP_Query`. The hook name is still `sfaf_cron_hourly`, which is
historical; `ensure_scheduled()` migrates any install still on `hourly` by
asking `wp_get_schedule()` what the hook is actually on, because "is it
scheduled?" would find the old event present and leave it forever.

The recurrence is registered by an `add_filter( 'cron_schedules', ... )` at
**file scope in `sfaf-calendar.php`**, not from `SFAF_Cron::register()`, and the
placement is load-bearing. Core hangs `wp_cron()` on `init` at priority 10 and
registers it before this plugin's own `init` callback at the same priority, so
`wp_cron()` runs first, and its response to a recurrence it cannot resolve is to
**unschedule the event**. Registering the filter any later would have the runner
delete itself on the first request after an update.

**WordPress cron is not a scheduler. It is a check that runs when somebody
visits the site.** On a calendar nobody visits at 6am, a 6am job simply does not
happen. This is a low-traffic site, which is exactly the case the pseudo-cron
fails.

Three things drive the runner, and it does not care which:

1. **An external scheduler** requesting `wp-cron.php?doing_wp_cron` every 15
   minutes, with `DISABLE_WP_CRON` set. This is the intended setup, and it is
   deliberately an external service rather than a server cron job so the plugin
   needs no server configuration and stays portable.
2. **The embed script.** Any page carrying a calendar, including sfaf.org, which
   has far more traffic than resources, pings `admin-ajax` (`sfaf_cron_ping`),
   throttled to once every 15 minutes. It is on `admin-ajax` and not a REST route
   because of the exact-route-string CORS gate in §1.
3. **"Run now"** in the admin.

**Why an external scheduler must be pointed at `wp-cron.php` and never at (2).**
The ping endpoint calls `run()` directly, so it runs this plugin's jobs and
nothing else. `wp-cron.php` dispatches WordPress's whole schedule: core's own
maintenance, every other plugin's jobs, and this plugin's among them. Under
`DISABLE_WP_CRON` nothing else dispatches that queue, so a scheduler aimed at
the ping endpoint would keep the calendar working and quietly stop everything
else on the site. The endpoint also throttles to one run per 15 minutes and
answers 204 inside that window, so an external 15-minute ping would land on the
boundary and be refused about half the time. `wp-cron.php` needs no parameter,
header or key, and never consults `DISABLE_WP_CRON`: that constant only
suppresses the spawn from ordinary page loads.

**THE ORDER THESE ARE SWITCHED ON IN IS LOAD-BEARING, and getting it wrong
means nothing runs at all.** `DISABLE_WP_CRON` stops the pseudo-cron before
anything has been proved to replace it, so it goes LAST:

1. **Create the external ping first.** Every 15 minutes, at
   `wp-cron.php?doing_wp_cron`, with no parameter, header or key.
2. **Confirm on Events > Automation that tasks are running**, from that ping
   rather than from your own page views.
3. **Only then set `DISABLE_WP_CRON`.**

Reversing 1 and 3 leaves a site with no scheduler at all, and the only symptom
is that nothing happens overnight.

**(2) stays switched on after (1) exists, and is not a second scheduler.** The
health check that sends the "tasks have stopped" alert hangs off `wp_loaded`,
deliberately not off the runner, since a monitor inside the thing being
monitored fails exactly when it is needed. `wp_loaded` fires on a `wp-cron.php`
request too, so an external scheduler drives the monitor as well as the work.
The hole it cannot close is the trigger dying while nobody visits: no request
means no `wp_loaded`, no check and no email, and no monitor inside a site can
report that the site is never visited. The nudge is a request from a site that
does have traffic, arriving whether or not the scheduler is alive, and that is
what closes it in practice.

**The Automation screen answers "is this working?" in a sentence** before any of
the machinery, in four states: working, running late (45 minutes with nothing
completed), stopped (three hours, or three failed runs), never run. The 45
minutes and the three hours are separate judgements on purpose: the longer one
decides when to wake somebody by email and must not fire on one hiccup, the
shorter one decides what to tell somebody already looking at the screen, where
being told early costs nothing.

**Two independent layers stop a double send.** The run lock is an
`add_option()` claim (`option_name` is UNIQUE, so the second run stands down),
with a 15-minute TTL so a fatal mid-run cannot stop the runner permanently and
silently. Under it, the reminder ledger has a UNIQUE key on
`(event_id, recipient_hash)` and the row is **inserted before the send**, so a
second attempt fails the insert and skips. The second layer needs no cooperation
from the first and holds even with the lock broken.

A send that fails is recorded as failed and **not retried**: `wp_mail()`
returning false does not reliably mean nothing was delivered, and retrying risks
the exact duplicate the design exists to prevent.

**Imported events are excluded from reminders outright.** The platform holds the
registrations and sends its own. The check is on the *presence of a source*, not
on a named platform, so a new adapter is excluded the moment it exists rather
than the day somebody remembers to add it.

---

## 5. Permissions

### Two concepts, deliberately separate

**ACCESS** derives from `manage_options`, asked first, in
`SFAF_Portal::get_role()`. A WordPress administrator has full calendar access
and the stored record **cannot reduce them**. Below that, `_uc_calendar_role`
holds one of `admin` / `editor` / `contributor`; any other value is no access.

This order is a fix. It used to read the meta first and fall back to
`manage_options`, which meant an administrator who added himself on the Users
screen so he could join a team got the form's default level written against his
account and was then held to it. His WordPress role was never touched; the
calendar simply stopped believing it.

**VISIBILITY** comes from *having a calendar user record at all*, which is what
Users and Permissions creates. Only people with a record appear in the
notification picker and the team picker.

### The event gate: one function, asked by every route

Since 3.35.0 **`SFAF_Portal::user_can_edit_event( $user_id, $post )` is the only
answer to "may this person edit this event"**, and every route that reads or
writes an event, or reads its registrations, asks it. Not a check per route:
four of the five defects below were routes that *had* a gate and had the wrong
one, which is what a second copy of a rule produces.

It answers in this order, and the order is the design:

1. **`user_can_view_all()`**, calendar admin or editor,, which resolves
   `manage_options` first. Nothing below can reduce this. (3.7.0)
2. **The organizer**, which is `post_author` and nothing else. There is no
   second field to keep in step and no snapshot. It never changes implicitly;
   only the reassignment on the Users screen and WordPress itself move it.
3. **A team that owns the event**, but only after `get_role()` confirms the
   person has calendar access at all.

That third order matters and is not defensive tidiness. A team is a name and a
set of user ids, and the `$offered` guarantee means it may legitimately hold
somebody with no calendar record: a person added so they could be mailed, or one
whose access was withdrawn while their membership stayed. Answering *true* for
them would be worse than useless, because they cannot pass the portal's entrance
gate, so every link the answer produces opens a "Denied" page. The pre-event
summary asks exactly this question to decide whether to send such a link. That is
defect five below, rebuilt out of new parts.

**Team membership is live.** Nothing is copied onto the event, so joining a team
grants access to every event that team already owns and leaving removes it, both
without touching any event. Same resolve-at-read-time rule teams already followed
for notifications, and the reason a team is a set of ids rather than a snapshot.

**An event may name up to two teams**, in `_uc_event_teams`. That is a
**different key** from `_uc_notify_teams`, deliberately and permanently. Reusing
the notification key would have made this a retroactive permission change: every
event that had ever named a team for notification would have granted that team
edit access the moment 3.35.0 was activated, on data entered when the field meant
something else, with nobody asked and nothing said. Access starts empty on every
existing event.

**Notifications do not follow access.** Assigning a team grants access and
nothing else; a separate checkbox, off by default, also puts it on the
notification list. A team generally wants to log in and read who has registered,
not receive an email per registration. The organizer is always notified.

**Only somebody who can already give access away may assign a team**, so the
control is `can_view_all` on both the renderer and the save. A contributor gets a
separate read-only render naming who has access, not a disabled input: a disabled
input is a control that posts nothing today and posts something the day somebody
removes the attribute.

**Registrations follow the same gate, scoped.** `/caladmin/rsvps?event_id=N` and
its CSV export ask the event gate; unscoped, both stay on `can_view_all`, because
"every registration on this site" is not a question about any event and no team
owns it. This also widened the scoped view to a contributor reading their own
event's registrations, which is deliberate: the alternative is a second rule
saying team members may read an event's registrations but the person responsible
for it may not.

**What did not change.** "My events" for an admin or editor is still a literal
author filter, because it is a label a person reads. The public read-only table
for other people's events is untouched. Picker visibility still requires a
calendar record.

### Events with no organizer

An event whose organizer has lost calendar access is editable by calendar admins
and nobody else, and nothing used to say so. Two defences, because the two ways
it happens are different:

- **Removing somebody on Users and Permissions refuses to finish** while they
  organize anything. It names the count, lists the events, and asks for a new
  organizer, who must be somebody with calendar access. Same shape as refusing to
  delete a team that is in use. This is the deliberate path and produces the good
  answer, because a person is present who knows who should take the work on.
- **WordPress's own Users > Delete bypasses all of that**, and it is the normal
  way staff leave. `SFAF_Orphans` runs a check once a day from the ordinary
  runner and emails every calendar admin, naming the events and linking to each.

It **alerts on change, not on state**: a message goes out when the set of
orphaned events differs from the set last reported, and never otherwise. The same
list every morning is noise, noise gets filtered, and then the one that mattered
is filtered too. Four days of the same three events is one email; a fourth
appearing is a second naming all four; everything being fixed is a third saying
so. A daily check rather than a `user_deleted` hook because a hook fires once, at
a moment nobody is watching, and cannot see the second cause at all: a calendar
record withdrawn while the WordPress account stays.

So an administrator who has not added themselves has full access and does **not**
appear in pickers, and that is correct: **a picker is a list of the people who
work on this calendar, not a list of everybody who could.** 3.14.0 got this
wrong and "fixed" missing administrators by widening the picker, which quietly
made the two questions one list. The real fault was that nothing recorded which
question the picker asks.

Access is never written as a side effect. No `set_role`, `add_cap` or
`wp_capabilities` write except from a control whose only purpose is that.

### This is a known weak point. Five defects, same family.

Five separate times, attendee data or a destructive action has been found behind
the wrong gate. **Treat any new screen, route, export or email link that touches
registrations as guilty until the gate is named.**

1. **The `/caladmin` registrations screen was ungated** (found 3.5.0). It relied
   on not appearing in the sidebar, which is not a permission. A contributor who
   typed the URL could read every registration on the calendar.
2. **The dashboard leaked registrant names.** Two of its seven elements ignored
   access level: Total RSVPs counted every confirmed registration on the
   calendar, and Recent activity read the whole registration table, which is
   the worse of the two, because it *names people*. A contributor read that a
   named person had registered for an event they cannot open or edit.
3. **`uc_export_rsvps` was gated on `edit_posts`.** It was the download link on
   the WordPress RSVP page, so any Author on the site could fetch the full
   registration list as CSV by calling it directly. Removed with its screen.
4. **`POST /sfaf-calendar/v1/rsvp` had `permission_callback => '__return_true'`**
   was an unauthenticated public write. Nothing had ever called it; it had also
   been broken since 3.26.0. Being broken is the proof nobody called it, not the
   reason it was removed. A public write path that nothing uses is not made safe
   by being broken.
5. **The registration alert and the pre-event summary emailed gated links to an
   ungated list** (3.26.0, 3.26.1). See §4.

The standing consequence: **a permission-sensitive view gets a separate
renderer, never a flag.** A method with no capability to leak cannot leak:
absent columns rather than hidden ones, nothing clickable, widening stated
explicitly.

The second standing consequence, from 3.35.0: **one function answers the
question, and a whitelist proves every route asks it.**
`.claude/event-access-test.php` names every route that reads or writes an event
or its registrations together with the gate it is allowed to use, and fails in
both directions: a route in the source that is not on the list, and a list entry
whose route has gone. Being right today is not the property being protected. It
also sweeps for a `post_author` comparison written anywhere outside the gate,
which is how a second and quietly different answer gets into the codebase.

`.claude/route-gate-inventory.php` is the companion that does not fail: it prints
what each route requires, for a person deciding whether a gate is the *right*
one. No script can answer that, and four of the five defects above were routes
that had a gate.

Two supporting rules that came out of the same family. Attendee data is **not
searchable and cannot become searchable by accident**: `SFAF_Search` whitelists
what to include rather than listing what to skip, so a field added later is
unsearchable until somebody adds it deliberately. And registrations are reached
only from the event they belong to.

**On why this matters more than usual here:** this calendar carries HIV,
substance use and trans health programming. The *size of a group* is itself
worth not saying.

---

## 6. Built but never exercised

Be honest about this in any hand-off. Almost everything in this plugin has been
**statically verified and never executed** in the situation it was written for.

**PROVED ON 2026-08-18, AND IT WAS THE MOST VALUABLE UNVERIFIED THING HERE.** A
morning-of reminder went out **unassisted at 6:58am** against real
registrations. That settles the scheduled path end to end in one observation:
cron fired without a person, the reminder pass found the event, the send-once
ledger let it through, the 6am timing rule held, and the mail left the server.
Everything downstream of "does the unattended path work at all" is a
**reliability** question from here, not an existence one.

**AND TWO MORE WERE SETTLED ON 2026-09-03.**

- **The one-time import RAN.** Not a dry run: 287 drafts across 32 series exist
  on the site and were read and accepted. It ran out of order and twice,
  followed by two partial clears, and the outcome was still correct, which is
  worth recording because it is evidence about the clear's spare rules rather
  than about the happy path. See §3.
- **The updater completed a real cycle.** 3.70.1 and 3.71.0 were released, the
  Plugins screen offered 3.71.0, and it installed. So the whole arrangement,
  the release asset naming, the version comparison, the twelve-hour cache and
  WordPress's own update UI, is proved rather than reasoned about.

**Still never run in production:**

- **The pre-event summary has never been seen.** The morning-of reminder is its
  sibling and is now proved; the two-hour summary needs an event with somebody
  registered and a mailbox being watched, and that has not happened.
- **The external scheduler has never driven a run.** What fired on 2026-08-18
  was the page-view nudge and visitor traffic. The cron-jobs.org ping does not
  exist yet, so `DISABLE_WP_CRON` is not set and the pseudo-cron is still what
  the site depends on. See §4 for the order that has to be followed.
- **No email has been confirmed as RECEIVED.** One has demonstrably been sent,
  which is a different fact. Whether Postmark forwards Reply-To, and whether a
  `text/plain` part arrives at all, are properties of the transport visible only
  in a received message. `send_test()` exists to find out and the answer has not
  been recorded.
- **Neither import adapter has been observed running unattended.** GFMP and
  Eventbrite have been exercised by hand; the hourly fetch path has not.
- **The bulk publish has been seen, and its per-row ticks have not.** The 3.71.0
  all-or-nothing button was pressed on a real series and read correctly. 3.72.0
  put a tick on every eligible row, so what is unexercised is now the narrowing:
  that unticking two publishes the rest and leaves those two alone.

**Tested only by their author, once, by hand:** most `/caladmin` workflows.
Event creation and editing, the schedule editor, bulk edits across a recurrence
group, the pending queue, teams, venues, categories, private events. There is no
second user, no QA pass, and no automated coverage of the portal.

**Dormant on purpose, not dead:** the satellite REST feed. With no key it
returns 403, and that reversal is what makes clearing the key a safe way to turn
it off.

What *is* actually proven: the PHP linter and the callable audit run on every
build (see `CLAUDE.md` §2), and the committed test scripts in `.claude/`: the
recurrence cross-check, the email render test, the private-events whitelist, the
date sweep, the rendered parity tests, the embed width probe.

### The events a save cancelled, and why the list of them is short

**THE BUG IS FIXED AND THE DAMAGE IS NOT, WHICH IS WHY THIS IS HERE RATHER THAN
IN THE HAND-OFF.** Between 3.36.0 and 3.40.0 a save could cancel an event on its
own, and each affected event reinstates from its cancel card. That part is
ordinary.

**THE PART THAT OUTLIVES THE FIX IS THAT THE CANCELLED LIST IS NOT THE WHOLE
LIST.** A second save silently un-cancelled an event, so an event that was
wrongly cancelled, mailed its registrants, and was then saved again looks
untouched today. **`_uc_cancelled_at` is the timestamp of the save that did
it**, which is the only handle on the set: an event whose cancellation
timestamp falls in that window and which is not currently cancelled is one of
these.

**The emails cannot be unsent.** Whoever was registered was told the event was
off, and reinstating it now sends the "back on" message from 3.73.0, which is
the right thing and is not a correction of the original.

**Why this is durable.** It is a property of the data this calendar holds, not a
thing in flight: it will still be true in a year, it will still be true after
every affected event is found, and anybody auditing cancellations needs to know
that a clean cancelled list does not mean a clean history.

### Four things that need somebody outside the code

**Kept here rather than in the hand-off because none of them is about the
current situation.** Each has been true for several releases and will go on


| | What is needed | What happens meanwhile |
|---|---|---|
| **The GFMP campaign image** | The field name that carries it, found by running the `[PROBE]` in `class-sfaf-gfmp.php` against a real response | Every imported campaign shows the category placeholder instead of its own picture |
| **The Turnstile keys** | Both the site key and the secret, in Settings | There is no widget at all on either public form, so neither has a bot check |
| **The Cycle to Zero series** | Somebody to create it | The community form's address is that series' slug, so the link a submitter is given resolves to nothing |
| **The test event** | A decision about post 60379 | It is live, `pending`, and badged Community submission |

**The first two are pairs and neither half is useful alone.** A Turnstile site
key with no secret is a widget that cannot verify; the probe's answer with
nobody to map it is a field name in a log.

## 7. The lessons that cost time

These are recorded because each cost more than one build and each recurred.

### Read these four before a first change

Standing hazards rather than lessons from one defect, and they were in
`HANDOVER.md` until 3.54.0, which is the wrong file: none of them is about the
current situation and all four will still be true in six months.

- **Approving a submission sends registrant data outside SFAF, ticked by
  default.** The second tick puts the submitter's address on the event's
  notification list, so they receive the **registration alert** naming whoever
  just registered AND the **morning-of summary listing every registrant by name
  and email address**. This is deliberate and is right for the person running
  the event, but it means the name on that prompt decides who sees a list of
  people who signed up for an HIV testing or trans health event. Read it before
  pressing Approve. §1 carries the reasoning.
- **Nothing in caladmin warns about unsaved work.** There is no `beforeunload`
  handler anywhere in the portal: close a tab or follow a link mid-edit and
  everything typed is gone, silently, on every screen. This is also why a
  control that creates something is a field on the form and never a button that
  posts; see "Create-from-the-editor is a field, never a button".
- **A cancelled event is still `publish` with a date**, and teams are an access
  model rather than a snapshot. Anything querying events has to ask
  `SFAF_Cancellation` as well as the post status, and anything deciding who may
  edit one has to go through the single gate. §4 and §5 before touching a query
  or a route that reads one.
- **The confirm, unsubscribe and cancel pages are documents this plugin writes
  itself.** They are handled on `template_redirect`, so `wp_enqueue_scripts` has
  not run and `wp_head()` is never called. A stylesheet cannot be enqueued onto
  them and a theme cannot reach them. `sfaf_notice_page()` is the one renderer
  all three use, and it was two until 3.55.0: the cancel page was still going out
  through `wp_die()` with no stylesheet three releases after the follow page was
  fixed, which is what a second copy of a document shape costs.
- **Weglot is a separate, site-wide plugin, and the calendar only hides its
  switcher.** It appends the control after `</html>`, which every browser
  reparents into `<body>`, so it floats over any document the site emits
  including the ones this plugin writes. Suppression is scoped to
  `body.uc-portal`, `body.uc-notice-page` and `body.uc-calendar-page`, and an
  unscoped rule in either stylesheet would take the switcher off pages that have
  nothing to do with the calendar. Nothing here detects, sets or translates a
  locale, and 3.16.0 is why that sentence is written down.

**A heuristic that was right about its own case and wrong about everybody
else's.** `looks_like_a_filename()` blanks a picture's title when it matches the
file it came from, which is exactly right about the titles WordPress derives on
upload and is a GUESS about titles a person typed. The guess fails in the
commonest case there is, because a well-named file is usually named after the
picture: "Cycle To Zero" on `cycle-to-zero.jpg` was thrown away.

**The cost was that the remedy did not work.** 3.76.0 answered "the picker shows
file names" by adding a name box to the Images screen, and somebody could type a
name there, save it, and watch every picker go on showing the file name. Two
releases of a correct diagnosis and a remedy that could not take effect.

> **WHERE A HEURISTIC GUESSES AT INTENT, RECORD THE INTENT INSTEAD.** The
> question was never "does this string look derived", it was "did a person
> choose this", and that is a fact available at the moment somebody types it.
> `rename()` marks the attachment and the reader trusts a marked title without
> asking the heuristic at all. The heuristic stays for everything nobody has
> named, which is what it was written for. **A guess is the right tool only
> where the answer cannot be known**, and the moment a screen exists that could
> know, the guess should stop being consulted about what it produced.

**And an empty state that reads as a fault will be reported as one.** A card
showing `dsc_0043.jpg` beside one showing "Cycle To Zero" looks broken. It was
reported twice, and both times the answer was "that is the rule working". The
screen says it now, in one line pointing at the box rather than explaining the
rule, plus a marker on the cards where it is true. Same lesson as the picture
chooser showing an ungrouped list when nothing is tagged, and this is the second
time this feature has produced it.

**A layout floor set from the wrong element.** The Images grid used
`minmax(150px, 1fr)`, chosen and written down as "a thumbnail somebody can
recognise a photograph in". The widest thing in the card is not the photograph:
it is a `<select>` that has to render "Mobile Health Sites" without clipping.
Six cards across, the dropdown clipped after the word "Add", and the one thing
that control exists to show was the thing it could not.

> **A GRID'S MINIMUM IS SET BY ITS WIDEST CONTROL, NOT ITS LARGEST PICTURE.**
> A picture scales; a select, a date input and a button do not. Ask what the
> least compressible thing in the cell is before choosing the floor, and if the
> answer is "a control", the floor is whatever that control needs to be
> readable at.

**A feature built on the surface the product does not have.** 3.75.0's hover
preview went into `calendar.js`, which is the shortcode's script. **This
calendar has no front end on resources.sfaf.org: it exists only as an embed on
sfaf.org**, which runs `embed.js`, a separate jQuery-free implementation. The
markup was right, both routes call the same renderer, the test passed, and the
feature never executed anywhere a person could reach it.

> **ASK WHICH SURFACE THE THING ACTUALLY RENDERS ON BEFORE WRITING THE
> BEHAVIOUR FOR IT.** This plugin has two front-end scripts and one of them is
> the only one production uses. A feature that touches the public calendar is
> an embed feature by default, and the shortcode is the copy, not the original.
> Four of the six past faults in this area were embed-only for the same reason.

**And the test was the wrong kind of green.** `hover-preview-test.php` asserted
the top layer, the hover gate, the delay and the UA box, all correctly, all
against `calendar.js`. A test can be right about every property of a file that
is not the file anybody loads. **Where a behaviour has to exist on two surfaces,
the FIRST assertion is that it exists on both**, before any assertion about what
it does; that one is now the first thing that file checks.

**The remedy was the one this project already had.** The recurrence engine lives
in PHP and in JavaScript and is kept honest by a cross-check that slices the JS
between markers. The preview is now one block between markers in two files,
compared byte for byte by the build. A shared third file was rejected: on a
third-party page it means a second network request, injected by a script already
deriving one URL from its own src, with an ordering question attached.

**Correct behaviour that is indistinguishable from broken.** The picture chooser
groups a series' own images above the rest, and with nothing tagged it shows one
ungrouped list of everything, which is exactly what "the filter does not work"
looks like. The same shape twice over: every picture in the folder falls back to
its file name because none has a title a person typed, which is 3.65.0's rule
working, and reads as a regression.

> **A FEATURE WHOSE EMPTY STATE LOOKS LIKE ITS BROKEN STATE WILL BE REPORTED AS
> BROKEN.** Neither of these was a defect and both cost a round trip to
> establish. Where a control's output depends on data somebody has to enter
> first, either say so on the screen or ship the means to enter it in the same
> release. The Images screen got a name box in 3.76.0 for exactly that reason:
> the rule was right and there was nowhere to give it anything to work with.

**A check that could not fail, written the same day as the thing it checks.**
`.claude/palette-audit.php` was added in 3.75.0 to stop an unusable category
colour being added, and its first draft asked whether every colour had a neutral
over 3:1. Against Dark Gray and white that is arithmetically guaranteed:
clearing 3:1 against the first needs a luminance under 0.2155, against the
second needs over 0.30, and nothing is in both bands, so the worst any colour
can do is 3.44:1 at the crossover. **The check was a statement about arithmetic
and would have passed any shade anybody ever added**, while reporting in its
own summary that the palette had been verified.

> **A FLOOR HAS TO BE ONE A REAL VALUE CAN FALL BELOW.** Before writing a
> threshold, work out what the WORST possible input scores against it. If
> nothing can fail, the check is decoration and a confident summary under it is
> worse than no check, because it is what stops the next person looking.

**What caught it was the self-test, exactly as intended.** The plant was "a
colour with no neutral over 3:1", and there is no such colour to plant: the
self-test reported MISSED, which is how a checker says its own floor is
unreachable. That is the third time a self-test has found a fault in the checker
rather than in the code, after the possessive quantifier in 3.20.0 and the two
assertions `updater-test.php` had to have rewritten in 3.73.0.

**A regex that worked until the file grew.** `filter-bar-test.php` located a
button with `<button\b(?:(?!<button\b).)*?data-category="all".*?</button>` over
the whole five-thousand-line shortcodes file. Adding forty lines to that file
exhausted PCRE's JIT stack, `preg_match()` returned **false** rather than 0, and
the test read a false as "not found" and failed. Nothing it checks had changed.

> **preg_match() HAS THREE ANSWERS AND MOST CODE TREATS IT AS TWO.** 1, 0 and
> false, and false means the pattern gave up rather than the subject not
> matching. Any nested quantifier over a whole source file is a pattern that
> will eventually give up, at a size nobody can predict, and the failure arrives
> as a confident assertion that something is missing. Where the question is
> "find this literal and look at what encloses it", `strpos()` and `strrpos()`
> cannot backtrack and cannot lie.

**A rule that was right in one context, one selector along.** The month grid
hides the thumbnail in a 40px cell on a phone and sets `gap: 0` with it, because
with no thumbnail the gap is dead space at the start of the line. Both halves
are correct about the cell. The day panel below the grid puts the thumbnail
BACK, and inherited the `gap: 0`, so the picture sat flush against the title on
every phone.

> **THE TWIN OF THE CASCADE FAULTS, AND IT NEEDS THE OPPOSITE SEARCH.** Nothing
> lost on specificity and nothing was missing, so neither the cascade arithmetic
> nor the padding audit could have found it. A rule whose correctness depends on
> a condition (here, "there is no thumbnail") has to be checked in every context
> it reaches where that condition is FALSE. Ask what a rule assumes, then go
> looking for the place that assumption does not hold.

**Three ways in, and the investigation only knew about two.** The FAQ answers
were reported as "the editors do not start", and three releases were spent on
the two paths anybody could name: rows that come from the server, and rows the
repeater's Add button clones. The rows that were actually plain came from a
THIRD control nobody had counted, the FAQ set picker, which builds rows from the
same template and asks for nothing. Every finding about the other two paths was
correct and none of them was the fault.

> **Count the ways a thing can arrive before deciding which one is broken.** The
> question "why does this row have no editor" has as many answers as there are
> controls that can create a row, and a screen accumulates those one release at
> a time. Enumerate them from the code that CREATES, not from the code that
> consumes: `startAll()` could not have told anybody that a third caller
> existed, and neither could any amount of reading it.

**And the silence was the evidence.** 3.72.0 added logging to the catch around
`wp.editor.initialize()` precisely so the exception could be named, and nothing
appeared in the console. That was read as "the logging did not reach the site"
and was in fact the finding: an initialise that never happens throws nothing.
**A check that stays quiet has told you something**, and the thing it told us
was that the exception theory was the wrong theory.

**A fix that "looks like the path that works" is not a diagnosis, and 3.72.0
said so at the time.** It put the load pass through the same deferred task the
add pass used and wrote down, in the code, that this was not a claim to have
fixed anything. That honesty is what made 3.74.0's investigation start from the
right place instead of assuming the matter was closed.

**A correct rule that never reached the screen. Six times.** A component rule
written as one class, `(0,1,0)`, sitting under a host-proofing base rule written
as class plus element, `(0,1,1)`. One class does not beat one class plus one
type, so the base rule wins every property it declares and the component rule is
dead without looking dead. It has hit the sidebar nav (link teal at **2.30:1**
for two releases, under a measured contrast table that never applied), the
sidebar headings, every pill on the public calendar (`border-radius: 99px`
losing to a reset's `0`), the RSVP consent checkbox, and thumbnails. The two
conventions are each correct and collide silently.

> **Compute the cascade before rewriting.** Then pick a remedy deliberately:
> raise the component to two classes, drop the base to zero with `:where()` when
> it is meant as a floor, or *split* the base rule when its properties want
> different things (a defense stays high, a floor goes low).

**A rule that was never written at all, which looks identical from the screen.**
The twin of the above and a different repair. `.uc-admin-card` declared no
padding, so a card looked correct only when every child happened to carry its
own: table cells do, and headings, paragraphs and forms do not. Automation's
cards are made of exactly the ones that do not, so its headings and controls
rendered against the border. The same shape produced the unstyled controls found
in 3.16.0, and the `-actions` baseline written in 3.20.0 could not help, because
that convention lives in `portal.css` and reaches no WordPress admin screen.

> **Ask which of the two it is before rewriting.** A losing rule and an absent
> rule look the same on screen and want opposite fixes: one is a specificity
> problem, the other is a missing baseline. A container owns its own padding;
> anything relying on a child that happens to carry some is unstyled the moment a
> child without any is added. `.claude/admin-padding-audit.php` is the check.

**A test fixture that removed the property under test.** The first draft of
`embed-combined-panels-test.js` put `data-view` on the block instead of the
container, so `viewFor()` answered `'list'` for every mode and the test passed
while the mode rendered nothing. The parity test had the same shape from the
other side: with only one card in the fixture, the height cap that squashed
twelve cards to 40px strips left nothing wrong to see.

> **Make a new checker fail on purpose before trusting it.** Plant the fault it
> is supposed to catch. Every committed test in `.claude/` has been proved this
> way.

**A stub that removed the mechanism under test.** The private-events test
asserted the slug round trip correctly and stubbed `wp_update_post()` as "write
the fields and return". A stub cannot fire `post_updated`, so
`wp_check_for_changed_slugs()` never ran and the entire core mechanism that
decides what a private event's *old* address does was invisible. Every assertion
passed for three releases while a private event kept answering on its public URL
and redirecting to the secret one. The fix was to model that slice of core
rather than stub it, including a real hook registry so the filter is called
rather than assumed.

> **Ask what your stub replaced, not just what it returns.** A stub standing in
> for a function that fires hooks has removed the hooks. Where behaviour depends
> on what core does *around* a call, model it.

**A planted fault in a harmless position proves nothing.** The first draft of
`private-slug-fault-check.sh` planted "clear the old slugs on the way back to
public" *before* the `wp_update_post()` that restores the address. Core re-adds
the row a moment later, so the end state was correct and the check reported a
fault it could not see. Only the placement *after* the update is destructive.

**An instruction reported complete without touching the file. Twice.** The RSVP
sensitivity banner and the series removal paragraph were each reported removed
and were each still on screen. Both times `git log -S"<exact string>"` returned
exactly one commit: the one that added it. Nothing had overridden it or
duplicated it. The edit simply never happened.

> **"It did not take" means run `git log -S` first**, before touching anything.
> One command settles in seconds what the alternative explanations (wrong
> element, second copy, specificity) cost hours to chase. Then say plainly
> which it was.

**Containment removing an intrinsic floor.** `container-type: inline-size`
applies *size* containment, which computes the element's intrinsic size as if it
had no contents. Harmless when the width comes from the parent, which is the
only case anyone tests. Fatal when the parent sizes from its contents: a table
cell, a float, an inline-block, a flex item. On the live page `.uc-sidebar` was
in a table cell; its min-content fell from ~150px to 30px; every column
collapsed. The CSS was correct and the deployed stylesheet was byte-identical to
the repo. **The element it was measuring had been erased.**

The comment above it read "SAFE, BECAUSE OF WHAT IS NOT IN HERE" and reasoned
carefully and correctly about absolutely positioned descendants. All true. None
of it about size.

> **One mechanism verified is not "safe".** When a property brings several
> behaviors, the checklist is the behaviors, not the one that came to mind.
> Write down which you checked, so the unchecked ones read as a gap rather than
> as covered. Corollary: do not publish a number derived by arithmetic. Measure
> it and commit the probe.

**Two correct comparisons that together hid every panel.** `showView()` had
`list.hidden = (view !== 'list')` beside `cal.hidden = (view !== 'calendar')`.
Correct for the two views that existed. For `'combined'`, both are true, and the
mode rendered its search box, filter bar and count and then nothing. The server
markup was right the whole time, which is why the panels were in the inspector.

> **Default to keep, not hide.** A mode added later should render too much,
> which gets reported, rather than nothing, which reads as the plugin being
> broken. And the on-site twin (`calendar.js`) and the embed (`embed.js`) are
> documented twins that drift silently: the same trap sat unreached in the
> other runtime.

**A form inside a form, and every save cancelled the event.** The cancel card was
rendered into the event editor's side column, which is echoed inside the event
`<form>`. HTML forbids nested forms, so every parser drops the inner start tag
and keeps its children: the cancel card's `uc_action`, its nonce and its
notify-registrants checkbox joined the event form. PHP takes the last value of a
repeated key, so Save posted `cancel_event` with the matching cancel nonce, the
security check passed because both halves came from the same card, and
`save_event` never ran, so the edit was discarded as well. **It shipped in
3.36.0, the release that added the cancel card, and was found live on 3.40.0,
five releases later.** The card was never once outside the form: the call site
sits between the event form's open and close tag in every one of 3.36.0, 3.37.0,
3.38.0, 3.39.0 and 3.40.0. Earlier notes in this file and in the changelog put
the start at 3.38.0 or 3.39.0, which was read off the releases where it happened
to be noticed rather than off the call site.

> **A renderer that emits a form cannot be composed into one.** The rule is
> structural and is now checked structurally: no renderer opening a form is
> called between another form's open and close, and no form is given two actions
> or two nonces. A second `uc_action` is destructive whether or not it brings a
> form with it, which is why the count is asserted and not only the nesting.

**Three releases of tests asserting something other than the behaviour that
mattered.** This is the finding, not a footnote on the one above.

- 3.31.x asserted both panels were built and neither was marked hidden, then
  counted the visible cards. Both passed while the mode showed nothing, and then
  while every card was a 40px strip with no title and no button.
- 3.39.0 asserted `data-uc-scope-confirm` was gone and that `dismiss()` compared
  paths. Both were true and stayed true, while the scope dialog went on
  appearing after every save, because the second ask was never on the save
  button: it was on the page a save redirects to.
- 3.40.0's assertions about the event editor were greps over the source, and the
  destructive fault above does not exist in the source. It exists only once a
  parser has read it.
- **3.38.0 to 3.64.0 is the purest instance, and it went unnoticed for 26
  releases.** `render_event_form()` called the series prefill card 102 lines
  above the two lines assigning the variables it takes, so both were undefined,
  PHP passed `null`, and the card's `empty()` guard returned. The New Event
  screen has never had a series control. **Every static question returns the
  right answer**: the call exists, the method exists, the arity matches, the
  file parses, the callable audit is clean. Nothing was wrong except the ORDER
  of two statements, which is not a property any of those questions can see, and
  the only symptom was two warnings suppressed on any production
  `display_errors` setting. `.claude/series-control-test.php` renders the form
  and parses what came back.
- **3.38.0 to 3.64.1, the same card, the other half of the same shape.** The
  prefill's Image option wrote the series picture into two hidden fields and
  stopped. Every assertion available about the WRITE was true the whole time:
  the values were set, on the right elements, from the right keys. What a person
  saw was an empty preview and a tag still reading "Placeholder", under a message
  saying six things had been filled in. **"The value was written" is not "the
  screen shows it"**, and a control that reports success while its result is
  invisible is worse than one that fails. `.claude/prefill-image-test.js` runs
  the real `initSeriesPrefill()` over a document and then reads the preview, the
  Remove button and the tag.

> The common shape is not "the tests were too weak". Each asserted a PROPERTY OF
> THE CODE believed to imply the outcome, and never the outcome. **Write the
> assertion in the words of the outcome first, then find a way to decide it.**
> "A save leaves the event published and uncancelled" is decided by working out
> what the browser posts and what PHP makes of it, which is what
> `.claude/save-outcome-test.php` does. Writing that assertion is also what
> found a second, unrelated defect: `save_mode` fell back to `draft`, so a save
> naming no mode unpublished a published event, and Save Draft was the first
> submit button in the form, which is the one a browser presses on Enter.

**A single-select control over a taxonomy that accepts many.** Categories had it
until 3.8.0, organizers until 3.40.0: the picker read index zero and the save
wrote an array of that one back through `wp_set_object_terms()`, whose default
REPLACES. Venue and series have the same shape and are the deliberate case:
both are one per event, both read `reset( get_the_terms() )` and both write a
single id through `set_for_event()`, so if an event did hold two, a save would
keep the alphabetically first and drop the other silently.

> **The limit is only as real as the narrowest route into the data**, and the
> routes differ. `uc_venue` registers `show_ui => false`, so there is no screen
> and nothing can give an event two. `uc_series` registers `show_ui => true`
> with `meta_box_cb => false`, which closes the CLASSIC metabox only; `uc_event`
> is `show_in_rest => true` and supports the editor, and the block editor picks
> its taxonomy panels off `show_ui`. So series may be reachable the same way
> organizers were. **Unverified, and it needs a person to open the WordPress post
> editor and look**, which is why nothing was re-registered on the inference:
> `show_in_rest` and `show_ui` on a public taxonomy also decide its archive and
> what satellites read.

> **Ask whether the LIMIT is in the data model or only in the control.** When it
> is only in the control, the loss is silent, unlogged and unrecoverable.

**A checker that was blind for a reason that had nothing to do with the code.**
Until 3.49.0 the linter, the callable audit and the date sweep all walked
`.build-stage/`, the byte-identical copy of the plugin that `build-zip.sh`
leaves behind. Every finding was counted twice, and because the copy sits at a
path none of the exemptions name, the date sweep reported the date formatter for
being the date formatter. **It surfaced only when the suite ran AFTER a build**,
which is not the usual order, so it survived unnoticed through several releases
and made one clean run clean by luck of sequencing rather than by being correct.

> **Ask what the checker is looking AT, not only what it is looking for.** A
> tool that reads the tree will read whatever is in the tree, including things
> the build put there. Every tree-walking check now skips `.build-stage/` the
> same way it skips `Old Calendar Files/`.

**A new checker gets a case for the shape you have NOT already seen.** The type
scale sweep matched `(\d+)px`, so it could not see `13.5px`, which is precisely
the value the ladder exists to forbid. Its self-test passed because every case
in it was a whole number, and six real violations sat behind it. The same shape
appeared again in 3.49.1: a rendering test modelled a TinyMCE textarea as
holding the typed value, which is the one thing such a textarea never does, and
the planted fault went uncaught until the fixture was corrected.

> **A self-test built only from the case that prompted the checker proves the
> checker handles that case.** Give it the input you think cannot happen.

**A rule that has never run is not a rule that works.** 3.66.0 floated a section
legend and 3.68.0 was the first release in which that float did anything,
because until then every fieldset carrying it was also a flex container and
float computes to `none` on a flex item. The comment beside it said the float
was load-bearing and must not be tidied away; it had been load-bearing on
nothing for two releases, and the moment it took load it broke both forms.

> **Removing a class to escape one of its rules removes all of them.** The
> reset being escaped was `border`, `padding` and `margin-inline`. The class was
> also the section's `display`, and nothing about those three properties would
> have led anybody to look for it. **Before taking a class off an element, list
> what else that class declares**, the same way a container audit lists what
> else a property brings.

### A block can land in the wrong function and look right in review (3.73.0, found 3.79.0)

The bulk category control shipped with tick boxes on the events list, and there
were no tick boxes on the events list. The `<td class="uc-col-tick">` had been
inserted into `upcoming_overview()`, the read-only dashboard table, instead of
`events_table()` immediately below it, and it stayed there for six releases.

**Both halves of one fault, in two different tables.** The events list got a
`<th>` with no `<td>` under it in any row: a header cell wider than its body,
and a bulk panel with nothing to select. The dashboard got a `<td>` with no
`<th>` over it, guarded by a `$plain` that does not exist in that method, and
associated by `form=` with a form that is not on that screen.

**IT READ CORRECTLY IN REVIEW BECAUSE THE TWO LOOPS ARE THE SAME THERE.** Both
open `$date = get_post_meta(...)`, then `$st = get_post_status( $id ); ?>`, then
`<tr>`. There is nothing at the insertion point that says which table you are
in. The diff hunk applied cleanly and the surrounding lines were the expected
ones.

**And every check in use proved something true and irrelevant.** The file
parsed. The string `data-uc-tick-one` was present. `git log -S` would have
confirmed the edit landed. All three are the answer to "was it written", and the
question was "is it written in the same table as its header".

> **The check is a RELATIONSHIP, not a presence.** `.claude/bulk-ticks-test.php`
> slices the file into methods and asserts per renderer that a tick header and a
> tick cell appear together, on the same guard, and that `upcoming_overview()`
> has neither. It also resolves every `form="X"` against the form ids the file
> actually opens, because a control associated with a form that is not on the
> page fails **silently**: the box ticks, and the press carries nothing.
>
> **It failed twice on its own first runs, both times by not reading the file.**
> `<form[^>]*id="..."` stops at the `>` inside `action="<?php echo esc_url( ... ); ?>"`,
> so it found one form id in a file that opens sixty-one. Then stripping lines
> beginning `//` took the `?>` off the second line of a two-line `<?php //`
> comment, unterminating the block and swallowing the next form whole, which it
> duly reported as a broken association. Both are the same trap as 3.20.0: **make
> a new checker fail on purpose before believing it passes**, and give it a floor
> it must clear so that finding nothing is a failure rather than a pass.

### A comment can assert a declaration that is not there (3.45.0, found 3.80.0)

`.uc-view-panels-combined` carried a paragraph explaining that `overflow: hidden`
on the container is what lets the panels' square corners sit inside a rounded
border. The rule did not declare it, for thirty-five releases, and nothing was
ever reported. The prose is exactly what would have stopped the next person
checking.

> **IT WAS ADDED AND TAKEN STRAIGHT BACK OUT, which is the more useful half.**
> `.claude/embed-modes-test.php` refuses any `max-height` or `overflow` on that
> container, a guard written after 3.31.2 where a height cap above
> `overflow: hidden` cards squashed them to 40px strips. The guard is broader
> than that one case on purpose. **The fix was not to widen it**: the property
> was not needed, which thirty-five quiet releases had already demonstrated, and
> the one element that reaches a rounded corner carries the matching radius
> itself. Widening a guard to admit something nothing needs is how the defect it
> was written for comes back.

**The same release found the other half of the pair.** `.uc-month-nav-side
.uc-month-nav` declared a 1px border and an 8px radius on the month arrows;
twenty lines later `.uc-calendar .uc-month-nav` declared `border: 0`. Both are
(0,2,0), so source order decided it. **The obvious remedy for "the arrows blend
in" was to darken the border, and that border never rendered.**

> **COMPUTE THE CASCADE, DO NOT READ IT.** `.claude/month-nav-cascade.php`
> resolves every rule in `calendar.css` that can reach one element, orders them
> the way a browser does, and prints the winner and the losers per property. It
> also prints what specificity a host rule would need to beat ours, which is the
> question the embed actually asks.
>
> **Its own first gate was wrong and that is worth keeping.** It failed on
> `.uc-calendar *`, the scoped box-sizing reset, which is (0,1,0) and is MEANT
> to be: it declares nothing about a control's appearance. A check that fails on
> a correct file trains somebody to widen it until it goes green.

**And a control's boundary is not a card's.** `--uc-border-strong` in
`calendar.css` is `#D7DBE1`, 1.39:1 on white, which is right for a card edge
because that is decoration. It was also being used for controls, where 3:1
applies. `--uc-control-edge` is `#8C8D8E` at 3.33:1, the same value portal.css
has used since 3.64.0. **The month arrows use it; these do not yet**, and the
list is here rather than left to a search: the filter bar's search field and
dropdown, the group pills, the groups disclosure, the RSVP modal's cancel and
its secondary add-to-calendar button, and the month tabs under the sidebar.

### Balance is not validity in JavaScript either (3.77.0, found 3.81.0)

3.77.0 closed `initFaqSetPeek()` **after** `initRequestPrefill()` instead of
before it, so the whole of the second function lived inside the first. One
closing brace in the wrong place, and the braces still balanced.

```
    select.addEventListener('change', build);
    build();
}
}                       <-- this one closed initFaqSetPeek
function initRequestSeriesImage() {
```

**`run('requestPrefill', initRequestPrefill)` evaluates the name before calling
`run()`**, so the ReferenceError landed in the caller rather than inside
`run()`'s try/catch, which is the thing `run()` exists to provide. It killed the
rest of the startup list: `requestPrefill`, `calendarTick` and `tickPickers`.
Every tick picker on every screen was dead for four releases, and so was the
feature 3.77.0 had just shipped.

**Three gates passed it.** `node --check` proves a file parses, and it did.
`.claude/js-scope-test.js` passed through a hole it names in its own header: *"a
function declared inside a NESTED function is treated as belonging to its whole
top-level scope... it can MISS a genuine fault"*. And the release was verified by
reading the source, which is exactly what the 3.79.0 entry above says not to do.

> **`.claude/js-nesting.js` is the answer, and writing it taught the same lesson
> twice.** The first version stripped comments and strings with regexes and
> reported nesting depths of fifteen, which would have sent somebody hunting
> fourteen missing braces. **Line comments, block comments, strings, template
> literals and regex literals all carry braces that are not code**, and regex
> literals are the hard one: `/\d{4}/` has braces and `a / b` does not, and
> telling them apart needs the previous significant token. It self-tests against
> all five before it asserts anything, then requires every initialiser the
> startup list names to be declared at depth 1.

### There is a browser on this machine (3.81.0)

`PROJECT.md` has said for several releases that a stylesheet is not rendered
output and that a fault living only in geometry reaches Mark's screen with the
suite green. That was true and it was also incomplete: **Chrome is installed and
headless works**, and two of this release's four reports were settled by
measuring rather than reasoning.

```
chrome --headless --disable-gpu --window-size=1400,1200 \
       --virtual-time-budget=8000 --dump-dom file:///...
```

- **`.claude/sidebar-enclosure.php`** builds the combined view at three widths
  and reports real boxes: is anything outside the card, how far the painted edge
  is from the last row, what radius each corner computes to. It is what showed
  that 3.80.0's fix had landed and was aimed at the wrong end of the column.
- **`.claude/media-ticks-live.php`** loads the real `portal.js` against the
  Images screen's markup, clicks labels the way a person does, and reads the
  button. It is what turned "Tag 0 images" from a guess into
  `initTickPickers did not reach it`, and then into the exact ReferenceError.

**Use it before theorising about anything visual or anything the script does.**
Two releases were spent reasoning about a card that could have been measured in
ten minutes.

### A decision made for one shape is made for both (3.45.0, found 3.82.0)

The combined mode forced its view toggle off, with a good reason written beside
it: both views are on screen, so the toggle has nothing to switch. That is true
**side by side**, which was the only shape anybody had looked at.

**The panels stack on flex-wrap**, at a width the browser decides, and PHP
cannot see it. So the decision was made once, for both shapes, and in the
stacked one it removed the only route to the list view from a screen that is a
month grid tall enough to need it.

> **THE TEST OF A MODE-WIDE `if` IS WHETHER ITS REASON SURVIVES EVERY SHAPE THE
> MODE HAS.** This one, the pagination beside it, and 3.81.0's `max-width` on
> the sidebar panel were all written for the side-by-side case and all three
> applied unconditionally. A CSS layout with two shapes and a PHP branch that
> knows about one of them is the general form of this fault, and the calendar
> now has three instances of it in the same mode.

### A picture outside the calendar folder is not used

**Mark's rule, in full, from 3.83.0.** Every event picture on this calendar is
one somebody curated into `uploads/calendar/`, at the shape a 16:9 card wants,
reachable from the Images screen and taggable to a series. Anything else is
ignored and the chain falls through to the series picture and then the category
placeholder.

**ENFORCED AT RESOLUTION, NOT BY CLEARING WHAT IS STORED.** The 2026-09-03
import carried 270 events' pictures across from The Events Calendar. Clearing
270 stored references is the expensive way to enforce a rule that belongs in one
place, and the next import would undo it. Enforced at the read, those references
stop mattering: nothing is deleted, no file is touched, and whatever any future
import writes is not used either.

**`sfaf_event_own_image_url()` IS THE RULE AND EVERY SURFACE READS IT.** The
month tile, the hover preview, the event page, the sidebar row, the list card,
the embed payload and the sharing tags.

> **AND SO DOES THE EDITOR**, which is what makes read-time enforcement safe
> here. The completeness prompt, the per-event override flag and the picker's
> current selection all ask `sfaf_event_has_own_image()`. Without that the
> editor would say an event has a picture of its own while the calendar drew the
> series one, which is the two-answers-to-one-question fault this project keeps
> meeting, and it was the reason for rejecting read-time enforcement in 3.82.0.

**IT IS ABOUT ATTACHMENTS AND ABOUT LOCAL PATHS NAMING ONE.** An attachment is
in the folder or it is not. `_uc_image_url` is asked whether it points into the
folder. **An address on another site is left alone**: that is `_uc_external_image`
territory, the rung a fetch maintains, and taking it away would blank every
imported campaign, which is a different decision nobody has made.

**`sfaf_event_thumbnail()` MUST NOT SHORT-CIRCUIT ON THE FEATURED IMAGE.** It
did until 3.83.0, returning it before the chain was consulted, which was the one
path the rule could not reach. `.claude/image-folder-rule-test.php` plants that
fault and requires the build to fail.

### The picture an event shows is resolved in one place, and set in two

`sfaf_event_image_url()` is the whole chain: the event's own featured image, then
`_uc_image_url`, then `_uc_external_image`, then the series' picture, then
`_uc_remote_image_url`. Every display surface goes through it, which was checked
rather than assumed in 3.82.0: the month tile's hover preview, the sidebar row,
the list card, the event page, the sharing tags and the sync payload all call it,
and the four places that read `has_post_thumbnail()` directly are editor-side
"does this event have one of its own" questions, not display.

**SO CLEARING AN EVENT'S PICTURE CLEARS IT EVERYWHERE, AND RUNG ONE IS TWO
VALUES.** `_thumbnail_id` and `_uc_image_url` are both rung one, and clearing
either alone leaves the other supplying the picture.

**AND AN OCCURRENCE INHERITS BOTH.** `SFAF_Recurrence` copies `_uc_image_url` in
`$copied_meta` and calls `set_post_thumbnail()` from the seed, so one imported
pattern's picture reaches every date it generated.

### A panel moved into a container it was never styled for (3.82.0, found 3.83.0)

3.82.0 gave the combined mode a list panel so its toggle had somewhere to go.
Nothing gave that panel a size inside the combined wrapper, because until then
the class had never been in it. Measured at 700px:

```
uc-panel-sidebar   shown   698x964  left  21
uc-panel-list      shown     0x964  left 719   flex 0 1 auto  min-width auto
a card              26x268
```

A zero-width flex item with its cards overflowing at their min-content width,
which on screen is one letter per line.

> **WHEN AN ELEMENT MOVES INTO A NEW PARENT, LIST WHAT THAT PARENT GIVES ITS
> OTHER CHILDREN.** Both siblings carried `flex` and `min-width: 0`; the arrival
> carried neither and took the defaults, and `min-width: auto` on a flex item is
> not a floor anybody chose. The same shape as the container-audit rule in
> `DESIGN.md`, one level up: a property of BEING a child of this container, not
> of being remembered.

**And the same release left the query behind.** The mode asked its renderer for
the total with no cards, which was right while it had no list to draw, so the
new panel rendered its empty state beside a sidebar listing the same events.
**A feature added to a mode has to be walked against every decision that mode
already made**, and there were three: the toggle, the pagination and the render
mode.

A shared thread runs through most of these: **a verified change is not a
verified outcome.** `git log -S` answers "was my edit applied"; it does not
answer "why does this still look like that". Start from the element as rendered
and hunt the effect by any mechanism. **And a stylesheet is not rendered
output:** every check on these two forms reads markup or rules, so a fault that
lives only in geometry reaches Mark's screen with the suite green.

---

## 8. Agreed, not built

Decisions settled in conversation that have no code yet. They live here because
a chat ends and this file does not. Move an entry into the body of this document
when it ships, and delete it here.

### Two things are called the calendar folder (3.86.0, half closed in 3.90.0)

> **NEW UPLOADS ARE NOW FILED IN BOTH**, by discovering the library's taxonomy
> rather than naming it. See the body of this document. **What is still open is
> the BACKLOG**: pictures already on disk and absent from the library folder are
> not retrofitted, because that is a bulk write to somebody else's taxonomy and
> a decision rather than code.
>
> **The two remaining options are unchanged and both are Mark's**: configure WP
> Media Folder to move files on disk when they are filed, or file the strays
> into the library folder in bulk. **Moving files and rewriting stored paths
> stays refused** while anything reads those paths.

**THE REST OF THIS ENTRY IS THE ORIGINAL INVESTIGATION AND IS STILL TRUE.**

**WHAT THE PLUGIN READS: the physical directory.** `SFAF_Media_Folder::holds()`
reads `_wp_attached_file`, core's own record of where the file actually is, and
checks the path starts with `calendar/`, anchored. `SFAF_Media::pictures()`, the
Images screen's query, uses the same anchored REGEXP over the same meta. **Those
two agree with each other**, and always have.

**WHAT THE MEDIA LIBRARY SHOWS: a WP Media Folder taxonomy term.** WordPress
core has no folder UI; that one is the plugin's. Its folders are an ASSIGNMENT
on the attachment, which can be set without the file moving, and a file can be
moved without the assignment following.

> **That is the entire disagreement.** A file physically in `uploads/calendar/`
> but not assigned to the plugin's Calendar folder is visible to the calendar and
> absent from the library view. A file assigned to that folder but physically in
> `uploads/2026/09/` is the other way round. Both states read as "the folder is
> wrong" and neither is a fault in either piece of software.

**REMOVE IS NOT GATED ON OWNERSHIP**, which is worth stating because it looks as
though it is. It is a soft marker, `_uc_media_removed`, offered on any row the
Images screen lists, withheld only when the picture is IN USE. An image Mark
filed into the folder himself has no Remove button because **it is not on that
screen at all**: the screen lists by physical path, so a file the library calls
"Calendar" while it sits in a date directory is not listed, and a row that does
not exist has no button.

**WHICH SHOULD WIN: the physical path, and that is already the recorded
decision.** `PROJECT.md` has said since the picker was built that this filters on
`_wp_attached_file` and never on WP Media Folder's API, so the rule survives that
plugin being removed and depends on metadata core maintains. Going through its
taxonomy would make a third-party plugin load-bearing in the event editor.

**WHAT IT WOULD TAKE TO AGREE.** Either configure WP Media Folder to move files
on disk when they are filed, so an assignment implies a path, or move the strays
and rewrite `_wp_attached_file`, the GUID and every stored `_uc_image_url`.
**A reconciliation REPORT should come before either**, listing the mismatches in
both directions, because until that exists nobody knows how many files are in
which state.

### A cached embed.js is named, not prevented (3.89.0, still open)

**PART OF THIS IS NOW BUILT** and is described in the body: the payload carries
the plugin version, `embed.js` carries the version it shipped as, and the
mismatch is named in the console. That makes a stale script DIAGNOSABLE.

**What is still open is preventing it**, and the obvious remedy is banned.
`?ver=` on the script URL would pin every snippet already pasted rather than
bust its cache, which is the 2.10.1 defect. The options that remain all have a
cost somebody has to choose:

- **Serve `embed.js` through a PHP endpoint** so the plugin controls its cache
  headers. New URL, so every pasted snippet needs re-copying, and it puts a
  static file behind WordPress on every embed page view.
- **Set the headers at the web server**, which is a `.htaccess` or an nginx
  rule outside this plugin and outside its ability to verify.
- **Accept it**, now that it announces itself, and hard refresh when a release
  does not appear.

**The third is the current state and is defensible**, because the failure is no
longer silent. Moving off it needs Mark rather than code.

### The list view, rebuilt on a horizontal card (3.82.0)

**PROPOSED, NOT BUILT.** Mark wants the list view on the shape at
`wpeventful.com/events-list/`: a horizontal card per event, picture on the left
in 16:9 with a zoom on hover, title, one meta line of venue, date and time, and
a description excerpt with Read More. No social icons: an event is shared from
its own page.

**THE CALENDAR VIEW IS NOT IN SCOPE.** Some days carry nine events and the grid
gets very tall; that is being reviewed separately.

**INFINITE SCROLL OVER 287 EVENTS NEEDS A SHAPE, and "load forever" is not one.**
A scroll with no sense of how far through somebody is, no way to reach the end,
and a back button that returns them to the top is worse than the pagination it
replaces. The proposal:

- **The count stays at the top**, which the list already renders: "287 events
  coming up". It is the only thing that tells somebody how big this is before
  they start.
- **Auto-load twice, then a button.** Three pages arrive by scrolling and the
  fourth asks. That covers browsing, which is the case infinite scroll is for,
  and stops a visitor who is looking for one thing in December from falling
  through 280 cards. The button says what is left: "Show more (58 of 287
  shown)".
- **The URL carries the page**, so the back button returns somebody to where
  they were rather than to the top, and a link they send opens on the same set.

**IT MUST WORK WITH SCRIPT OFF, like everything else here.** The shape is the one
this calendar already uses for Load More: **the server renders real pagination
and the script layers scrolling on top of it**. The page links are in the markup
and work on their own; `initLoadMore()` intercepts them, fetches the next page
through the same endpoint and appends. With the script gone the list is paged,
which is complete and slower, and nothing is hidden behind a control that cannot
run.

**THE QUERY DOES NOT CHANGE.** The filters, the scope clamp and the server-side
querying stay exactly as they are: every narrowing goes through the query and
nothing is ever done by hiding rows already downloaded.

### The imported events' pictures, and the calendar-folder-only rule (3.82.0)

**INVESTIGATED AND REPORTED, NOTHING CLEARED.** The 2026-09-03 import carried
each event's featured image across from The Events Calendar. That is what an
import normally does, it was not asked for, and it happened without a decision.
The result is pictures outside the calendar folder, in the wrong shape for a 16:9
card, untagged, invisible to the picker and unreachable from the Images screen.

**MARK'S DECISION: only pictures inside the calendar folder are used.** Roxane
is producing the event pictures and those are the ones going on.

**WHICH RUNG SUPPLIES THEM.** Rung one, and both halves of it. The importer
called `attachment_url_to_postid()` and wrote `_thumbnail_id` when it resolved,
`_uc_image_url` when it did not. The sized URL in the report,
`Damn-Daddy-768x512.jpg` against a plan that carries `Damn-Daddy.jpg`, is
WordPress rendering the `large` size of a real featured image, so that one is
`_thumbnail_id`. **Both have to be cleared**; either alone leaves the other
supplying the picture. Nothing was written to rung two: `_uc_external_image` is
the fetch adapters' and the TEC import never touched it.

**HOW TO TELL AN IMPORTED PICTURE FROM ONE MARK CHOSE.** By the file's folder,
and it is good but not perfect. caladmin's picker only ever offers
`uploads/calendar/`, so a featured image outside it was not chosen there. The
gaps, stated rather than glossed: wp-admin's own post editor can set any
attachment, and the caladmin image control has a URL field that writes
`_uc_image_url` by hand. **So the clear must be reported and confirmed before it
runs, never applied blind.**

**WHAT HAPPENS AFTER THE CLEAR IS THE OUTCOME WANTED.** With rung one empty, the
chain falls to the series' picture, which from 3.81.0 resolves to a picture
TAGGED to that series when none is set. That is exactly Roxane's calendar-folder
pictures, so the clear and the end state line up with no third step.

**SUPERSEDED IN 3.83.0: THE RULE IS ENFORCED AT RESOLUTION.** Nothing is
cleared and nothing needs to be. See "A picture outside the calendar folder is
not used" in section 1. The importer guard below stays, because a picture it
never writes is one fewer stored value that means nothing.

**ENFORCEMENT IS ALSO AT THE WRITE, IN THE IMPORTER (3.82.0).** It is the only thing
that has ever created these, and it now skips a picture outside the folder and
reports every one it passed over. So another run of it cannot undo the clear.

**NOT AT THE READ**, and that is deliberate: a filter in
`sfaf_event_image_url()` would leave the wrong value in the database silently
overridden, so the event editor would show one picture and the calendar another,
which is the two-answers-to-one-question fault this project keeps meeting.

**WHAT IS THEREFORE STILL OPEN.** Two routes can still put an out-of-folder
picture on an event, and neither is an import: wp-admin's own post editor, and
the URL field on caladmin's image control. Both are a person choosing something,
which is the case the rule is not meant to override silently. `clear-outside-folder.php`
run in its report mode is the standing check for them.

**THE CLEAR ITSELF IS `.claude/import/clear-outside-folder.php`.** Report mode by
default, undoable, and it matches each picture against `plan.php` so "the import
set this" is a fact rather than an inference. Anything outside the folder that
is NOT in the plan is listed and left alone, because the folder test on its own
cannot tell an import from a person.

### Whether a language belongs as a category, still open

**REPORTED IN 3.75.0, NOT ACTED ON**, because it is a decision about what the
calendar's categories ARE rather than a defect.

**SIX OF THE SEVEN DESCRIBE WHAT AN EVENT IS.** Fundraising, Health Services,
Program Groups, Support Groups, Volunteer and one more say what kind of thing is
happening. **Español says what language it happens in**, which is a different
question about the same event, and an event can be both.

**AND THE FIRST CATEGORY ALPHABETICALLY SUPPLIES THE COLOUR AND THE ICON.**
`sfaf_event_categories()` sorts with `strcasecmp` and
`sfaf_event_primary_category()` takes `[0]`. **Español sorts before every other
category this calendar has**, so a Spanish-language support group is drawn in
Español's colour, with Español's icon, on its card, its placeholder, its month
tile and its chip. It is a support group everywhere except in how it looks.

**THAT IS THE RULE WORKING, NOT FAILING.** One category has to win or a card has
two colours, alphabetical is the only order that does not require somebody to
maintain a ranking, and 3.40.0 settled deliberately that there is no "primary
organizer" field to keep in step. Nothing here is a bug to fix.

**THE THREE ANSWERS, AND THE COST OF EACH.**

| | What it means | Cost |
|---|---|---|
| **Leave it** | Spanish-language events are drawn as Español | The kind of event is invisible on every surface that shows one category |
| **A field, not a category** | Language becomes its own meta, with its own badge | A build, and a decision about what the badge says and where |
| **Rename it so it sorts late** | "Programs in Spanish", say | A rename, and it still wins wherever it pairs with something later still |

**What cannot be recommended from here is which one.** It depends on whether
somebody browsing wants to filter by language, which is a question about the
people using the calendar rather than about the code. The filter bar already
offers categories, so today Español IS a language filter, and that is the thing
the first option quietly keeps.

### The community form's age restriction options, awaiting Mark

**Raised in 3.74.0 with an instruction to report the set before renaming any of
it**, because one of the five opens a required field and two were named in
isolation. Nothing was changed; this is what is there.

| Value stored | Label on the control | What it does |
|---|---|---|
| `''` | Not saying | The default, and the first entry. Stores nothing. `choice_phrase()` returns an empty string, so **the event page shows no age line at all**. |
| `all` | All ages | Prints **All ages** on the event page. |
| `18` | 18+ | Prints **18+**. |
| `21` | 21+ | Prints **21+**. |
| `other` | Something else | **Reveals a companion box and that box is REQUIRED.** Whatever is typed prints verbatim, so this is the escape for "trans and non-binary people 18 and over" and anything else a closed list cannot hold. |

**Two of the five were named as reading badly.** "Not saying" reads as terse for
a default nobody chose, and "Something else" says nothing about what happens
next, which on the one entry that opens a required field is the entry that most
needs to.

**What the replacement has to keep.** The empty value must stay first and stay
the default, because a required-looking list with a real answer at the top
pre-selects that answer, and this control is not required. And whatever `other`
becomes has to signal that a box follows, since somebody choosing it and not
filling the box is refused.

**A set to take with the pair in front of you**, offered rather than applied:

```
''       No age restriction given
all      All ages
18       18+
21       21+
other    Something else, and I will say what
```

Renaming these is one array, `SFAF_Submit::age_options()`, read by the control
and the validator alike, so the stored values do not move and no event changes.
It is a copy decision and not a build.

### Naming the community form's two email fields, still open

**Both do unrelated jobs and neither label says which.** This was raised in
3.66.0 and half of what it blocked has since shipped: five addresses on the
About you field, in 3.67.0, and the pending row showing what was submitted.
**What is still undecided is what the two fields are CALLED.**

| Field | Meta | What it actually does |
|---|---|---|
| **About you → Your email** | `_uc_request_email`, plus `_uc_submitted_notify_emails` for the rest | The submitter's identity, and now up to five addresses. `SFAF_Submissions::submitter()` reads the first, the confirmation goes to it, and these are **the only addresses that reach the notification list**, at approval, when a manager ticks the prompt. |
| **Contact for the event → Email** | `_uc_contact_email` | Printed on the **public event page** through `sfaf_event_public_contact()`. It reaches no list and no message. |

3.67.0 ships **"Your email, and anybody else who should get RSVPs"** on the
first, which says what the field does and is longer than a label wants to be.
The second is still **"Email"** under a legend that carries the whole of its
meaning. Renaming either is a decision rather than a build, and it wants taking
with the pair in front of you rather than one at a time.

### Announcing new dates to followers, agreed 2026-08-24

**Part 2 of the work 3.53.0 began.** 3.53.0 established who the followers are
and how somebody becomes one; **nothing yet sends them anything**, and
`SFAF_Follow::active_followers()` is the audience it will read. That method is
uncalled on purpose and is not dead code.

What was agreed: an **organizer's screen** listing the events in a series that
have been added and not yet announced, with an **envelope state** per row,
**send** and **dismiss**, and an email **naming the dates that were added**.

**It is a screen with a person pressing a button, not a hook on creation.**
Nothing fires today when an occurrence is generated or a date is added to a
series, and the six custom actions this plugin defines are all on the RSVP or
opt-in path. That absence is deliberate rather than an oversight to be
corrected: `SFAF_Recurrence::register()` hooks nothing, on purpose, because
generating on `save_post` is what once made saving one event rewrite a term's
worth of them. A schedule is usually built in one sitting — extend a group, add
two one-offs, rename a date — and a trigger on creation would send one email per
occurrence in the middle of somebody's editing. The unit people care about is
"here are the new dates", which only a person can say is finished.

**Dismiss exists because of that same editing session.** A date created and then
removed, or created only to correct a typo, must be closable without mail.

### EveryAction event import, agreed 2026-08-17

**Why not an API.** EveryAction API keys **cannot be scoped to events only**.
Any key that returns event listings also exposes client and attendee records
from the **Aging Services** program. The API route was rejected on **privacy
grounds, not technical ones**. It would work, and the exposure is unacceptable.
Do not revisit this by finding a cleverer set of API calls; the constraint is
the key's scope.

> **THE SOURCE HAS MOVED TO A MangoApps TRACKERS ENDPOINT**, and what is being
> waited on from Val is now that endpoint plus a **sample response**. The shape
> below was agreed when it was going to be a file he wrote; the privacy
> reasoning above is unaffected and is the part worth keeping, because it is the
> half nobody can reconstruct later. **Do not build the adapter until the sample
> exists**: the GFMP spec has been wrong or silent four times and every one cost
> a release.

**The agreed mechanism**, as it stood when the file was the source.

- **Val**, who manages EveryAction, runs a **cron job on the hour** that writes a
  **JSON file of events** to the shared host.
- The plugin **fetches it over plain HTTPS at half past the hour**. No
  authentication layer, no API client. This has already been proven with an
  earlier PHP file, so the fetch path is known to work.
- Imported events land in the **pending queue**, exactly like GFMP and
  Eventbrite, and go through the same `owned_fields()` / `manager_fields()`
  contract in §3.

**"Half past" means the first visit after that.** WordPress cron does not fire on
a schedule on its own (§4), so the fetch happens on the first run at or after the
half hour. For hourly data that is fine, and no special handling is needed.

**Fields requested from Val:**

| Field | Why |
|---|---|
| A **stable unique ID per occurrence** | So a refetch updates rather than duplicates. Without it, every fetch creates a new event. |
| Title | |
| Start and end **date and time, with timezone** | |
| **The public signup URL for that specific occurrence** | Each date has its own. **This is the field that makes the integration worth building at all.** A generic program URL does not do the job. |
| Location name and address | |
| Description | |
| Image URL | |
| Public or internal | Maps to the private flag. |
| Capacity, if available | |
| **Something identifying the recurring group** an occurrence belongs to | So a weekly coffee social groups as one series rather than arriving as 52 unrelated events. |

**Open questions, both to be settled before the adapter is written:**

1. **A sample file with real events has been requested.** Do not build the
   adapter against a guessed shape. The GFMP spec has been wrong or silent four
   times (§3) and every one of those cost a release.
2. **Does Val's job write atomically** (temp name, then rename), so a fetch
   cannot catch a half-written file? A partial JSON read on the hour is a
   plausible and silent failure mode.

### A parameter nothing passes is a feature nothing has (3.73.0)

> **CONFIRMED ON 3.74.0, AFTER THREE RELEASES AS A DEDUCTION.** The reasoning
> below fitted every symptom and could not be tested, because each release that
> would have proved it went on by hand, which clears the cache. Mark pressed
> **Check for updates** on 3.73.0, it offered 3.74.0, and 3.74.0 installed in
> one click. The cache was the cause, the fix works, and the cycle runs end to
> end. It is recorded because the NEXT cache will want the same question asked
> of it, not because this one is still open.

**WHAT HAPPENED.** 3.72.0 was released correctly and resources.sfaf.org did not
offer it. The release was real, the tag right, the asset correctly named,
`asset_url()` accepted it, and `inject()` would have offered it. Every one of
those was checked and every one passed.

**`inject()` never saw any of it.** `SFAF_Updater::latest()` has taken a
`$force` argument since 3.70.0, and **nothing in the codebase ever passed
`true`**. Both call sites, `inject()` and `details()`, ask without it. So every
answer this plugin has given about whether an update exists came from a
twelve-hour cache that only an install could clear.

**THIS IS THE 3.64.1 SHAPE AGAIN, ONE LEVEL ALONG.** There, the series prefill
card was rendered by a method whose arguments were undefined at the call, so the
card never drew: the call existed, the method existed, the arity matched, the
file parsed. Here the escape hatch existed, was correct, and was never reached.

> **NEITHER BUILD GATE CAN SEE THIS AND NEITHER EVER WILL.** The linter proves a
> file PARSES. The callable audit proves what it CALLS exists and that the
> arity matches. **"Does anything ever pass this argument" is a third question**,
> and it is not a variant of either: an unused parameter is valid PHP with a
> matching arity at every call site. The only thing that finds it is running the
> feature and observing the outcome, which is what `.claude/updater-test.php`
> does.

**AND THE TOOLING TOLD THE OPERATOR THE OPPOSITE.** `publish.sh` and
`build-zip.sh` both ended with "Sites see it within twelve hours, or at once
from Dashboard > Updates". WordPress's **Check again** calls
`wp_clean_update_cache()`, which deletes `update_core`, `update_plugins` and
`update_themes` and **does not touch `sfaf_updater_release`**. Our filter fires
and answers from our own cache. Pressing it could never work.

**THE LESSON, WHICH IS NOT "ADD A CONTROL".** A cache with no override is a
decision to be wrong for up to its TTL, and that is defensible right up to the
day somebody has just released and wants to install. **Every cache this plugin
adds from here needs the answer to "how does a person force it" written down at
the same time as the TTL**, and needs something that actually calls it.

**A SECOND ONE CAME OUT OF THE SAME READING.** `forget()` cleared the cache on
`'update' === $options['action']` and nothing else, and **uploading a zip on the
Plugins screen is `install`**. That is how every release before 3.70.0 reached
this site, so the route used most often was the route that left the cache stale.

**AND A THIRD, FROM THE CHECKER ITSELF.** The first version of
`updater-test.php` planted the loss of `$force` and did not catch it, because
`run_check()` deleted the cache before fetching, which makes an unforced
`latest()` fetch anyway. **The redundancy masked the regression.** The delete
came out so the force carries the whole job, and the plant then failed as it
should. Two of six plants were missed on the first run; both assertions were
rewritten until they failed on purpose. **A checker that has never failed is not
evidence**, which is the rule this project already had and which paid again here.

### A name that does not resolve is the same defect as a name nothing calls (3.73.0)

**THIS IS THE JAVASCRIPT TWIN OF THE DEAD `$force` PARAMETER ABOVE**, found
one release later, and the pair is why both are written down together.

**WHAT HAPPENED.** `portal.js` is FOUR top-level IIFEs, not one. 3.72.0
declared `ucDismissOnBackdrop()` inside the first and called it from the
third and the fourth, which are its SIBLINGS and cannot see into it. Both
calls threw `ReferenceError`.

**IT DID NOT LOOK LIKE AN ERROR, WHICH IS THE PART WORTH KEEPING.** Each
call site throws AFTER `preventDefault()` and AFTER the panel has been moved
into a `<dialog>` that has not been shown yet, and a `<dialog>` with no
`open` attribute is `display: none`. So the click was cancelled, the panel
left the page into an invisible box, and nothing appeared. **Three controls
did nothing at all:** Get a form link on the dashboard, and Approve and
Reject on the pending queue, which is the main action of the screen every
submission and all 287 imported drafts pass through.

> **ONLY ONE OF THE THREE WAS REPORTED.** A control that does nothing is
> reported when somebody needs it that week. Approve had been dead for a
> release and the queue was not being worked through at the time, so the
> more serious failure was the quieter one. **Do not treat one reported
> symptom as the extent of a shared cause**: the instruction that found
> this said so, and it was right.

**NEITHER BUILD GATE COULD SEE IT AND NEITHER EVER WILL.** `node --check`
proves a file PARSES. The callable audit is PHP. "Does this name resolve
from here" is a third question, and it is the same shape as "does anything
ever pass this argument": both are valid, parseable, correctly-spelled code
that does not work.

**`.claude/js-scope-test.js` IS THE CHECK.** It reads every top-level scope
and requires that every call to a name THE FILE ITSELF DECLARES is made from
a scope that can see the declaration. Only our own names, so a browser global
is never flagged and there is no allow-list to keep in step with the
platform. It is deliberately an over-approximation on nesting, so it can miss
a fault and cannot invent one.

**THE RULE, WHICH IS NOT "ADD A CHECK".** Anything two of those IIFEs share
lives at file scope, and the file header now says so at the top where
somebody adding a fifth will read it. A shared helper is the one kind of
thing that cannot be written where it is first needed.

### Three things investigated in 3.72.0, two of them now built

Each was asked for as an investigation. They are here rather than in the
hand-off because the finding is durable even though the decision is not taken.

> **TWO OF THESE SHIPPED IN 3.73.0** and are kept here rather than deleted,
> because what each says about the SHAPE of the problem is what a later
> reader needs and the build note is one line. The RSVP pair and the seed
> defect were built; the wording half of the duplicate control is still a
> proposal.

**1. REINSTATING A CANCELLED EVENT, AND WHAT IT DOES TO REGISTRATIONS.**

Reinstating deletes `_uc_cancelled`, `_uc_cancelled_visibility` and
`_uc_cancelled_at`, and from 3.72.0 the two pieces of prose with them. It touches
**nothing else**.

- **Registrations are untouched and still hold.** They live in their own table
  keyed by event id and nothing on this path writes to it. So a reinstated event
  carries every registration it had, they are live again because
  `is_cancelled()` is false, and its reminders resume.
- **Reinstating unchanged and reinstating on a new date are TWO actions today**,
  in either order, and **the order changes what is sent**. Reinstate first, then
  change the date, and the ordinary save's change-notice prompt offers to tell
  everybody the date moved. Change the date first, then reinstate, and nothing
  is offered at all, because the reinstate action has no prompt.
- **What is missing is a message for the case.** The change notice is written for
  a live event whose date moved. Nobody has been told "the thing you registered
  for is back on, on a date you did not choose", and the people it would go to
  are exactly the people who were told it was off. **BUILT IN 3.73.0.** It is one message, `reinstated`, it goes out whether
  or not the date moved, it carries a cancel link, and it is subject to the
  consent rule like every other manager-caused message. See PROJECT.md 4.

**2. "USE THIS EVENT'S DETAILS ON ANOTHER DATE" ON A MULTI-EVENT SERIES.**

It **does** take a specific row, so the data model is not the problem. The
problem is that **the row is chosen for you and never named.** The handler seeds
from the next upcoming event in the series, or the most recent if nothing is
upcoming. On a series holding several distinct events, PROP holds four, "this
event's details" silently means "whichever of the four happens next", which is a
coin toss to the manager.

> **FIXED IN 3.73.0: `SFAF_Series::seed_from_lists()` is the one rule and
> both callers ask it.** What it was:
>
> **THE TWO SEEDS WERE COMPUTED TWICE, BY DIFFERENT CODE.** The render-side
> seed prefers the next event **in the recurrence group** and falls back to the
> series; the handler's seed asks only the series. On a series with several
> groups those can disagree, so the placeholder shows event A's title and the
> button copies event B. **That is a defect and not a wording problem**, and it
> is the half worth fixing first.

The cheap remedy is to name the seed in the control, which the renderer already
knows: the title placeholder is already built from it. The fuller one is a
per-row "copy this date" action, which is a bigger change.

**3. THE TWO RSVP CONTROLS ARE TWO QUESTIONS, AND ARE ANDed.**

Not a duplicate. `_uc_rsvp_enabled` ("Accept RSVPs", under Capacity) is the data
gate: `SFAF_RSVP` refuses a registration when it is not `'1'`. `_uc_show_rsvp`
("RSVP", under Display) is one of five feature toggles deciding what the event
page draws. `sfaf_event_takes_rsvps()` is **both**.

**So the combination produces a state nothing warns about:** `rsvp_enabled` on
and `show_rsvp` off means an event that accepts registrations and shows no
button. Nothing is broken and nothing says anything.

**BUILT IN 3.73.0.** They stay two questions and the second is **dependent on
the first**, greyed while `rsvp_enabled` is off, which is exactly the treatment
`show_calendar` already had for the same reason and through the same live
script. The save skips it on its own reading of the stored value, because a
disabled input posts nothing and the toggle loop would otherwise write `0`. Collapsing them into one tick was considered and rejected:
"takes registrations" and "shows the button" are genuinely separable, and an
event taking registrations through a link elsewhere is a real case.

> **WHICHEVER CONTROL CARRIES "TAKES REGISTRATIONS" IS LOAD-BEARING.**
> `show_calendar` is not read while `sfaf_event_takes_rsvps()` is true, because
> the calendar file goes out with the confirmation instead. A change here reaches
> Add to calendar.

### The three jobs queued behind everything else, carried since 3.64.0

**MOVED OUT OF `HANDOVER.md` IN 3.72.0**, because none of it is about today and
all three had been carried release after release in a file whose whole job is
what is true right now. Each is a decision already taken about what to do next,
which is what this section is for.

1. **The `/caladmin` design audit.** 106 findings against `portal.css`, never
   written down. 3.64.0 took the control chunk and left it enumerated as a build
   gate rather than a list: `.claude/control-standard-audit.php`. What is left is
   spacing, density and type on individual screens.

2. **Simplify the event editor.** A parade of checkboxes, and several more cards
   since 3.35.0. A rendering-order and disclosure problem, not a data-model one.
   **The control standard went first on purpose:** it is a property of being a
   control, so moving controls between cards cannot undo it.

3. **Tailwind greys are still in `portal.css`.** `#F3F4F6`, `#6B7280`, `#4B5563`,
   `#D1D5DB`, `#E5E7EB` carry the locked and disabled states and are in neither
   the palette nor `DESIGN.md`s derived neutrals. Nothing looks wrong, so 3.64.0
   left them: a separate sweep with its own arithmetic, and the arithmetic is the
   work.

### The image picker stays one calendar folder, weighed 2026-08-21

**LEFT AS IT IS, DELIBERATELY.** Both public forms offer the same folder, and a
second campaign form would offer that same set rather than its own.

`SFAF_Media_Folder::FOLDER` is the single string `calendar`, and the picker is
narrowed to it by a flag on the request. Nothing in the arrangement reads the
series, so "the campaign's own images" is not a setting somebody forgot to turn
on. It is a folder per series, a picker that resolves which one it is standing
in, and an upload path that follows: three changes to a filter that hangs on a
global WordPress hook.

**The reason to wait is what that hook is.** Too narrow fails silently and in
the worst direction: this filter can narrow the WordPress media library itself,
on every screen of the site, for every plugin and every editor, and somebody
writing an unrelated page has no reason to suspect the calendar. That risk is
worth taking to solve a problem somebody actually has. It is not worth taking to
solve one nobody has yet.

**There is one campaign form.** With one, "not series-specific" describes no
observable behavior: the set it offers and the set it would offer are the same
set. Build the split when a second campaign exists and the folders genuinely
differ, because that is also the first moment the right shape is knowable rather
than guessed.

**What would change the answer:** a campaign whose images must NOT be visible to
another campaign's submitters. That is a confidentiality requirement rather than
a tidiness one, and it does not wait for a second form.
