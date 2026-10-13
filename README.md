# BLT Optimized

WordPress performance diagnostics, disk-usage forensics and database cleanup by [S-FX.com](https://www.s-fx.com).

BLT Optimized reports what is slowing a WordPress site down or using up its storage, and names the plugin, theme, file, database table, URL or post responsible. Where a fix is safe to apply, it can be applied with one click. The plugin covers three areas:

- **Performance Scan** (v1.3): scored, prioritised findings for the front end, the server, the database and the media library, with an optional Google PageSpeed Insights check.
- **Disk-usage forensics** (v1.0): a real folder-by-folder breakdown of `wp-content`.
- **Database cleanup and optimization** (v1.0): orphaned data, expired transients, excess revisions, old trashed content and table optimization.

Shared-hosting quota is often used by things sitting in `wp-content`: backup archives nobody deleted, cache directories from a caching plugin swapped out years ago, a `debug.log` growing for years, or a folder left over from a plugin removed via FTP. The disk-usage layer is built to find those.

The Performance Scan, the disk scanner and database cleanup need no external service. Two optional features do contact one: the PageSpeed Insights check (off by default, sends page URLs to Google) and the image module (a Cloudflare Worker).

## Optional: image-optimization module (v1.1)

BLT Optimized also bundles an **optional image-optimization module** (off by default), merged in from the former standalone *BLT Image Optimizer* plugin. It permanently optimizes images on disk — compression + WebP conversion — by routing them through a self-hosted Cloudflare Worker (`worker/`), then rewrites front-end URLs/srcset to serve the `.webp`.

- Enable under **Settings → Image optimization** (setting key `enable_images`). When off, no image code runs and no image table/options are created — the disk/DB core keeps its zero-dependency guarantee.
- Self-contained under the `BltImageOptimizer\` namespace in `includes/images/` + `admin/images/`, with its own log table (`{prefix}blt_optimizer_log`) and settings option (`blt_optimizer_settings` — note: one letter off from the core's `blt_optimized_settings`, kept intentionally distinct).
- Requires the Worker deployed to a real **zone route** (not `*.workers.dev` — `cf.image` transforms are unavailable there). Configure the Worker URL + shared secret under **Image Settings** and use **Test Connection** to verify.
- Hand-off safe: optimized `.webp` files and their `_blt_webp_sizes` / `_blt_optimized` postmeta are preserved on uninstall, so the site keeps serving WebP after the plugin (and Worker) are gone.

## Performance Scan (v1.3)

The Performance Scan is the landing screen of the BLT Optimized menu. It scores the site from 0 to 100 for each category — Server & PHP, Database, Scripts & styles, Content and Storage — and overall, and lists findings in order of impact.

Every finding has:

- a **severity**: critical, warning, notice or pass
- a **category** and an **impact**, in milliseconds or bytes
- the **responsible component**: the plugin, theme, core or external origin, with the file, table, URL or post involved
- **where to look**, and an **evidence** table of the measurements behind it
- a **fix**: a one-click action, a link, or manual steps

Findings can be dismissed. The report can be exported as CSV for agency hand-off.

### What a scan measures

**Front-end profiler.** The scan loads a sample of real pages through loopback requests: the front page, the latest post, the latest page, a category archive, the shop and a product page when WooCommerce is active, a search results page, and up to five extra URLs set under **Settings → Performance scan**. Each request carries a single-use token. Within that one request the profiler records:

- PHP time, broken down by phase
- every database query, with the plugin or theme that ran it (slow and duplicate queries are flagged)
- plugin and theme load times
- outbound HTTP calls made while rendering
- PHP notices and warnings, per plugin
- transient writes
- enqueued scripts and styles, with file sizes

Ordinary visitor requests pay a single `isset()` check. The profiler's collector code only loads while a profile is running.

**Page analysis.** Each sampled page's HTML is also checked for oversized images, images missing `width`/`height`, a lazy-loaded hero image, legacy image formats, heavy embeds (YouTube, Vimeo and map iframes), DOM size, inline CSS and JavaScript, third-party origins, render-blocking scripts, page-cache and compression detection, and static asset cache headers. When a problem sits inside a post's content, the finding names the post and links to its editor.

**Server and database checks.** PHP version, OPcache, persistent object cache, `WP_DEBUG` and `SAVEQUERIES` in production, memory limit, WP-Cron reliability and overdue events, autoload size (with each oversized autoloaded option and a guess at its owner), transients, cron option size, orphaned cron hooks (scheduled with nothing to handle them), Action Scheduler bloat and stuck queues, revisions, postmeta bloat by meta key, oEmbed cache, table overhead, MyISAM tables and large plugin tables. Items flagged by the last disk scan are imported here too.

**Media storage audit.** Resumable and time-boxed. It finds:

- orphaned files in `uploads/YYYY/MM` folders that no attachment owns and nothing references
- thumbnails of image sizes that are no longer registered
- pre-scaled original images
- the largest images
- unattached and unreferenced attachments, and duplicate uploads — reported only, never acted on

**PageSpeed Insights (optional, off by default).** Turn it on under **Settings → Performance scan**. It sends each tested URL to Google, so:

- the site must be public: local, staging, password-protected and maintenance-mode sites cannot be tested
- an API key is effectively required, because Google's keyless quota is usually exhausted

When enabled it adds Lighthouse lab metrics (LCP, CLS, TBT, FCP, Speed Index and TTFB), real-user field data when available, the LCP element, and unused JavaScript and CSS and other opportunities, attributed to the plugin, theme or third party responsible.

### One-click fixes

Where a fix is safe to apply, a finding offers it as a one-click action. Otherwise it offers a link or manual steps. One-click fixes include:

- switching on a speed tweak
- adding asset rules, for example loading Contact Form 7's scripts only on pages that contain a form (one click covers the plugin's whole asset family)
- stopping autoload for an oversized option (reversible from Speed Tweaks)
- unscheduling an orphaned cron hook
- running a cleanup category: post revisions, or Action Scheduler
- deleting orphaned upload files, stale thumbnails or pre-scaled originals (see [Safety](#safety))

## Admin screens

The BLT Optimized menu opens on Performance Scan. Tabs, in order:

1. Performance Scan (`blt-optimized`)
2. Page Profiler (`blt-optimized-profiler`)
3. Speed Tweaks (`blt-optimized-tweaks`)
4. Disk Usage (`blt-optimized-disk`)
5. Database Cleanup (`blt-optimized-cleanup`)
6. DB Optimization (`blt-optimized-db`), formerly "Optimization"
7. Audit Log (`blt-optimized-audit`)
8. Settings (`blt-optimized-settings`)

Settings gains a **Performance scan** card with the extra pages to scan (up to five), the PageSpeed Insights toggle and the API key.

### Page Profiler

Profile any URL on demand, with Query Monitor-style tabs: **Components**, **Queries**, **Assets**, **HTTP calls**, **PHP errors**, **Content**, **Timeline**, and a raw **JSON** download. An optional A/B run measures the page with every speed tweak switched off, so the effect of the tweaks can be seen. PageSpeed Insights can also be run on demand from this screen.

### Speed Tweaks

Every tweak is off by default:

- disable emojis
- disable oEmbed discovery and embeds
- disable self-pingbacks
- remove jQuery Migrate on the front end
- remove Dashicons for logged-out visitors
- defer JavaScript, using the WordPress 6.3+ script-strategy API, so WordPress itself refuses unsafe cases; an exclusion list covers the rest
- WooCommerce cart fragments only on shop pages
- Heartbeat turned off on the front end, or slowed down in the admin
- cap post revisions
- preconnect to chosen origins

**Asset rules** dequeue a specific script or stylesheet everywhere, or everywhere except where conditions match: the front page, a post type, post IDs, WooCommerce pages, post content containing text (such as a shortcode), or a URL containing text.

**Reversible changes** lists every option whose autoload a fix switched off, and lets you restore each one.

**Safe mode.** An administrator can open any page with `?blt_optimized_safe=1` to see it with every tweak and asset rule switched off. Visitors cannot use it.

## Features (v1.0)

### Disk usage scanner
- Breadth-first, **time-boxed, resumable** scan of `wp-content` — built to survive cheap shared hosting. Partial scan state persists between ticks, so a scan resumes after a timeout, deploy, or restart.
- `du -sb` fast path when `exec()` is available; pure-PHP `RecursiveDirectoryIterator` fallback when it isn't (SiteGround / GoDaddy / Pressable shared plans).
- Drill-down tree: `uploads` by year/month, per-plugin and per-theme folder sizes; `wp-admin` / `wp-includes` reported as single reference figures.
- Known space-hog signatures (filterable): UpdraftPlus / AI1WM / Duplicator / BackWPup archives, orphaned cache dirs, WooCommerce logs, Elementor assets, `.git`, `node_modules`, multisite `uploads/sites/*`.
- Notable-file detection: growing `debug.log` / `error_log` files, large backup archives.
- **Orphaned plugin-folder detection** — folders in `wp-content/plugins` with no matching installed plugin header. Flag only, never auto-deleted.
- Registered image-size crop multiplier reported (never auto-deletes generated sizes).
- Top 20 space hogs panel, CSV export, manual Scan Now + scheduled weekly/monthly auto-scan.
- The Performance Scan imports the items flagged by the last disk scan.

### Orphaned data cleanup
Dry-run preview → confirm → execute → logged, for: orphaned postmeta / usermeta / commentmeta / term relationships / terms, expired + orphaned transients, oEmbed cache, expired session tokens, excess revisions (configurable retention, default 5), old trashed posts and spam/trashed comments (configurable age, default 30 days). Leftover tables from uninstalled plugins are **flagged only — never dropped**.

Old Action Scheduler actions and logs (completed, failed and canceled actions older than 30 days, with the age filterable) join the same flow. This category only appears when the Action Scheduler tables exist.

### Database optimization
- `OPTIMIZE TABLE` with engine detection surfaced first; large InnoDB tables warn (rebuild + brief lock) instead of running silently.
- Autoloaded-options audit: total autoload payload, top 20 largest options, anything over 100 KB flagged.
- Remaining MyISAM tables flagged for InnoDB conversion.
- Before/after summary: DB size, top tables, autoload size.

### Safety
- Every destructive action: `manage_options` + nonce, POST only.
- Dry-run is the default state everywhere.
- Backup acknowledgment before the first bulk deletion in a session.
- Performance Scan fixes that delete files or rows use the same once-per-session backup acknowledgment. They are validated against the latest audit results on the server, re-checked right before deletion, and written to the audit log.
- Full exportable audit log (who, what, when, how much reclaimed).
- Hard exclusions (active theme/plugin folders, `wp-config.php`, `.htaccess`) plus an admin-editable exclusion list and `blt_optimized_exclude_paths` filter. Backup archives are never swept into a generic bulk delete.
- Media files can be protected from deletion through the `blt_optimized_media_is_referenced` filter.

## Hooks

| Hook | Type | Purpose |
| --- | --- | --- |
| `blt_optimized_before_scan` / `blt_optimized_after_scan` | action | Disk scan lifecycle |
| `blt_optimized_space_hog_signatures` | filter | Extend the known-signature list |
| `blt_optimized_before_cleanup` / `blt_optimized_after_cleanup` | action | Cleanup lifecycle |
| `blt_optimized_exclude_paths` | filter | Extend scanner exclusions |
| `blt_optimized_max_depth` | filter | Tree depth (default 4) |
| `blt_optimized_tick_budget` | filter | Seconds per disk scan tick (default 15) |
| `blt_optimized_before_perf_scan` / `blt_optimized_after_perf_scan` | action | Performance Scan lifecycle; the after hook receives the compiled report |
| `blt_optimized_perf_findings` | filter | Change findings before they are stored |
| `blt_optimized_perf_tick_budget` | filter | Seconds per Performance Scan tick (default 12) |
| `blt_optimized_sample_urls` | filter | Pages the Performance Scan profiles |
| `blt_optimized_asset_markers` | filter | Markup that proves a plugin or theme asset is in use |
| `blt_optimized_known_third_parties` | filter | Map of hosts (or host and path prefix) to third-party service names |
| `blt_optimized_table_owners` | filter | Map of table and option prefixes to plugin slugs |
| `blt_optimized_protected_options` | filter | Options that "Stop autoloading" must never touch |
| `blt_optimized_action_scheduler_retention_days` | filter | Days to keep finished Action Scheduler actions (default 30) |
| `blt_optimized_media_is_referenced` | filter | Return `true` to treat a media file as referenced, which vetoes its deletion |
| `blt_optimized_core_cron_hooks` | filter | Cron hook names treated as WordPress core, so they are not reported as orphaned |
| `blt_optimized_forward_basic_auth` | filter | Return `false` to stop the scan forwarding the admin's HTTP basic-auth credentials to its loopback requests (only ever sent to this site's own host; needed to scan password-protected staging sites) |
| `blt_optimized_skip_page_tweaks` | filter | Return `true` to switch speed tweaks and asset rules off for the current front-end request (e.g. a page builder's preview) |

## Architecture notes / decisions

- **Background processing:** Action Scheduler is used when present (`as_enqueue_async_action`, e.g. bundled with WooCommerce); otherwise chained WP-Cron single events. Interactive scans are additionally driven by AJAX polling from the open admin page, so they progress regardless. This resolves the "bundle vs. rely on Woo" open question in favor of *rely when present, degrade to WP-Cron* — no vendored copy to keep patched.
- **Performance Scan state machine:** the scan is resumable. It runs as a sequence of steps (health → media → pages → psi → compile), advanced in time-boxed ticks by the open admin page. Its state and partial results are kept in non-autoloaded options, so an interrupted scan picks up where it stopped.
- **Lazy-loaded performance classes:** the performance-scan classes (`BLT_Optimized_*`) are loaded on first use through an `spl_autoload_register()` autoloader. A front-end page view never parses the auditors, the findings rules or the PageSpeed client. The disk and database classes keep their explicit `require_once` calls.
- **Profiler gate and collector:** `class-blt-optimized-profiler.php` is loaded on every request, but on an ordinary request its `maybe_start()` only checks for a profile token. The collector, which records queries, HTTP calls, PHP notices, transient writes and enqueued assets, is loaded only for a request that carries a single-use token.
- **Component attribution:** `BLT_Optimized_Components` maps a file path, a URL or a backtrace frame to its owner: a plugin, a theme, WordPress core or an external origin. Each finding's responsible component is resolved through it.
- **Findings schema:** `BLT_Optimized_Findings` defines the shape every finding takes (severity, category, impact, responsible component, where to look, evidence and fix). It also names the options that hold the compiled report and the dismissed-finding keys.
- **Tables:** `wp_blt_optimized_scans` (scan tree; includes `run_id`, `item_type`, `flags` beyond the spec's baseline columns) and `wp_blt_optimized_audit_log`.
- **Updates:** [plugin-update-checker](https://github.com/YahnisElsts/plugin-update-checker) v5.x, GitHub-hosted, guarded require — see `plugin-update-checker/README-VENDOR.md` for the vendoring step. The BLT family policy (`BLT_Family_Updates`) caps automatic checks at one a day, anchored to midnight site time; manual checks — the Plugins-row link, Dashboard → Updates, and **Check for Updates** on this plugin's Settings screen — always run immediately.
- **Shared settings (`includes/blt-family/`):** the BLT family layer, vendored byte-identical into every BLT plugin. It supplies the admin-menu mark, the update policy, and one encrypted store of shared connection settings. This plugin declares the `github` and `image_worker` groups, so on a site running another BLT plugin the GitHub update token and the image Worker URL/secret can be entered once. Reads are fallbacks only: wp-config constant → this plugin's own option → shared store, and the shared store is consulted only after the site owner opts that group in on the BLT screen (off by default). Never edit this directory in one repo alone.
- **Admin design system:** custom screens are composed from `assets/css/blt-design-system.css` (the shared `.blt-ui` component library — see `DESIGN.md`), enqueued on this plugin's own screens only. `assets/admin.css` and `admin/images/assets/blt-admin.css` now hold only what the shared file does not cover.
- **Multisite:** deferred beyond flagging `uploads/sites/*` (per spec open question).
- **MSP Mode (Cloudflare central reporting):** Phase 2 / v2.0 — intentionally not in this codebase yet. The `blt_optimized_after_scan` action receives the compact scan summary that a future `class-blt-optimized-reporter.php` will POST to the Worker endpoint.

### Options and uninstall

| Option | Autoloaded | Holds |
| --- | --- | --- |
| `blt_optimized_tweaks` | yes, read on every front-end request | Every Speed Tweak setting and the asset rules |
| `blt_optimized_perf_report` | no | The latest compiled Performance Scan report |
| `blt_optimized_perf_state` | no | Performance Scan status, cursor and partial results |
| `blt_optimized_perf_pages` | no | Page reports from the last scan, used by the Page Profiler |
| `blt_optimized_dismissed_findings` | no | Keys of dismissed findings |
| `blt_optimized_media_audit` | no | Latest media storage audit results |
| `blt_optimized_media_audit_state` | no | Resumable state of the media audit |
| `blt_optimized_autoload_changes` | no | Autoload changes made by fixes, so they can be undone |

`uninstall.php` removes every option above, along with the disk-scan and settings options. An option whose autoload a fix switched off stays off after uninstall, so the optimization outlives the plugin.

## Roadmap

- **v1.0 (released):** disk scanner, orphaned data cleanup, DB optimization — fully standalone.
- **v1.1 (released):** optional image-optimization module.
- **v1.2 (released):** shared BLT admin design system, BLT family update policy and shared settings layer.
- **v1.3 (this release):** Performance Scan, Page Profiler, Speed Tweaks, and the optional PageSpeed Insights check.
- **Not yet scheduled:** email summary reports for scheduled scans; richer exclusion UI.
- **v2.0:** MSP Mode — Cloudflare Worker/D1 central reporting (or NZT/Arcadia integration).

## Development

Requirements: PHP 8.0+, WordPress 6.0+. Coding style: WordPress Coding Standards.

```sh
# Lint
find . -path ./plugin-update-checker -prune -o -name '*.php' -print | xargs -n1 php -l
```
