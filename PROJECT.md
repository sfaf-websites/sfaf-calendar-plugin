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

**The picture grid is not `wp.media`, and cannot be.** The frame needs a
logged-in user with `upload_files`, so on this page it would not open. It is
radio buttons over the same `SFAF_Media_Folder` query the editor's picker uses.
**Picking from that grid sets the featured image**, because everything in it is
already an approved picture. Sending one of your own does not: see "Files from
people with no account" below.

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
- **The event contact is three fields and is public. The submitter's own name
  and address are two fields and are not.** Different keys, different labels,
  and only the first is readable from a template.
- **A submitted address does not create a venue.** The venue list is offered
  first; the parts are stored as the submitter's answer, and promoting one is a
  decision taken at approval by somebody who knows it will be used again.

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

**This is the third fatal here that lint could not see**, and the honest note is
that the check which would catch it, rendering the screen and asserting a
complete document, is not possible in the build environment: there is no
WordPress, no database and no HTTP server. Stubbing enough of WordPress would
mean the test deciding which functions exist, which is the question the bug
turned on. The contract check is the closest achievable thing, and loading the
screens stays a manual pass.

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

**Choosing is narrowed; DISPLAY never is.** An event whose image predates the
folder, or was set in the WordPress editor, renders exactly as before, and the
URL field still takes any address. The test asserts that no display path
mentions `SFAF_Media_Folder` at all, because the day one does is the day older
events start losing pictures.

Uploads made from caladmin land in the folder, deliberately overriding the
year-and-month setting: the folder IS the organisation for these, and a date
directory underneath would scatter the same pictures across twelve places a
year. Without it an upload would be invisible to the picker that made it.

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
  colour is the card ring, the chip and the placeholder tile, and the event page
  names the category nowhere in words.
- **`.uc-field-attention`**, which of roughly thirty fields is still empty. The
  publish banner names *what* is missing; only this says *where*.

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

---

## 3. Integrations

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

**Dateless imports, and the trap in those.** Many GFMP campaigns have no date at
all; a general fundraiser has no start or end. They are imported anyway with an
empty date and the manager sets it at approval. So `queue_ids()` **must not
order by the `_uc_event_date` meta**: setting `meta_key` in `WP_Query` implies
that meta must exist, which silently hides every dateless import. It sorts in
PHP instead, dateless last.

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

`apiv2-public-gfmp.json` in the project root is their OpenAPI spec and the
source of truth for field names. It is 2.2 MB and too large to read whole: query
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

### Cancelling, and why it is meta rather than a post status

An event that is not happening still has to exist: somebody registered for it,
and the registration is the record that they did. Deleting it strands them, so
since 3.36.0 **deleting an event that has registrations is refused** and
cancelling is the operation that exists instead.

`SFAF_Cancellation` stores `_uc_cancelled`, `_uc_cancelled_visibility`
(`stay`|`hide`) and `_uc_cancelled_at` on the event. **Not a post status**, and
the reason is the one `SFAF_Sources` already writes down in another context:
this plugin names `post_status => 'publish'` **by hand** in the shortcodes, the
embed payload, the REST feed, the .ics, the reminder query, the summary query
and the series listings. A new status is invisible to every one of those until
each is found and changed, and the failure mode of missing one is an event that
is cancelled everywhere except the place nobody checked. A meta flag inverts
that: nothing changes about which queries return the event, the two places that
must behave differently ask, and everywhere else keeps working. The organizer's
`stay`/`hide` choice needs a second field anyway, which settles it.

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
**Confirmed AND subscribed** since 3.39.0: somebody who only pressed Get
Reminders holds no place but is still expecting to turn up, and there is one
definition of "who is told about this event" rather than two. Somebody who
released their place is `cancelled` and is not written to.

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
actually changed AND somebody is registered or subscribed. Otherwise the save
goes straight through. A click in the way of a save that cannot email anybody
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

### The four message types

All four are **on** by default. `_uc_notify_off` records only what somebody has
switched off, so a default costs no writes and an event created before any of
this existed behaves like one created after.

| Kind | To | When |
|---|---|---|
| `confirmation` | the person registering | immediately |
| `alert` | the event's notification list | one per registration, as it happens |
| `reminder` | everybody registered, list copied in | 6am on the day (midnight if the event starts earlier) |
| `summary` | the notification list | two hours before; nothing sent if nobody registered |

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

### The cron trigger, and why WordPress cron is not enough

There is **one** runner, `SFAF_Cron`, on a **15-minute** recurrence, for every
unattended job. `SFAF_Cron::tasks()` is the single list of them: the reminder
pass, the pre-event summary and the third-party fetch. `run()` iterates it and
the Automation screen iterates it, so a job cannot be run without appearing on
the screen and cannot appear without being run. Anything added later joins that
list rather than scheduling its own event, so there is one lock, one log, and
one place to look when something happened overnight.

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

**Never run in production at all:**

- **Reminders have never been sent by the scheduled path.** The send-once
  design, the ledger's unique key, the 6am/midnight timing rule, the failure
  accounting: none of it has ever run unattended against real registrations.
- **The cron trigger has never fired in production.** No confirmed real system
  cron, and the embed ping has never been observed driving a run on the live
  site.
- **No email has been confirmed as delivered.** `class-sfaf-email.php` says so at
  the top of the file. Whether Postmark forwards Reply-To, and whether a
  `text/plain` part arrives at all, are facts about the transport visible only in
  a received message. `send_test()` exists to find out and the answer has not
  been recorded.
- **Neither import adapter has been observed running unattended.** GFMP and
  Eventbrite have been exercised by hand; the hourly fetch path has not.

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

---

## 7. The lessons that cost time

These are recorded because each cost more than one build and each recurred.

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

A shared thread runs through most of these: **a verified change is not a
verified outcome.** `git log -S` answers "was my edit applied"; it does not
answer "why does this still look like that". Start from the element as rendered
and hunt the effect by any mechanism.

---

## 8. Agreed, not built

Decisions settled in conversation that have no code yet. They live here because
a chat ends and this file does not. Move an entry into the body of this document
when it ships, and delete it here.

### Self-clearing import queues, agreed 2026-07-29

The last unbuilt piece of the original import design. Everything else from that
conversation shipped: the pending and dismissed sub-sections, publish, dismiss,
restore, dismissed-stays-dismissed even when the source updates it,
update-on-refetch, and unpublish-on-removal.

**What was agreed and is still missing:** an event that has expired (its date has
passed) or has been unpublished at the source should **disappear from the Pending
and Dismissed queues by itself**. Those are decision queues, so once there is no
decision left to make, the row is clutter. No manual cleanup.

**The distinction that must survive into the build:** this applies to the queues
only. A *published* event that expires simply becomes a past event and stays;
a published event removed at the source is unpublished and kept as a record,
which is already built. Do not let the queue rule reach published events.

### EveryAction event import, agreed 2026-08-17

**Why not an API.** EveryAction API keys **cannot be scoped to events only**.
Any key that returns event listings also exposes client and attendee records
from the **Aging Services** program. The API route was rejected on **privacy
grounds, not technical ones**. It would work, and the exposure is unacceptable.
Do not revisit this by finding a cleverer set of API calls; the constraint is
the key's scope.

**The agreed mechanism.**

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
