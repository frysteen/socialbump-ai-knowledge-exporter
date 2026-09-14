# SocialBUMP SEO for AI

Notes for whoever picks this up next, most likely a new chat with no memory of
how any of it came about. Read this first.

**Keep it current.** Change how something works, add a feature, or learn
something painful, and write it here in the same session. A note that is wrong is
worse than no note, so fix anything you find that has gone stale.

That means all of it, not just the overview. Add a module and it gets its own
entry under the detailed list, describing what it does, how it does it, and what
it can be set to. Change how a setting behaves and the entry for that setting
changes with it. Add a setting to an existing module and add it to that entry.
The detail is the point: an overview that says a module exists helps nobody who
has to change it.

## What it does

Builds three files that describe the whole site for AI crawlers:

- llms.txt, a slim index.
- llms-full.txt, every chosen post with its content as Markdown.
- llms-details.txt, the same plus notes on how the site is put together.

They are served virtually from the cache by default, with no files on disk, which
needs pretty permalinks. Physical mode writes real files to the site root.

## Where it came from

It began as four WP CodeBox snippets, and the code here is still recognisably
those snippets. The core file is 330 KB of working code that was moved, not
rewritten, so it does not look like the other two plugins inside. That is on
purpose: it works, and rewriting it wholesale would be a lot of risk for no gain.

Because the plugin and the snippets declare the same classes and functions, they
cannot both run. The plugin switches the snippets off as it loads, before WP
CodeBox gets to them, and tells you what it switched off. See
sbaike_stand_down_snippets() in the main file.

## How a page is turned into Markdown

This is the expensive part and the heart of the plugin.

1. The renderer fetches the page over HTTP, as a visitor would.
2. It strips the site furniture using a list of CSS selectors you can edit under
   Settings, Content Rendering.
3. What is left becomes Markdown, and is cached against the post.
4. A builder module may step in first: Bricks reads the element tree directly,
   which is better than reading the rendered page.

Because it fetches over HTTP, a site in coming soon or maintenance mode would
hand back the holding page. The fetch carries a short lived token and Bricks is
told to stand its holding page down for that one request. Another coming soon
plugin can be handled by hooking socialbump_aiknowledge_render_request.

## The three files, and how they differ

| File | What is in it |
| --- | --- |
| llms.txt | the slim one: business block, then an index of every included post by type and taxonomy, with links |
| llms-full.txt | the same, plus the whole content of every post as Markdown, plus its chosen custom fields |
| llms-details.txt | everything in full, plus notes on how the site is built: which builder made a page, template relationships, field names |

All three are assembled from the same cache in one pass. That is why writing them
is cheap and rendering is not.

## Serving

Virtual is the default and the one to use. The three routes are served straight
from the cache through a rewrite rule, with nothing on disk, so a file can never
fall out of step with the settings. It needs pretty permalinks, any setting other
than Plain.

Physical writes real files to the WordPress root, for a host where the rewrite
cannot work. Switching back to virtual deletes them, so a stale file cannot
shadow the live one.

Tell a caching plugin to leave /llms.txt, /llms-full.txt and /llms-details.txt
alone, especially in virtual mode, or it will serve yesterday text.

## Custom fields

ACF is optional throughout, and every ACF call is guarded, so the plugin runs on a
site without it.

- Per post type you choose which fields are exported and in what order, under
  Content, Post Types to Include, Post Content Ordering.
- The ACF Options Page Fields panel picks site wide fields for the business block.
- {post_content} is a pseudo field, so the body can be ordered among the fields
  rather than always coming first. It is preserved verbatim when settings are
  sanitised, because sanitize_key would eat the braces.
- Only sensible field types are offered. Anything that looks like a secret is
  refused by name: api_key, token, password, secret, stripe, webhook and friends.
- ACF Field Visibility on the Settings page keeps a ticked field out of one file,
  or both. A rule is three parts: a post type or the all types wildcard, the field
  name, and full, details or both. Rules stack, and any matching rule wins.
- The Content page marks a field that a rule affects: Excluded from Full in blue,
  Excluded from Details in purple, Excluded from export in red. A field excluded
  from both cannot be ticked, since it would not be exported either way, so the
  tick is disabled and the row dimmed.
- A disabled checkbox submits nothing, so when such a field is ticked a hidden
  input carries the value. Without it, the selection would quietly disappear on
  the next save. Remember this if you ever disable another checkbox here.

## Choosing what is exported

- Post types are ticked on the Content page and dragged into the order they
  appear in the files.
- Within a type, Posts to Include lists every published post so individual ones
  can be left out. New posts default to included.
- Exclusions are stored per post type, and only overwritten when that picker was
  actually on the page, through a hidden marker. Without it, saving a page that
  did not show the picker would wipe the exclusions.
- Some post types are never offered: attachments, revisions, menu items, blocks,
  templates, ACF internals, WooCommerce orders and similar.
- Taxonomies get their own sections, and annotate posts in the slim file.

## On the post edit screen

Every included post gets a small box showing whether its cache is current, when it
was last built, and a button to bring it up to date on its own. It shows three
states: up to date, unsaved changes, and needs update. Unsaved changes asks you to
save first, because rebuilding from what is still in the editor would cache the
old version.

## The modules

Modules live in extensions/ and are loaded automatically, so adding support for
something is a matter of dropping a file in. The renderer loads first because the
builder modules build on it, and a fatal inside one is caught.

- rendered-content-renderer.php, the shared renderer. Always on.
- bricks-builder.php, reads Bricks element content and template relationships.
- elementor.php, the same idea for Elementor.

A module registers itself with register_extension( slug, info ), where info
carries name, version, category, description, and optionally admin_page for a
settings page of its own. It contributes content through filters, chiefly
socialbump_aiknowledge_post_content_markdown.

Modules are not switched on by hand. Each detects what it needs and steps in, and
the Modules panel on the Settings page reports what turned up.

## The admin, and why it is unusual

The exporter renders its own settings page, all 170 KB of it, from that original
snippet. Rather than rewrite it, SBAIKE_Admin catches that output and reshapes it:

- unwrap_cards() takes off the inline styled boxes it draws around each section,
  but leaves the box around each post type, which is that list layout. A box
  containing a heading is a section; anything else is layout.
- panels() turns each heading and its description into one of our panels.
- only_sections() keeps just the sections that belong on the page being viewed,
  and adds a hidden marker naming them.
- take_save_bar() lifts the save bar out of whichever section it was drawn in and
  puts it at the foot of the form, so every page has one.
- quieten_dirty_script() points the old unsaved changes code at nothing. That
  same script also sets up the drag to reorder lists, so it has to stay.

It is stitching, and it is fragile in one specific way: it matches on markup the
core file prints. Change that markup and check these still work.
## The files, and what each one is for

| File | Size | What it is |
| --- | --- | --- |
| socialbump-ai-knowledge-exporter.php | 8 KB | constants, updater, hub check, sbaike_log_change(), stands the WP CodeBox snippets down, loads everything |
| includes/class-socialbump-ai-knowledge-exporter.php | 326 KB | the exporter itself, moved from the snippet |
| includes/class-sbaike-admin.php | 34 KB | menu, the three settings pages, reshaping the exporter own page, admin bar |
| includes/class-sbaike-rebuild.php | 10 KB | the batch runner and the report |
| includes/class-sbaike-release.php | 29 KB | publishing, hub only |
| includes/class-sbaike-updates.php | 5 KB | the Updates page |
| includes/class-sbaike-transfer.php | 5 KB | settings export and import |
| includes/class-sbaike-docs.php | 6 KB | these notes and the Publishing panel |
| extensions/rendered-content-renderer.php | 18 KB | fetch, strip, convert to Markdown |
| extensions/bricks-builder.php | 19 KB | read Bricks elements and templates |
| extensions/elementor.php | 7 KB | the same for Elementor |
| assets/js/rebuild.js | 4 KB | the progress bar |

## What it offers other code

Filters, all prefixed socialbump_aiknowledge_:

| Filter | Changes |
| --- | --- |
| post_content_markdown | the body of one post. This is how a builder module takes over |
| post_sections | extra sections on a post |
| post_meta_lines | the meta lines under a post |
| post_index_tree | the index of posts in a type |
| post_type_section_lines | the heading block for a post type |
| taxonomy_section_lines, term_lines | the same for taxonomies and terms |
| business_info_lines | the business block at the top of every file |
| excluded_post_types, excluded_meta_keys | what never gets exported |
| allowed_acf_types | which ACF field types can be exported |
| available_page_builders | what the details file reports as available |
| renderer_sslverify | for a site with a broken certificate |

Also the action socialbump_aiknowledge_render_request, which fires when the
exporter is fetching a page. Hook it to stand down a coming soon plugin other
than the Bricks one, which is handled already.

Methods worth knowing, all public on the exporter:

- cache_post( id ) renders one post into the cache, without touching the files.
- build_files() assembles the three files from what is cached.
- exportable_post_ids( type, stale_only ) is the work list for a job.
- exported_post_types() is what the settings have chosen.
- count_stale_posts_for_type(), count_eligible_posts_for_type(),
  get_global_stale_count(), post_cache_is_fresh() answer the status questions.
- clear_post_cache(), clear_post_type_cache(), clear_all_post_caches() forget.

## The pages

| Page | Slug | Holds |
| --- | --- | --- |
| Content | sb-ai-knowledge-exporter | Status, ACF options fields, taxonomies, post types |
| Business | -business | The business context written into every file |
| Settings | -settings | Modules, content rendering, field visibility, file serving, automatic updates, danger zone |
| Updates | -updates | Version, update check, settings export and import |
| Publishing | -publishing | Hub only |

Content is the landing page, because it decides what ends up in the files.

Saving any page returns you to it, through a hidden sbaike_return field and a
filter on the redirect, because the exporter always sent you to its own page.

## Every panel in detail

The pages are Content, Business and Settings. This is what sits on each one, what
it changes, and where the setting ends up.

### Content page

**Status.** One pill per exported post type: green when every post in it is
current, amber with a count when some are not. An amber pill is a link that
re-renders just that type and rebuilds the files, the same action as the pill on
the post type heading further down. Types with nothing eligible are left out
entirely. Counts come from count_stale_posts_for_type() and
count_eligible_posts_for_type().

**ACF Options Page Fields.** Site wide ACF fields, written into the business block
at the top of every file, so a crawler reads the phone number and address before
anything else. Tick what to include and drag to set the order they appear in.
Stored as acf_options_fields and acf_options_fields_order.

**Taxonomies to Include.** A ticked taxonomy gets its own section in both files,
listing its terms and the posts under each, and its terms also annotate posts in
the slim file. Drag to order the sections. Term level ACF fields can be picked per
taxonomy. Stored as taxonomies, taxonomies_order, acf_term_fields and
acf_term_fields_order.

**Post Types to Include.** The heart of the page. Tick a type to export it, drag
to order the types in the output, and inside each one:

- *Post Content Ordering* picks which custom fields are exported for that type and
  in what order. {post_content} is a pseudo field for the body itself, so the body
  can sit among the fields rather than always first. A field a visibility rule
  affects is badged here, and one excluded from both files cannot be ticked.
- *Posts to Include* lists every published post so individual ones can be left
  out. New posts default to included. Exclusions are stored per type and only
  rewritten when that picker was actually on the page, through a hidden marker.
- The heading carries the status pill for that type, and Rebuild All, which clears
  that type cache and re-renders every post in it.

Stored as post_types, post_types_order, acf_fields, acf_fields_order and
excluded_posts.

### Business page

**Business Details.** Free text written into the top of every file as the business
context block: name and description, which fall back to the site title and tagline
when empty, plus an Additional Information heading and body for anything else a
crawler should know first, such as service areas or trading hours. Line breaks are
kept. The section only appears in the files when there is something in it. Stored
as business_name, business_description, compliance_notes_title and
compliance_notes.

### Settings page

**Modules.** What the exporter can read content from, reported rather than
switched on: each module detects what it needs and steps in. A card shows the
module name, its category and version, Detected or Standby, and a link to its own
settings if it has any. Nothing here is stored: it is read live from
get_registered_extensions().

**Content Rendering.** The list of CSS selectors stripped out of a fetched page
before it becomes Markdown: header, footer, nav, cookie notices, related posts,
whatever else is furniture rather than content. Shown as pills, with the defaults
in one colour and your own additions in another, and a Restore Defaults action
that merges the original list back in. The list is fully editable, including
removing the defaults, and once saved it is honoured exactly, even when empty.
That is what renderer_selectors_initialised records. Stored as
renderer_strip_selectors.

This is the setting most likely to need attention after a redesign. A theme change
can start wrapping real content in something that is being stripped, and the only
sign is content quietly missing from the export. Check the output after any big
front end change.

**ACF Field Visibility.** Keeps a ticked field out of one file, or both. A rule is
a post type or the all types wildcard, a field name, and full, details or both.
Rules stack and any matching rule wins. Use it for detail that belongs in
llms-details.txt but not the everyday file, or the other way round. Stored as
field_omit_rules, and answered by field_is_omitted().

**File Serving.** Virtual, the default, serves the three routes live from the
cache through a rewrite rule with nothing on disk, so a file can never fall out of
step with the settings. It needs pretty permalinks. Physical writes real files to
the WordPress root for a host where the rewrite cannot work. Switching back to
virtual deletes the physical files, so a stale one cannot shadow the live version.
Stored as serving_mode, with the files themselves in socialbump_ai_virtual_store.

**Automatic Updates.** A cron event that re-renders changed posts and rewrites the
files on a schedule, so the everyday Update Files click becomes optional. The
interval is a minimum, not a clock: WordPress runs scheduled tasks on visits, so a
quiet site has longer gaps. The panel shows when it last ran and whether it found
anything to do. It runs server side in one go and is not batched, which is fine
for a trickle of edits and would not be for a bulk import. Stored as cron_enabled
and cron_interval, with the last run in sbaike_cron_last_run.

**Danger Zone.** Reset Everything wipes every setting and every cached page, and
deletes the generated files. It is for starting again, not for fixing a bad
render: a Full Rebuild does that without losing your settings.

### Updates page

The version running, whether a newer one is out, a button to ask GitHub now rather
than waiting for the twice daily check, and settings export and import. The export
is a JSON file of every setting, named after the site and the date. Import
replaces the settings on this site and refuses a file from a different plugin. The
GitHub token is never included.

## Rebuilding, and why it is batched

Rendering a post means fetching a page over HTTP, so a site with a few hundred
cannot be rebuilt in one request. It used to try, and the page simply hung until
something gave up.

SBAIKE_Rebuild runs the work in batches over AJAX. A job is a list of post IDs in
a transient. The browser asks for three at a time, each request doing a little and
returning, then one final call assembles the files. Nothing runs long enough to be
cut off, and a request that does die takes nothing with it: the browser tracks the
position and already cached posts stay cached.

Scopes: everything, stale, type, type-stale, post. A rebuild clears the cache for
what it covers first; an update does not, so only changed posts re-render.

Anything carrying data-sbaike-job runs through it: Full Rebuild, Rebuild All on a
post type, the amber pill on a post type, and Update on a single post row. With
JavaScript off they still work as plain links, all in one go.

Afterwards the page reloads and shows a report: a line per post type with counts,
plus taxonomies, ACF options fields and business details, since those are written
fresh every time the files are assembled.

Update Files and Save changes are deliberately not batched: they are the same
submit, which saves your settings first. The batch runner already has a stale
scope ready if a site ever needs it.

## Staleness

A post is stale when it has changed since its cache was written. Counts appear as
pills on Content, on each post type heading, and as a dot in the admin bar.
post_cache_is_fresh() is the single answer to the question; the global count is
cached in a transient for five minutes and flushed when anything changes it.

## The scheduled update

A cron event re-renders changed posts and rewrites the files on a schedule, set
under Settings, Automatic Updates. It runs server side, all in one go, and is not
batched. On a very large site with a lot of stale content at once that has the
same limit the buttons used to have. Worth batching if it ever bites.

## What it stores

| Name | Holds |
| --- | --- |
| socialbump_ai_knowledge_exporter_settings | every setting |
| _socialbump_ai_cache (post meta) | the rendered Markdown per post |
| socialbump_ai_virtual_store | the three files, when serving virtually |
| socialbump_ai_rewrite_version | the rewrite rules version |
| sbaike_global_stale_count | cached count, five minutes |
| sbaike_cron_last_run | when the schedule last ran |
| sbaike_github_token | encrypted, hub only |
| sbaike_pending_changes | notes for the next release |

The four names beginning socialbump_ai are shared with the old snippets on
purpose. That is what lets a site move from snippets to plugin with every setting
and every cached page intact. Do not rename them. Everything else is sbaike_.

## Where to be careful

- The core file is huge and was moved rather than written. Read before editing,
  and prefer adding a small public method over reworking what is there.
- The admin reshapes markup the core prints. Change that markup, check the admin.
- cache_post() and build_files() are the entry points the batch runner needs. Do
  not make them private.
- The renderer strips by CSS selector. A theme change can quietly take content
  out of the export, so check the output after a redesign.

<!-- shared:start -->

## House rules, shared by all three SocialBUMP plugins

This block is identical in the docs of all three plugins. Change it in one and
copy it to the other two in the same session. They all live on the hub, so that
is a two minute job, and the Publishing page warns you when they have drifted.

### The three plugins

| Plugin | Folder | Prefix | Menu |
| --- | --- | --- | --- |
| SocialBUMP Bricks Tweaks | socialbump-bricks-tweaks | SBBT_ / sbbt_ | SB Bricks Tweaks |
| SocialBUMP Site Kit | socialbump-site-kit | SBSK_ / sbsk_ | SB Site Kit |
| SocialBUMP SEO for AI | socialbump-ai-knowledge-exporter | SBAIKE_ / sbaike_ | SB SEO for AI |

SEO for AI was called AI Knowledge Exporter until September 2026. Its folder,
text domain, option names and GitHub repo still say so, deliberately: renaming
them would break the update checker and the saved settings on every site.

Which plugin does a job belong in? Needs the Bricks theme, Bricks Tweaks.
Useful on any site, Site Kit. About what AI crawlers read, SEO for AI.

### Files that are identical in each plugin

- includes/class-socialbump-admin-bar.php
- assets/js/save-state.js

Change one, change all three, then check the md5s match. Both are written so
that whichever plugin loads first wins and the others stand aside, so a site
running mixed versions still works.

### The shared admin bar item

SocialBUMP_Admin_Bar::register() takes id, label, href and items, and optionally
actions, attention, attention_title and current. Everything is drawn once, at
admin_bar_menu priority 200.

- One plugin active: that plugin sits on the bar on its own.
- Two or more: a single SocialBUMP item, each plugin a row inside it, its pages
  on a flyout from that row.
- Each row has a dot: green when there is nothing to do, amber when there is.
  Any amber row makes the SocialBUMP dot amber, so the top of the bar is the
  only thing that needs watching.
- attention means an update is waiting. SEO for AI also counts stale posts.
- The current page is white and bold, never the admin colour scheme accent:
  some accents are unreadable on the dark bar.
- An action row marked sb-bar-action is-idle looks inactive and ignores hover.

### Unsaved changes, and the save button

Any form marked data-sb-dirty is watched. The save button sits disabled reading
Nothing to save until something changes, then wakes up with its own wording and
an amber reminder appears top right and follows you down the page. Put the change
back the way it was and both go quiet. Leaving with something unsaved warns you.

Attributes a button can carry:

- data-sb-save: treat as a save button even though it is not a submit.
- data-sb-label-dirty: the wording to use when there is something to save, for a
  button whose resting label says there is nothing.
- data-sb-always-on: never disable this one. Used for buttons that do work
  rather than save, such as Full Rebuild.
- data-sb-idle=1: nothing to run right now, so sit inactive until there is.

Styling: .sb-save--clean is a grey outline on transparent, .sb-save--dirty is
pale yellow with an amber border, matching the reminder. Both selectors lead with
.wp-core-ui and .button, because WordPress styles disabled and primary buttons
with important and would otherwise win.

### The look

- One stylesheet per plugin at assets/css/admin.css, every class prefixed.
- Dark banner: SocialBUMP logo, plugin name, page name in a span in the accent
  colour, then a version badge linking to Updates that turns amber when a
  release is waiting. The heading reads plugin name then page name, including on
  a landing page: Site Kit Modules, Bricks Tweaks Features, SEO for AI Content.
- Panels: prefix-section, with __head for the heading and description and __body
  for the content.
- Cards: prefix-card, is-on for a live one, is-unavailable for one waiting on
  something missing. The left edge carries the accent when live.
- Pills: prefix-status__pill, is-good green, is-stale amber. An amber one that
  can be acted on is a link, and clicking it does the thing it describes.
- Menu icon: the same toggle switch SVG, recoloured by WordPress. Each plugin
  positions its menu next to the others rather than at a fixed spot.
- The accent comes from the admin colour scheme, chosen by saturation so a
  washed out swatch is never picked, and exposed as --prefix-accent.

### Releasing

Everything is developed and released on the hub, bricks.socialbump.com.au. Each
plugin decides it is on the hub by host name, and only then loads its release
code and shows a Publishing page.

- Publishing pushes the code to GitHub, builds a zip, creates a release and
  attaches the zip. Sites update through the plugin update checker.
- One fine grained GitHub token per plugin, stored encrypted, scoped to that one
  repo with Contents read and write. A token cannot create repositories, so a new
  repo is made by hand first.
- The notes box fills from prefix_log_change() calls made since the last release,
  and the list empties once a release goes out. Call it after any change worth
  telling someone about, in their words rather than yours.
- Publishing retries on a 5xx, checks the zip actually attached, and checks again
  before undoing anything, because GitHub has published a release and then failed
  the response.
- The first release may carry the version already in the files. Every release
  after that has to be higher than the last.
- Everything in the plugin folder is published except .git, .github, node_modules
  and .DS_Store. These docs ship with the plugin, so they reach every site, and
  the repos are public: nothing private goes in them.

### How work actually gets done here

There is no local checkout and no git client. Everything happens on the live hub
through its Novamira MCP connector, by running PHP on the site. That shapes how
to work:

- Read a file with file_get_contents, write it with file_put_contents.
- Lint before you write. Put the new contents in a temporary file, run php -l on
  it, and only write the real file when it passes. A fatal in a plugin file takes
  the site down, and you are editing the site you would need to fix it.
- JavaScript has no linter here. Walk the brackets, minding strings, comments
  and regular expressions, before writing.
- Call opcache_invalidate() on a file after writing it.
- A class already loaded in the current request is still the old one. Check your
  work in a fresh call, not the one that wrote the file.
- Anchor edits on a unique string and check it matches exactly once. If it
  matches twice, widen it until it does not.
- Keep a copy before a risky edit. copy( $file, sys_get_temp_dir() . ... ) costs
  nothing and has saved a rewrite more than once.
- Verify after every write. A write that silently did nothing, because the anchor
  never matched or the function returned early, has cost more time here than any
  actual bug.

Watch out for quoting when building PHP through a JSON tool call. A backslash in
a regular expression, or a quote in a string, has to survive JSON, then PHP, then
whatever it is written into. Building strings with chr( 34 ) and concatenation is
uglier to read but far less likely to arrive mangled.

### Where things live

The hub is bricks.socialbump.com.au, and the plugins are in the usual place:
wp-content/plugins/<folder>/. Client sites each have their own connector and the
same folder structure.

Every plugin has the same shape:

| File | What it is |
| --- | --- |
| <plugin>.php | constants, updater, hub check, log_change(), loads everything |
| includes/class-<pre>-settings.php or -admin.php | menu, pages, banner, admin bar registration |
| includes/class-<pre>-modules.php | finds and boots the modules |
| includes/class-<pre>-release.php | publishing to GitHub, hub only |
| includes/class-<pre>-updates.php | the Updates page and the update check |
| includes/class-<pre>-transfer.php | settings export and import |
| includes/class-<pre>-docs.php | these notes, and the panel on Publishing |
| includes/class-socialbump-admin-bar.php | shared, identical in all three |
| assets/css/admin.css | everything the admin pages look like |
| assets/js/save-state.js | shared, identical in all three |
| vendor/plugin-update-checker | the updater library, left alone |

### Working on a plugin from a client site

Work on the hub by default. Build on a client site only when it has something
the hub has not, which in practice means WooCommerce: the WooCommerce modules in
Site Kit were built on drivingevents.com.au for that reason.

When you have, bring it home carefully. Assume nothing at any step:

1. List both folders and compare every file by md5 and by modified time. Not just
   the files you think you touched: a file you did not expect to differ is
   exactly the one worth knowing about.
2. For each file that differs, work out which side is newer and why before you
   move anything. The hub may have moved on while you were working elsewhere, and
   the client copy may be an older release rather than your new work.
3. Read any file the hub has changed, in full, before overwriting it. Two people
   editing the same file from different directions is how work disappears.
4. Copy back only the files that genuinely differ, one at a time.
5. Compare the md5s again afterwards and confirm each one matches.
6. Update these docs on the hub, never on the client site.
7. Call prefix_log_change() on the hub, so the work appears in the next release.

If the two sides have both changed the same file, stop and say so rather than
picking one. Merging by hand with both versions in front of you takes minutes.
Guessing wrong costs whatever was on the losing side, and nobody finds out until
later.

Nothing may live only on a client site. The next update overwrites the plugin
folder, and anything not carried back to the hub is gone.

### Habits that have paid off

- Lint every PHP file before writing it, and bracket check any JavaScript.
  Write to a temporary file, lint that, and only then put it in place.
- Write a class file before the loader that requires it, so a failure never
  leaves a plugin pointing at a file that is not there.
- Keep each edit small and check it took. A write that silently did nothing has
  cost more time here than any bug.
- Anchor edits on unique strings. If an anchor matches twice, stop and widen it.
- After editing a file, the class already loaded in that same request is still
  the old one. Verify in a fresh request, not the one that wrote the file.

### Things learned the hard way

- PHP declares top level classes and functions while compiling the file, before
  a line of it runs. A class_exists() guard inside the file that declares the
  class always sees its own class and returns, and the file never finishes. This
  broke SEO for AI once. Guard by other means.
- WordPress styles disabled and primary buttons with important. Beat it with
  specificity, not with another important on its own.
- admin_head has already been sent by the time the admin bar is built, so a
  style hooked only there never appears. Hook the footer as well.
- A settings page that submits only part of the settings must merge rather than
  replace, or saving one page wipes the others. Site Kit and SEO for AI have both
  had this bug. Both now post a marker of which sections were on the page.
- An element with no link is rendered by the admin bar as an empty item, not an
  anchor, so style both.
- Nested admin bar flyouts need position relative on the row, or they fly off
  to the right of the whole menu.
- The admin menu can be renamed by an admin menu plugin. Admin and Site
  Enhancements holds its own titles and wins over whatever the plugin registers.

<!-- shared:end -->
