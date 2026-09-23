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
4. A builder module can step in first through the same filter. None does yet:
   the Bricks module reports which posts are built with Bricks and which
   templates apply, but the content of every post, Bricks or not, comes from the
   renderer.

Because it fetches over HTTP, a site in coming soon or maintenance mode would
hand back the holding page. The fetch carries a short lived token and Bricks is
told to stand its holding page down for that one request. Another coming soon
plugin can be handled by hooking socialbump_aiknowledge_render_request.

## What the converter does to builder output

html_to_markdown() in the core is a chain of regular expressions, and the order
matters. Things it does that are easy to break:

- Adjacent buttons. A builder writes a button group as sibling anchors with no
  whitespace between them, so without help they render as one word. The rule
  that splits them matches on the class attribute, so it has to run before the
  aggressive pass strips every class. It once ran after, and matched nothing
  on renderer output. A second rule then puts a space between any two anchors
  still touching, class or no class.
- Card grids. A heading inside a list item becomes a bold link on the bullet,
  and when a single paragraph follows it joins the same line:
  - **[Title](url)**: excerpt. That is done in render_list_item_content().
- Tables. One line per row, cells separated by bars, a rule under a header row.
- The hero. The renderer module drops, from the first dozen lines of a post,
  any whole line equal to the title, the custom title, the subtitle or the
  excerpt, since the meta lines above the body already carry them. Exact match
  only, so a later mention in prose is never touched. That is
  strip_repeated_intro_lines() in rendered-content-renderer.php.

Measured on a site of 177 posts, those four took the full file from 137 glued
button pairs to none, 677 headings on bullets to none, and 80 repeated titles
to one, with the index, meta lines and slim file unchanged.

Not done on purpose: body headings start at # under a ### post heading, so the
hierarchy is inverted. Demoting them would touch every file for a gain a
language model does not need.

A template section repeated on every page of a type, such as a related items
grid, is content, not chrome, so the converter leaves it. Strip it by class
under Settings, Content Rendering if it is bloat.

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

On a site in coming soon or maintenance mode the holding page answers the
three routes too, the hub included, so test serving on a live site such as
thecosmeticstudionoosa.com.au rather than the blueprint.

A route asked for while the store is empty, or physical mode with the file
missing, serves what the post cache already holds and renders nothing:
rendering means an HTTP fetch per post, and an anonymous visitor must never be
able to start hundreds of them. A post not yet cached appears without its body
until the next Update fills it in and rewrites the store. suppress_render on
the exporter is the switch, honoured by get_post_markdown_cached().

robots_txt_lines() on robots_txt at priority 110 adds the llms.txt and
llms-full.txt addresses to robots.txt as comments, after Site Kit's Robots.txt
(100) or an SEO plugin's, so it works whichever supplies the file. Only while
the files are served (virtual store has content, or the physical llms.txt
exists), not on a site discouraging search engines, and not if llms.txt is
already named. Crawlers ignore comments; it is a pointer for people and tools.

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
- bricks-builder.php, reports which posts are built with Bricks and the template
  relationships, for the meta lines and the details file. It does not read
  element content; the renderer does that for Bricks pages too.
  It also watches template saves and marks the posts they cover as stale.
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
| socialbump-ai-knowledge-exporter.php | 9 KB | constants, updater, hub check, sbaike_log_change(), stands the WP CodeBox snippets down, loads everything |
| includes/class-socialbump-ai-knowledge-exporter.php | 338 KB | the exporter itself, moved from the snippet |
| includes/class-sbaike-admin.php | 37 KB | menu, the three settings pages, reshaping the exporter own page, admin bar |
| includes/class-sbaike-rebuild.php | 10 KB | the batch runner and the report |
| includes/class-sbaike-release.php | 30 KB | publishing, hub only |
| includes/class-sbaike-updates.php | 5 KB | the Updates page |
| includes/class-sbaike-transfer.php | 5 KB | settings export and import |
| includes/class-sbaike-docs.php | 6 KB | these notes and the Publishing panel |
| extensions/rendered-content-renderer.php | 20 KB | fetch, strip, convert to Markdown |
| extensions/bricks-builder.php | 23 KB | Bricks detection and template relationships, not content |
| extensions/elementor.php | 7 KB | the same for Elementor |
| assets/js/rebuild.js | 9 KB | the progress bar |

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
a transient. The browser asks for a few at a time, each request doing a little and
returning, then one final call assembles the files. Nothing runs long enough to be
cut off, and a request that does die takes nothing with it: the browser tracks the
position and already cached posts stay cached.

Three requests run at once, because nearly all the time goes on waiting for a page
to come back over HTTP rather than on any work the site is doing. Each request is
told which offset to render, so nothing depends on the order they return in and a
failed one can simply be asked for again. The count is kept by the browser rather
than taken from the server, since the offsets no longer arrive in order.

Measured on a site of 177 posts: one at a time took 246 seconds, three at once
took 133, so about 0.75 seconds a post. It is not three times faster because the
site is now rendering three pages at once and each takes a little longer under
that load. More workers buy very little and start to make the site sluggish for
anyone browsing it mid rebuild, so three is the number unless a host says
otherwise. The filter socialbump_aiknowledge_rebuild_workers changes it, and one
is the safe answer on a host that objects.

Scopes: everything, stale, type, type-stale, post. A rebuild clears the cache for
what it covers first; an update does not, so only changed posts re-render.

Anything carrying data-sbaike-job runs through it: Update Files, Full Rebuild,
Rebuild All on a post type, the amber pill on a post type, and Update on a single
post row. With JavaScript off they still work as plain links, all in one go.

A job refuses to start while the settings form has unsaved changes, and says so,
because it reloads the page when it finishes and would throw them away. The save
button being enabled is the signal it reads.

While it runs, the panel names what it is on, with the post type first and in
bold: Treatment: Healite, Page: About. Labels are looked up once per type per
batch. A timer in the top right corner counts the seconds.

Afterwards the report appears inside the same box: a line per post type with
counts, plus taxonomies, ACF options fields and business details, since those
are written fresh every time the files are assembled. Close reloads the page so
the pills catch up. finish() returns the report in its response;
SBAIKE_Rebuild::report_body() draws it, and report() still reads the older
transient path for anything that might set it.

Save changes saves the settings, then looks at how much rendering the save
calls for. Nothing stale: the files are rewritten in the same request, about a
second on a healthy cache. Anything stale, a newly ticked post type being the
usual case: the files are first rewritten from what is already cached, with no
rendering, so they match the new settings at once, and the redirect back to
the page carries sbaike_autorun=stale, which the page reads and starts the
stale job through the batch runner with the progress bar, the same as Update
Files. Saving used to render synchronously inside the save request, which
froze the page with no progress bar whenever a save added real work. The
autorun flag is taken back out of the address by the script, so the reload
behind Close does not run it again. Update Files runs the stale scope through
the batch runner and does not save settings first: the unsaved changes guard
sends you to Save.

## Staleness

A post is stale when it has changed since its cache was written. Counts appear as
pills on Content, on each post type heading, and as a dot in the admin bar.
post_cache_is_fresh() is the single answer to the question; the global count is
cached in a transient for five minutes and flushed when anything changes it.

Each cache entry also carries a fingerprint of the settings it was rendered
under, and a mismatch counts as stale. The cache holds only the rendered body,
so the fingerprint covers only what the renderer reads: the strip selectors and
their initialised flag. Nothing else. It used to include the ACF field picks,
the options fields and the post type list as well, none of which touch the body,
so unticking one options field re-rendered every post on the site. That was the
hang people saw on Save.

Narrowing it meant every existing entry carried a value the new fingerprint could
never match, so maybe_restamp_cache() runs once on admin_init after the update
and rewrites the stamps a hundred rows at a time, leaving the markdown alone. It
records that it has run in sbaike_fingerprint_scheme. If the fingerprint is ever
narrowed again, bump that scheme number and the restamp runs once more.

A post is also stale when something it is rendered through has changed since,
which its own modified date cannot show. touch_post_type() and touch_posts()
record a timestamp in sbaike_touched, and both staleness checks hold it against
the cache stamp. The Bricks module calls them when a template is saved in the
builder (save_post_bricks_template, and the two meta keys Bricks writes),
reading the template conditions for the post types or post IDs it applies to.
A header, footer, popup or section template cannot be mapped to posts from its
conditions, so editing one of those still needs a Full Rebuild. The cache
entries are kept, so the files serve the old render until the next Update.

## The scheduled update

A cron event re-renders changed posts and rewrites the files on a schedule, set
under Settings, Automatic Updates. It runs server side, all in one go, and is not
batched. On a very large site with a lot of stale content at once that has the
same limit the buttons used to have. Worth batching if it ever bites.

## What it stores

| Name | Holds |
| --- | --- |
| socialbump_ai_knowledge_exporter_settings | every setting |
| _socialbump_ai_cache (post meta) | the rendered Markdown per post, with cached_at and the settings fingerprint in the same value |
| socialbump_ai_virtual_store | the three files, when serving virtually |
| socialbump_ai_rewrite_version | the rewrite rules version |
| sbaike_global_stale_count | cached count, five minutes |
| sbaike_cron_last_run | when the schedule last ran |
| sbaike_github_token | encrypted, hub only |
| sbaike_pending_changes | notes for the next release |
| sbaike_fingerprint_scheme | which fingerprint scheme the cache stamps use, so the one-off restamp runs once |
| sbaike_touched | when each post type, or particular post, was last touched by a template save |

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
- The cache stamp lives inside the same meta value as the markdown, so anything
  that checks staleness loads the markdown too: the stale count behind the admin
  bar dot, and the Content page. Leave update_post_meta_cache on for those
  listings. Switching it off was tried and it made things worse, because
  get_post_meta() then fetches each post meta one query at a time, 14 queries
  became 184, and the markdown was loaded anyway. The only real fix is moving the
  stamp to its own small meta key, and that has not been done.
- get_term_posts_index() is the one listing that never reads meta, so it does
  turn the meta cache off.
- clear_all_post_caches() goes through delete_metadata() rather than a raw
  $wpdb->delete, so the object cache is cleared with the rows. A raw delete left
  the old values cached on a site with a persistent object cache, and the
  staleness checks kept reading them.

## House rules for this plugin

SEO for AI is standalone. It shares no code, no admin bar item, no menu and
no update stream with SocialBUMP Site Kit or SocialBUMP Bricks Tweaks, and it
does not appear on the SocialBUMP Hub page. It may copy from them, and has:
the save button, the banner and the look all began as their code. Copy freely,
share nothing. A copy that drifts looks slightly wrong. A shared file that
drifted took two live client sites down, which is why this plugin was pulled
out of that arrangement.

This block used to be a block of notes identical in all three plugins. It is
now this plugin's own, so change it here and nowhere else.

### Getting between the pages

The banner carries a row of links to every page in the plugin, with the one you
are on marked. The admin menu lists them too, but on a long menu the plugin can
be a scroll away and its pages only show while you are already on one of them.

- Updates shows the new version number when one is waiting.
- Publishing shows how many changes are queued, and only exists on the hub, so a
  client site gets a shorter row and no badges.
- render_nav() builds it from bar_items(), the same list the admin bar uses, so
  a new page appears in the menu, the admin bar and the banner at once.
- It hides itself when a plugin has fewer than two pages.


### After an update

Updating a plugin swaps its files out mid request. If you were on one of its own
pages, the page you land on afterwards can still be running the old code, so its
menus never register and the plugin appears to have vanished until you navigate
somewhere else. Each plugin now clears the compiled copies of its own files on
upgrader_process_complete, which settles it.

The Update now button on each Updates page goes through update-core.php, the
bulk path the dashboard uses: maintenance mode on, files swapped, maintenance
mode off, plugin never deactivated. It used to go through update.php, the
single plugin path, which deactivates the plugin first and does not reactivate
it in PHP at all: the results page carries a hidden iframe that loads
update.php?action=activate-plugin, and that iframe is the reactivation. Leave
the page before it loads, or have anything block it, and the plugin stays off
with nothing in any log. That happened twice on a client site. Keep the bulk
path.


### What a client site must not carry

The hub is the blueprint new sites are built from, so whatever is in its database
travels with every copy. On any site that is not the hub, each plugin deletes its
GitHub token, its queued release notes and its release cache when an admin page
loads. A token has no business on a client site.

If you add anything else that only the hub should know, delete it there too.


### Unsaved changes, and the save button

Any form marked data-sb-dirty is watched, and every one of them also carries
autocomplete="off". Without it a browser puts unsaved values back into the
fields when the page is reloaded past the warning, and it does so after the
page has parsed: the button flickers while the script and the form disagree
about the baseline, and worse, the edits sit there on screen under a button
saying there is nothing to save. Reloading should show what is saved. The save button sits disabled reading
Nothing to save until something changes, then wakes up with its own wording and
an amber reminder appears top right and follows you down the page. Put the change
back the way it was and both go quiet. Leaving with something unsaved warns you.

Attributes a button can carry:

- data-sb-save: treat as a save button even though it is not a submit.
- data-sb-label-dirty: the wording to use when there is something to save, for a
  button whose resting label says there is nothing.
- data-sb-always-on: never disable this one. Used for buttons that do work
  rather than save, such as Full Rebuild, and for any submit that is an action
  rather than a save, such as Reset to defaults.
- data-sb-idle=1: nothing to run right now, so sit inactive until there is.

The reminder saves with the button that actually saves: one marked data-sb-save,
then the primary button, and only then the first submit in the form. A form can
hold more than one submit and not all of them save. The image sizes form has
Reset to defaults sitting above Save changes, and the reminder used to submit
whichever came first, so clicking it reset the sizes rather than saving them.
Worth remembering when adding any second submit to a form.

Styling: .sb-save--clean is a grey outline on transparent, .sb-save--dirty fills
with the admin colour scheme accent, white text, so the thing to press is the
only solid button on the page. The accent is taken down a shade with
color-mix( in srgb, var(--prefix-accent) 76%, #000 ): the SocialBUMP green is
too bright at full strength and every other scheme reads better slightly
darker. The flat var() is declared first as a fallback. The reminder stays pale
yellow: it is a notice, not a button, and the two should not read as the same
thing.

The button is rendered already wearing sb-save--clean, in the core file's save
bar, rather than left to the script. The script is enqueued in the footer, so a
button that starts life as an ordinary live primary one flashes the accent
colour on every page load before settling to its resting state. The other two
plugins had exactly that and now pass 'primary sb-save--clean' to
submit_button(). Both selectors lead with
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
- Text toggle links: sb-toggle on the link, sb-toggles on a pair's wrapper.
  Select all and Select none, Collapse all, Expand all, Collapse disabled, the
  all and none pairs on the Image Cleaner: all the same look, all defined once,
  so a new one never has to be styled again. The colour is the admin scheme
  accent taken down to 72 per cent against black, and hover goes to 42, which is
  a change you can actually see on any scheme. A wrapper sits its pair at the
  right, where the Modules links have always been.
- Menu icon: the SocialBUMP exclamation, this plugin's own copy of it, built in
  SBAIKE_Admin::menu_icon(). It came from the shared class the other two still
  use, so if the mark ever changes it has to be changed here as well. The menu
  positions itself next to the other SocialBUMP plugins when they are present.
- WordPress does not recolour an SVG menu icon. It only recolours Dashicons,
  which are a font. An SVG given as a menu icon becomes a background image and
  keeps whatever colour is baked into it, so ours is white and the dimming when
  idle, and the brightening on hover, are done in CSS to match the icons around
  it. Build the SVG by concatenation with chr( 34 ): a quote mangled in the
  middle of it produces markup that silently draws nothing.
- Publishing lays out as two columns: the token and the zip stacked on the left,
  publishing beside them. The cards are placed with CSS grid rather than
  reordered, so the markup and the reading order stay as they are.
- The accent comes from the admin colour scheme, chosen by saturation so a
  washed out swatch is never picked, and exposed as --prefix-accent.


### Releasing

Everything is developed and released on the hub, plugins.socialbump.com.au. Each
plugin decides it is on the hub by host name, and only then loads its release
code and shows a Publishing page.

- Publishing pushes the code to GitHub, builds a zip, creates a release and
  attaches the zip. Sites update through the plugin update checker.
- One fine grained GitHub token per plugin, stored encrypted, scoped to that one
  repo with Contents read and write. A token cannot create repositories, so a new
  repo is made by hand first.
- The notes box fills from sbaike_log_change() calls made since the last release,
  and the list empties once a release goes out. Call it after any change worth
  telling someone about, in their words rather than yours.
- Only log what a client site would notice. The Hub page, the Publishing page and
  anything else that exists only on the hub never reach a client site, so a change
  to them earns no note and no release of its own. It rides along with the next
  real one. A release exists to tell other sites something changed for them.
- Publishing retries on a 5xx, checks the zip actually attached, and checks again
  before undoing anything, because GitHub has published a release and then failed
  the response.
- The first release may carry the version already in the files. Every release
  after that has to be higher than the last.
- A version needs all three parts, so 1.1 is padded to 1.1.0 when you leave the
  field, and again on save in case the form never lost focus. Typing 1.1 used
  to get you the browser complaining about a pattern it does not explain.
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

The hub is plugins.socialbump.com.au, and the plugins are in the usual place:
wp-content/plugins/<folder>/. Client sites each have their own connector and the
same folder structure.

The file by file account of this plugin is under The files, and what each one is
for, further up these notes. Nothing in includes/ is shared with another plugin
any more: the two class-socialbump-*.php files that used to sit there are gone,
and SBAIKE_Admin_Bar replaced the shared bar class.


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
7. Call sbaike_log_change() on the hub, so the work appears in the next release.

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

- **form.requestSubmit() only accepts a real submit button.** Pass it a button
  with type=button, which is what data-sb-save allows, and the browser throws
  and nothing is sent, while the button and the reminder both look exactly
  right. SEO for AI's save button is type=button, so its settings pages
  silently stopped saving. save-state.js now passes the button only when its
  type is submit, and otherwise calls requestSubmit() with nothing. Found on
  21 September 2026.
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
- opcache_invalidate() only reaches the PHP process it runs in. On a LiteSpeed
  host with opcache.revalidate_freq set to 60, every other process keeps running
  the old file for up to a minute after a write. A rebuild started in that
  window ran half on old code and half on new, and stamped the cache both ways.
  A fresh request is not proof until a minute has passed, and nothing that
  writes stamps or data formats should be exercised in that minute.
- Three plugins carrying the same shared file means whichever loads first
  declares the class, and the others must not declare it again. Bricks Tweaks
  sorts before Site Kit, so when it started loading the cards class at the usual
  time it declared it first, and an older published Site Kit whose copy had no
  guard declared it again and killed two live sites. The lesson is not the
  guard, which was already there: it is that the guard only helps in the copy
  that has it, and published sites run old copies for months. A plugin that
  sorts early loads a shared class last, on plugins_loaded at a late priority,
  so the oldest copy present goes first and there is nothing to clash with.
  This plugin no longer carries any of those files, which is the permanent fix
  for it here: there is nothing left to clash with.
- The error log is the fastest way to the truth and was checked third rather
  than first during that outage, after two confident wrong explanations. Read
  the log before forming a theory.

## Hub reporter

includes/class-socialbump-reporter.php is shared by every SocialBUMP plugin (Site
Kit, Bricks Tweaks, SEO for AI, SocialBUMP Tweaks): the same file in each, kept
identical like the shared admin bar, and guarded by class_exists so whichever
loads first runs. It is required from the plugin's main file at the top level,
not on plugins_loaded, so it is already listening when a plugin is activated.

It reports every tracked plugin on the site at once, active or not, since a
deactivated plugin cannot speak for itself: site URL and name, each plugin's
version and active state, WordPress and PHP versions. Nothing else. It posts to
https://plugins.socialbump.com.au/wp-json/sb-tweaks/v1/checkin with the shared
X-SB-Key header, non-blocking with a 3 second timeout, so it never slows a page.
It sends on activated_plugin and deactivated_plugin for one of ours, on an admin
page load when the plugin list changed or a day has passed (option
socialbump_reporter_last holds the time and a hash), and from the daily cron
event socialbump_reporter_daily for sites nobody logs into. upgrader_process_
complete clears the last report so the next admin load sends the new version.
On the hub it calls sb_tweaks_installs_record() directly instead of over HTTP.

To track another plugin, add its folder to SocialBUMP_Reporter::PLUGINS in every
copy and a label to SB_Tweaks_Installs::LABELS, and bump the reporter VERSION.

Reporter 1.0.1: WordPress fires deactivated_plugin before it saves the new
active_plugins list, so reading the list then still showed the plugin as active.
deactivated() sends with that plugin forced inactive (send() takes an override).
activated_plugin fires after the save, so activation needs no such help.

## Hub moved (September 2026)

The hub moved from bricks.socialbump.com.au to plugins.socialbump.com.au, so the
Bricks blueprint can stay clean for starting new sites. The site was copied with
Duplicator, which kept the WordPress security keys, so the encrypted GitHub tokens
were copied across as they were. Every hub address in the plugins (the *_HUB_HOST
constants, SocialBUMP_Reporter::ENDPOINT and HUB_HOST, reporter 1.0.2, the docs and
the AI prompts) now names plugins.socialbump.com.au. Watch for this on any future
move: a copy of the hub on a new address is not the hub until the code says so, and
the first admin page load there runs each plugin's tidy-up, deleting the GitHub
token, the queued release notes and the latest release record. Sites keep
reporting to the old address until they update to a release naming the new one.

## Pushed updates (reporter 1.1.0)

The Installs page can push a release to a site. SB_Tweaks_Push (hub only) signs
an instruction with the hub's Ed25519 private key, sb_tweaks_push_secret, stored
encrypted with wp_salt('auth') like the GitHub tokens (the public half is
sb_tweaks_push_public). The instruction names the host, the plugin, the version,
the download URL, an expiry five minutes out and a random 32 character nonce. It
is posted to the site at ?rest_route=/socialbump/v1/update, which works whatever
the permalink setting.

Every reporter since 1.1.0 carries the public key (PUSH_KEY) and registers that
route. receive() refuses anything that is not signed by the hub, not addressed
to this host, expired or more than ten minutes ahead, already used (nonces kept
as socialbump_push_<nonce> transients for fifteen minutes), not one of PLUGINS,
not exactly https://github.com/frysteen/<slug>/releases/download/v<version>/<slug>.zip,
or not newer than what is installed. It then runs Plugin_Upgrader::run() with
Automatic_Upgrader_Skin and WordPress's temp_backup rollback, only when the
filesystem method is direct, clears opcache for the plugin, reports, and replies
with the new version. The version and URL come from the hub's own copy, so the
site never asks GitHub's API, which is what the 60 an hour limit applies to;
release file downloads are not limited that way.

The hub records each site's reporter version and only shows Update buttons on
sites at 1.1.0 or later (SB_Tweaks_Push::NEEDS). Update all pushes one plugin at
a time. Tested September 2026: every refusal case, then a real push of Site Kit
1.1.20 to bricks.socialbump.com.au in 8.3 seconds with settings and activation
kept. A site whose security plugin blocks outside REST requests answers with an
error the button shows as is.
