=== BLT Optimized ===
Contributors: sfxcom
Tags: disk usage, database optimization, cleanup, orphaned data, autoload, webp, image optimization
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 8.0
Stable tag: 1.1.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Disk-usage forensics and database optimization: folder-by-folder wp-content breakdown, orphaned data cleanup, and table optimization.

== Description ==

The thing that actually eats a shared-hosting quota is almost never "too many post revisions" — it's what's sitting in wp-content: backup archives nobody deleted, cache directories from a caching plugin swapped out years ago, a debug.log growing since 2023, or a leftover folder from a plugin removed via FTP.

BLT Optimized's headline feature is the **disk-usage forensics layer** — an actual folder-by-folder size breakdown of wp-content, not just a database nag:

* Recursive scan of wp-content with drill-down per branch: uploads by year/month, per-plugin folder sizes, per-theme sizes
* Known space-hog detection: backup plugin archives (UpdraftPlus, All-in-One WP Migration, Duplicator, BackWPup), orphaned cache directories, unbounded log files, deployed .git / node_modules directories
* Orphaned inactive-plugin folder detection — folders with no matching installed plugin, flagged only, never auto-deleted
* Batched, resumable scanning built for cheap shared hosting: time-boxed ticks, `du -sb` when exec() is available, pure-PHP fallback when it isn't
* Top 20 space hogs panel, sortable expandable folder tree, CSV export
* Manual "Scan Now" plus scheduled weekly/monthly auto-scan

Database cleanup and optimization are included as the secondary feature:

* Orphaned postmeta, usermeta, commentmeta, term relationships, and terms
* Expired and orphaned transients, oEmbed cache, expired session tokens
* Post revisions beyond a configurable retention count; old trashed posts and spam/trashed comments
* Leftover tables from uninstalled plugins — flagged for review, never auto-dropped
* OPTIMIZE TABLE with engine detection (large InnoDB tables warn before running)
* Autoloaded options audit: total autoload payload, top 20 largest options, anything over 100 KB flagged

Safety guardrails:

* Dry-run is the default state for every cleanup — nothing deletes until explicitly confirmed
* Every destructive action requires manage_options + nonce; nothing destructive runs off a GET request
* Backup acknowledgment before the first bulk deletion in a session
* Full exportable audit log: who ran what, when, and how much was reclaimed
* Admin-editable exclusion list for anything that should always be skipped

Works standalone with zero external dependency.

= Optional: image optimization =

BLT Optimized also ships an **optional image-optimization module** (off by default) that permanently optimizes images on disk — compression + WebP conversion — by routing them through a self-hosted Cloudflare Worker:

* Auto-optimizes new uploads and bulk-processes the existing media library (Action Scheduler queue with pause/resume/cancel)
* Writes optimized `.webp` files next to the originals and rewrites front-end URLs/srcset to serve them (Bricks Builder aware)
* Once optimized, images are just files on disk — the module, and the Worker, can be turned off with no image regression (agency hand-off model)

Enable it under **BLT Optimized → Settings → Image optimization**. It requires a Cloudflare Worker deployed to a real **zone route** (not a `*.workers.dev` subdomain — `cf.image` transforms are unavailable there); configure the Worker URL and shared secret under **Image Settings**, and use **Test Connection** to confirm the Worker reports `cf.image` availability. When the module is left off, the disk/DB core remains fully standalone with zero external dependency.

== Installation ==

1. Upload the `blt-optimized` folder to `/wp-content/plugins/`.
2. Activate the plugin through the Plugins screen.
3. Go to BLT Optimized → Disk Usage and click "Scan Now".

== Frequently Asked Questions ==

= Will it delete my backup archives? =

No. Backup archives are surfaced as their own flagged category and are never swept into a bulk delete. The plugin flags them for your review.

= Does it work on hosts where exec() is disabled? =

Yes. The scanner feature-detects exec()/du and falls back to a pure-PHP recursive scan on hosts (SiteGround, GoDaddy, Pressable shared plans) where exec() is disabled.

= Will a scan time out on shared hosting? =

Scans run in time-boxed batches and the partial state is persisted between ticks, so a scan resumes after a timeout, deploy, or server restart without starting over.

== Changelog ==

= 1.1.3 =
* Image optimization is now easier to find: when the module is off, an admin notice on the plugin's screens links straight to the toggle (dismissible per user).
* When the module is on, its Image Optimizer, Image Settings, and Image Log pages now appear in the shared tab strip alongside the disk/DB pages. The module remains off by default.

= 1.1.0 =
* New optional image-optimization module (merged in from the former standalone Blt Image Optimizer plugin): compress + WebP conversion via a self-hosted Cloudflare Worker, auto-optimize on upload, bulk runner, and front-end WebP URL/srcset rewriting.
* The module is off by default and toggled under Settings → Image optimization; the disk/DB core stays standalone with zero external dependency when it is off.
* Bundled the image Cloudflare Worker under `worker/` for deployment.

= 1.0.1 =
* Disk Usage: the whole folder row is now clickable to expand, not just the small caret.
* Disk Usage: added a "Top 20 largest files" panel alongside the top space hogs.
* Database Cleanup: reworked the previews into a bulk-action list table with per-row counts, sizes, and a details view.
* Settings: the plugin can now be shown as a top-level menu, under the Tools menu, or both.

= 1.0.0 =
* Initial release: disk usage scanner, orphaned data cleanup, database optimization, audit log.
