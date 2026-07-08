=== BLT Optimized ===
Contributors: sfxcom
Tags: disk usage, database optimization, cleanup, orphaned data, autoload
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 8.0
Stable tag: 1.0.1
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

= 1.0.0 =
* Initial release: disk usage scanner, orphaned data cleanup, database optimization, audit log.
