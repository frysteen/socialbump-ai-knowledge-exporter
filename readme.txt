=== SocialBUMP SEO for AI ===
Contributors: socialbump
Tags: llms.txt, ai, seo, acf
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.0.5
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Generates AI-friendly llms.txt knowledge exports from WordPress content, custom fields and supported page builders.

== Description ==

SocialBUMP SEO for AI generates llms.txt, llms-full.txt and llms-details.txt files for a WordPress website.

The plugin is organised as a core exporter with separate extensions for rendered content, Bricks Builder and Elementor. The exporter is the core, with separate modules for rendered content, Bricks Builder and Elementor. Each module detects what it needs and steps in on its own.

== Installation ==

1. Upload and activate the plugin.
2. Go to SB SEO for AI in the admin menu.
3. Configure the content sources and output mode.
4. Generate the knowledge files.

== Changelog ==

= 1.0.5 =
* Clearing the whole cache now goes through the WordPress meta API, so a site with a persistent object cache no longer keeps serving the old cached content after a Full Rebuild.
* Changing the ACF options fields, business details, taxonomies or which post types are exported no longer marks every post as needing a re-render. Only the strip selectors do that now, since they are the one setting that changes what is cached.
* Update Files now runs through the same progress window as Full Rebuild, a few posts at a time, instead of doing everything in one request that could time out.
* The progress window shows how long the job has been running, puts the post type in bold, and shows the result inside the window with a Close button rather than as a notice after the page reloads.
* Update Files and Full Rebuild refuse to start while there are unsaved settings, rather than losing them when the page reloads.
* After updating, existing cached content is kept and re-stamped once, so the update itself does not trigger a full re-render.
* Buttons that sit next to each other in a button group come out on separate lines instead of running into one word.
* Card grids, where each card is a heading with a link and a short description, now render as one line per card: a bold link followed by the description.
* The hero of a page no longer repeats the title, subtitle and summary that the file already lists above the content.
* HTML tables are rendered as tables, one row per line with the cells separated, rather than the cells running together.
* Saving a Bricks template now marks every post it applies to as needing an update, so a template edit no longer leaves the export out of date while everything looks current. Header, footer, popup and section templates still need a Full Rebuild.

= 1.0.4 =
* The banner now lists every page in the plugin, so you can move between them without going back to the admin menu. Updates shows a waiting version and Publishing shows how many changes are queued.

= 1.0.3 =
* A SocialBUMP Hub page gathers every plugin on the site, with one place to publish them all from. It only appears on the publishing hub.
* Menus now carry the SocialBUMP mark, and publishing lays out in two columns instead of three stretched cards.
* Updating no longer leaves the plugin missing from the menus until you navigate away.
* A site that is not the publishing hub now clears the GitHub token and release notes it has no use for.

= 1.0.2 =
* Rebuilding now runs three requests at once, which roughly halves a full rebuild: 177 posts went from 246 seconds to 133.
* The progress panel names the post type as well as the post, so a long rebuild says where it has got to.

= 1.0.1 =
* Renamed to SocialBUMP SEO for AI throughout.
* Page headings now read the plugin name followed by the page you are on.
* Admin bar marks the page you are on plainly, rather than in the admin colour scheme accent, which reads badly in some schemes.
* An action in the admin bar with nothing to do now looks inactive, and one with work waiting stands out.
* The plugin now carries its own notes at docs/context.md, and they can be read and edited on the Publishing page.
* ACF Field Visibility can now leave a field out of both files at once, not just one.
* The Content page marks any field a visibility rule affects, and a field left out of both cannot be ticked, since it would not be exported anyway.
* The plugin notes now describe every panel and setting in detail, not just the overall shape.

= 1.0.0 =
* First release as a plugin, moved out of WP CodeBox.
* Settings split across Content, Business and Settings pages, in the SocialBUMP house style.
* Rebuilds run in batches with a progress bar, so a large site no longer hangs or times out part way through.
* Status pills show what is current and what needs updating, and double as the button that updates it.
* A page that has changed can be caught up on its own, without rebuilding the rest.
* Settings can be exported to a file and imported on another site.
* Updates delivered from the hub through GitHub releases.
* The matching WP CodeBox snippets are switched off automatically, so the two copies can never clash.

= 1.0.0 =
* First release as a plugin, moved out of WP CodeBox.
