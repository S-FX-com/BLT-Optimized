=== BLT Optimized ===
Contributors: sfxcom
Tags: disk usage, database optimization, cleanup, orphaned data, autoload, webp, image optimization
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 8.0
Stable tag: 1.2.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Performance diagnostics, disk-usage forensics and database cleanup, with findings that name the plugin, theme, file or table responsible.

== Description ==

BLT Optimized reports what is slowing a WordPress site down or using up its storage, and names the plugin, theme, file, table, URL or post responsible. Where a fix is safe to apply, it can be applied with one click.

= Performance Scan =

The landing screen. It scores the site from 0 to 100 for each category (Server & PHP, Database, Scripts & styles, Content and Storage) and overall, and lists findings in order of impact. Every finding has a severity (critical, warning, notice or pass), a category, an impact in milliseconds or bytes, the responsible plugin, theme, file, table, URL or post, where to look, an evidence table, and a fix. Findings can be dismissed, and the report can be exported as CSV for agency hand-off.

* Front-end profiler: samples the front page, the latest post and page, a category archive, the shop and a product page (when WooCommerce is active), search, and up to five extra URLs you set. It records PHP time, every database query with the plugin or theme that ran it, plugin and theme load times, outbound HTTP calls, PHP notices and warnings, transient writes, and enqueued scripts and styles with their file sizes. Ordinary visitor requests only run a single token check.
* Page analysis: oversized images, images missing width and height, legacy image formats, heavy embeds (YouTube, Vimeo, maps), DOM size, inline CSS and JavaScript, third-party origins, render-blocking scripts, page-cache and compression detection, and static asset cache headers. A problem inside a post's content names the post and links to its editor.
* Server and database checks: PHP version, OPcache, object cache, WP_DEBUG and SAVEQUERIES in production, memory limit, WP-Cron reliability, autoload size (with each oversized option and a guess at its owner), transients, orphaned cron hooks, Action Scheduler bloat and stuck queues, revisions, postmeta bloat by meta key, oEmbed cache, table overhead, MyISAM tables and large plugin tables.
* Media storage audit, resumable and time-boxed: orphaned files in uploads folders, thumbnails of image sizes that are no longer registered, pre-scaled original images, the largest images, and unattached and duplicate attachments (reported only, never deleted).
* Optional Google PageSpeed Insights, off by default: Lighthouse lab metrics (LCP, CLS, TBT, FCP, Speed Index, TTFB), real-user field data when available, the LCP element, and unused JavaScript and CSS attributed to the plugin, theme or third party responsible. It sends each tested page URL to Google, so only public sites can be tested, and in practice a Google API key is needed because the keyless quota is usually exhausted.

One-click fixes, where a fix is safe to apply: switch on a speed tweak; add asset rules (for example, load Contact Form 7's scripts only on pages that contain a form); stop autoloading an oversized option (reversible); unschedule an orphaned cron hook; run a cleanup category (revisions, Action Scheduler); delete orphaned upload files, stale thumbnails or pre-scaled originals.

= Page Profiler =

Profile any URL on demand, with Query Monitor-style tabs: Components, Queries, Assets, HTTP calls, PHP errors, Content, Timeline and a raw JSON download. An optional A/B run measures the page with every speed tweak switched off, to show what the tweaks change. PageSpeed Insights can also be run on demand.

= Speed Tweaks =

Every tweak is off by default:

* Disable emojis, oEmbed discovery and embeds, and self-pingbacks
* Remove jQuery Migrate on the front end, and Dashicons for logged-out visitors
* Defer JavaScript, using the WordPress 6.3+ script-strategy API (WordPress refuses unsafe cases itself), with an exclusion list
* WooCommerce cart fragments on shop pages only
* Heartbeat turned off on the front end, or slowed in the admin
* Cap post revisions, and preconnect to chosen origins

Asset rules dequeue a specific script or stylesheet everywhere, or everywhere except where conditions match: front page, post type, post IDs, WooCommerce pages, post content containing text (such as a shortcode), or a URL containing text. Any option whose autoload a fix switched off can be restored from the reversible-changes list.

Safe mode: an administrator can add ?blt_optimized_safe=1 to any URL to see that page with every tweak and asset rule switched off. Visitors cannot use it.

= Disk usage forensics =

The thing that actually eats a shared-hosting quota is almost never "too many post revisions" — it's what's sitting in wp-content: backup archives nobody deleted, cache directories from a caching plugin swapped out years ago, a debug.log growing since 2023, or a leftover folder from a plugin removed via FTP.

BLT Optimized's disk-usage forensics layer is an actual folder-by-folder size breakdown of wp-content, not just a database nag:

* Recursive scan of wp-content with drill-down per branch: uploads by year/month, per-plugin folder sizes, per-theme sizes
* Known space-hog detection: backup plugin archives (UpdraftPlus, All-in-One WP Migration, Duplicator, BackWPup), orphaned cache directories, unbounded log files, deployed .git / node_modules directories
* Orphaned inactive-plugin folder detection — folders with no matching installed plugin, flagged only, never auto-deleted
* Batched, resumable scanning built for cheap shared hosting: time-boxed ticks, `du -sb` when exec() is available, pure-PHP fallback when it isn't
* Top 20 space hogs panel, sortable expandable folder tree, CSV export
* Manual "Scan Now" plus scheduled weekly/monthly auto-scan

= Database cleanup and optimization =

* Orphaned postmeta, usermeta, commentmeta, term relationships, and terms
* Expired and orphaned transients, oEmbed cache, expired session tokens
* Post revisions beyond a configurable retention count; old trashed posts and spam/trashed comments
* Old Action Scheduler actions and logs: completed, failed and canceled actions older than 30 days. Shown only when the Action Scheduler tables exist.
* Leftover tables from uninstalled plugins — flagged for review, never auto-dropped
* OPTIMIZE TABLE with engine detection (large InnoDB tables warn before running)
* Autoloaded options audit: total autoload payload, top 20 largest options, anything over 100 KB flagged

= Safety guardrails =

* Dry-run is the default state for every cleanup — nothing deletes until explicitly confirmed
* Every destructive action requires manage_options + nonce; nothing destructive runs off a GET request
* Backup acknowledgment before the first bulk deletion in a session; Performance Scan fixes that delete files or rows use the same acknowledgment
* Destructive Performance Scan fixes are validated against the latest audit results on the server, re-checked right before deletion, and written to the audit log
* Full exportable audit log: who ran what, when, and how much was reclaimed
* Admin-editable exclusion list for anything that should always be skipped

The Performance Scan, disk scanner and database cleanup work standalone with no external service. The optional PageSpeed Insights check is the only part of the plugin that sends URLs to Google.

= Optional: image optimization =

BLT Optimized also ships an **optional image-optimization module** (off by default) that permanently optimizes images on disk — compression + WebP conversion — by routing them through a self-hosted Cloudflare Worker:

* Auto-optimizes new uploads and bulk-processes the existing media library (Action Scheduler queue with pause/resume/cancel)
* Writes optimized `.webp` files next to the originals and rewrites front-end URLs/srcset to serve them (Bricks Builder aware)
* Once optimized, images are just files on disk — the module, and the Worker, can be turned off with no image regression (agency hand-off model)

Enable it under **BLT Optimized → Settings → Image optimization**. It requires a Cloudflare Worker deployed to a real **zone route** (not a `*.workers.dev` subdomain — `cf.image` transforms are unavailable there); configure the Worker URL and shared secret under **Image Settings**, and use **Test Connection** to confirm the Worker reports `cf.image` availability. When the module is left off, the disk/DB core remains fully standalone with zero external dependency.

== Installation ==

1. Upload the `blt-optimized` folder to `/wp-content/plugins/`.
2. Activate the plugin through the Plugins screen.
3. Go to BLT Optimized → Performance Scan and click "Run full scan". The scan advances while that page stays open. For a folder-by-folder view of `wp-content`, go to BLT Optimized → Disk Usage.

== Frequently Asked Questions ==

= Will it delete my backup archives? =

No. Backup archives are surfaced as their own flagged category and are never swept into a bulk delete. The plugin flags them for your review.

= Does it work on hosts where exec() is disabled? =

Yes. The scanner feature-detects exec()/du and falls back to a pure-PHP recursive scan on hosts (SiteGround, GoDaddy, Pressable shared plans) where exec() is disabled.

= Will a scan time out on shared hosting? =

Scans run in time-boxed batches and the partial state is persisted between ticks, so a scan resumes after a timeout, deploy, or server restart without starting over.

== Changelog ==

= 1.3.0 =
* New Performance Scan landing screen: a score from 0 to 100 for Server & PHP, Database, Scripts & styles, Content and Storage, plus an overall score. Each finding names the responsible plugin, theme, file, table, URL or post, and gives its severity, impact, evidence and a fix. Findings can be dismissed and exported to CSV.
* Front-end profiler that samples real pages through loopback requests, recording PHP time, every database query with the plugin or theme that ran it, plugin and theme load times, outbound HTTP calls, PHP notices, transient writes and enqueued assets. Ordinary visitor requests only run a single token check.
* Page analysis (images, embeds, DOM size, inline and render-blocking code, third-party origins, cache and compression detection), server and database health checks, and a resumable, time-boxed media storage audit.
* Optional Google PageSpeed Insights, off by default: Lighthouse lab metrics, field data when available, the LCP element, and unused code attributed per plugin, theme or third party.
* Page Profiler screen with Query Monitor-style tabs, a raw JSON download, an optional A/B run with speed tweaks switched off, and on-demand PageSpeed Insights.
* Speed Tweaks screen, all off by default: emoji, oEmbed and self-pingback controls, jQuery Migrate and logged-out Dashicons removal, JavaScript deferral, WooCommerce cart fragments on shop pages only, Heartbeat control, a post revision cap, and preconnect. Asset rules dequeue scripts and styles everywhere or except where conditions match. Administrators can use ?blt_optimized_safe=1 to see a page with every tweak switched off.
* One-click fixes for enabling tweaks, adding asset rules, stopping autoload on oversized options, unscheduling orphaned cron hooks, running cleanup categories and deleting orphaned upload files. Destructive fixes require the backup acknowledgment, are checked against the latest audit before deletion, and are written to the audit log.
* Database Cleanup: new category for old Action Scheduler actions and logs, shown only when the Action Scheduler tables exist.
* The BLT Optimized menu opens on Performance Scan. Disk Usage has its own page, and Optimization is renamed DB Optimization. Settings gains a Performance scan card.
* Speed Tweaks settings are stored in one autoloaded option (blt_optimized_tweaks). The other new options are not autoloaded.

= 1.2.0 =
* Adopted the shared BLT admin design system across every screen: consistent page headers, cards, field rows, toggles, status badges and stat tiles.
* The admin menu now carries the BLT mark instead of a generic dashicon.
* Update checks now follow the BLT family policy: at most one automatic check a day, anchored to midnight site time, with manual checks always immediate. Settings gained a "Check for Updates" action and the time of the last check.
* Joined the BLT shared settings layer. On a site running another BLT plugin, the GitHub update token and the image Worker URL/secret can be entered once and shared — opt-in per plugin, off by default, and this plugin's own settings always win.

= 1.1.3 =
* Image optimization is now easier to find: when the module is off, an admin notice on the plugin's screens links straight to the toggle (dismissible per user).
* When the module is on, its Image Optimizer, Image Settings, and Image Log pages now appear in the shared tab strip alongside the disk/DB pages. The module remains off by default.

= 1.1.0 =
* New optional image-optimization module (merged in from the former standalone BLT Image Optimizer plugin): compress + WebP conversion via a self-hosted Cloudflare Worker, auto-optimize on upload, bulk runner, and front-end WebP URL/srcset rewriting.
* The module is off by default and toggled under Settings → Image optimization; the disk/DB core stays standalone with zero external dependency when it is off.
* Bundled the image Cloudflare Worker under `worker/` for deployment.

= 1.0.1 =
* Disk Usage: the whole folder row is now clickable to expand, not just the small caret.
* Disk Usage: added a "Top 20 largest files" panel alongside the top space hogs.
* Database Cleanup: reworked the previews into a bulk-action list table with per-row counts, sizes, and a details view.
* Settings: the plugin can now be shown as a top-level menu, under the Tools menu, or both.

= 1.0.0 =
* Initial release: disk usage scanner, orphaned data cleanup, database optimization, audit log.
