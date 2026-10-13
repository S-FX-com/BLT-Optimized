# BLT Optimized

WordPress performance diagnostics, disk-usage forensics and database cleanup by [S-FX.com](https://www.s-fx.com).

BLT Optimized reports what is slowing a WordPress site down or using up its storage, and names the plugin, theme, file, database table, URL or post responsible. Where a fix is safe to apply, it can be applied with one click. The plugin covers three areas:

- **Performance Scan** (v1.3): scored, prioritised findings for the front end, the server, the database and the media library, with a built-in lab test that measures pages in the admin's own browser, an optional Google PageSpeed Insights check, and Bricks Builder support.
- **Disk-usage forensics** (v1.0): a real folder-by-folder breakdown of `wp-content`.
- **Database cleanup and optimization** (v1.0): orphaned data, expired transients, excess revisions, old trashed content and table optimization.

Shared-hosting quota is often used by things sitting in `wp-content`: backup archives nobody deleted, cache directories from a caching plugin swapped out years ago, a `debug.log` growing for years, or a folder left over from a plugin removed via FTP. The disk-usage layer is built to find those.

The Performance Scan, the disk scanner and database cleanup need no external service. Two optional features do contact one: the PageSpeed Insights check (off by default, sends page URLs to Google) and the image module (a Cloudflare Worker). The built-in lab test runs in the admin's own browser and needs no external service either.

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

Ordinary visitor requests only check the URL for a profiler token and a lab token. The profiler's collector code only loads while a profile is running.

**Page analysis.** Each sampled page's HTML is also checked for oversized images, images missing `width`/`height`, a lazy-loaded hero image, legacy image formats, heavy embeds (YouTube, Vimeo and map iframes), DOM size, inline CSS and JavaScript, third-party origins, render-blocking scripts, page-cache and compression detection, and static asset cache headers. When a problem sits inside a post's content, the finding names the post and links to its editor.

**Server and database checks.** PHP version, OPcache, persistent object cache, `WP_DEBUG` and `SAVEQUERIES` in production, memory limit, WP-Cron reliability and overdue events, autoload size (with each oversized autoloaded option and a guess at its owner), transients, cron option size, orphaned cron hooks (scheduled with nothing to handle them), Action Scheduler bloat and stuck queues, revisions, postmeta bloat by meta key, oEmbed cache, table overhead, MyISAM tables and large plugin tables. Items flagged by the last disk scan are imported here too.

**Media storage audit.** Resumable and time-boxed. It finds:

- orphaned files in `uploads/YYYY/MM` folders that no attachment owns and nothing references
- thumbnails of image sizes that are no longer registered
- pre-scaled original images
- the largest images
- unattached and unreferenced attachments, and duplicate uploads — reported only, never acted on

**PageSpeed Insights (optional extra, off by default).** The built-in lab test below needs no API key. The PageSpeed Insights check is turned on under **Settings → Performance scan**. It sends each tested URL to Google, so:

- the site must be public: local, staging, password-protected and maintenance-mode sites cannot be tested
- an API key is effectively required, because Google's keyless quota is usually exhausted

When enabled it adds Lighthouse lab metrics (LCP, CLS, TBT, FCP, Speed Index and TTFB), real-user field data when available, the LCP element, and unused JavaScript and CSS and other opportunities, attributed to the plugin, theme or third party responsible.

### Lab test (built in)

The lab test is a built-in alternative to PageSpeed Insights. It measures pages in your own browser. It is on by default (**Settings → Performance scan → Lab test**, setting key `enable_lab`), needs no API key and no outside service, and so works on local and staging sites.

During a scan, each scanned page loads in a visible phone-sized frame, scaled to fit, and the browser's own performance data is read: the same APIs Lighthouse reads. The Page Profiler's **Run lab test** button measures a page on a mobile, desktop or both viewports, and its **Lab (Core Web Vitals)** tab shows the result.

The measured request renders as a logged-out visitor, without the admin bar. It bypasses page caching and fetches the site's CSS and JavaScript fresh. A single-use token enables it, so only that one request is affected.

It measures:

- **LCP**, and the element responsible for it. On Bricks sites that is the Bricks element and its template, with an "Edit with Bricks" link. Elsewhere it is the post, with its edit link.
- **CLS**, with the elements that shift.
- **Total Blocking Time** and **main-thread time** per plugin, theme and third party, from Long Animation Frames, which Chromium reports.
- **Render-blocking files**, as the browser reports them.
- **Unused CSS** per stylesheet.
- **Images** downloaded much larger than they are shown, and **offscreen images** loaded eagerly.
- **Fonts**, **page weight**, **third-party weight** and **TTFB**.

In a scan, the results appear as median lab tiles, and as LCP, CLS and TBT columns in **Pages scanned**. Scans measure the mobile viewport only.

The lab test has these limits:

- **No CPU or network throttling.** Timings reflect your machine and connection, so they come out faster than PageSpeed Insights' simulated mid-range phone on 4G. Use the lab test to find what is slow and to compare before and after a change. It does not predict a PageSpeed score.
- **No unused-JavaScript coverage.** Main-thread time per script is shown instead.
- **INP is not measured.** Interaction delay needs real interactions. Total Blocking Time is the lab stand-in.
- **The frame must stay on screen, and the admin tab must stay in the foreground, while the test runs.** Browsers do not report paints for hidden frames.
- **Full metrics need a Chromium-based browser** (Chrome or Edge).
- **Some pages cannot be measured.** When the web server sends an `X-Frame-Options` or `Content-Security-Policy` `frame-ancestors` header that forbids framing, the page is reported as blocked. Headers that PHP sends are relaxed for the lab request only.

Google PageSpeed Insights remains available as an optional extra, as described above.

### One-click fixes

Where a fix is safe to apply, a finding offers it as a one-click action. Otherwise it offers a link or manual steps. One-click fixes include:

- switching on a speed tweak
- adding asset rules, for example loading Contact Form 7's scripts only on pages that contain a form (one click covers the plugin's whole asset family)
- stopping autoload for an oversized option (reversible from Speed Tweaks)
- unscheduling an orphaned cron hook
- running a cleanup category: post revisions, or Action Scheduler
- deleting orphaned upload files, stale thumbnails or pre-scaled originals (see [Safety](#safety))
- deleting orphaned Bricks CSS files (see [Safety](#safety))

## Bricks Builder support (v1.3)

Bricks support is detected automatically when Bricks is the active theme, or the parent theme of the active child theme. On other sites nothing from it loads, so it costs nothing there. It is built from Bricks' official documentation and developer schema. Bricks' own code is not available to this plugin, so every read of Bricks data is defensive: a data shape the plugin does not recognise gives no finding rather than a guess.

Bricks-specific findings are added to the Performance Scan. Findings about a page, template or element name it, with an **Edit with Bricks** link that opens the page in the builder (its permalink with `?bricks=run`). Findings about global classes or `bricks_*` options name the class or option instead:

- **CSS loading method.** Whether Bricks prints its CSS inline or loads it from external files. Bricks' own guidance does not call either method universally better, so the finding weighs the trade-off rather than recommending one. It warns when external files are set but no generated CSS files exist, and flags pages whose inline Bricks CSS is large.
- **`bricks_*` options.** The size and autoload flag of each Bricks option, with what is inside it. Bricks reads these options on every page, so this plugin never offers to stop their autoloading. The finding points at the data behind the size instead: unused global classes, unused components and variables, and large custom CSS pasted into a class.
- **Unused global classes.** Global classes, listed by name, that no element in the scanned Bricks content refers to. This means no reference in the scanned content, not proof that a class is unused.
- **Orphaned CSS files.** Per-post files in `uploads/bricks/css` (`post-{ID}.min.css`) left behind by deleted posts, or by posts that no longer use Bricks. They can be deleted with a one-click fix (see [Safety](#safety)).
- **Query loops.** Loops that load every post (Posts Per Page set to -1), loops with a limit above 100 posts, and loops nested inside another loop. Where such a loop sits in a header or footer template, the finding notes that it renders on every page that uses that template.
- **Full-size images.** Image elements set to the full image size but displayed small.
- **Google Fonts.** Google Fonts requested from Google on pages where Bricks' **Disable Google Fonts** setting (Settings → Performance) is off.
- **Icon fonts.** Font Awesome, Ionicons or Themify loaded on most scanned pages.
- **Unreferenced templates.** Published popup and section templates with no display conditions and no references. A popup with no conditions is never rendered, and a section template appears only where a Template element inserts it.
- **Query-loop caching.** Bricks' **Cache query loops** setting is off on a site with a persistent object cache.
- **Lazy loading.** Bricks' **Disable lazy loading** setting is on for the whole site.

Bricks content is read in small, time-boxed batches. A site too large to read in one scan gets a finding that says the check was partial, rather than a guess.

The "content contains" asset rules (see [Speed Tweaks](#speed-tweaks)) also search Bricks page content and the active Bricks header, footer and popup templates, so a shortcode or form placed in a template is found. The lab test maps the elements it measures (`brxe-…` ids) to the Bricks element and template, as described under [Lab test](#lab-test-built-in).

Bricks and plain WordPress (block editor and classic editor) are the builders this support is focused on. Layouts that other page builders keep in post meta (Elementor, Oxygen, Beaver Builder and Thrive) are searched only by "content contains" asset rules. There are no builder-specific checks for them.

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

Settings gains a **Performance scan** card with the extra pages to scan (up to five), the lab test toggle, the PageSpeed Insights toggle and the API key.

### Page Profiler

Profile any URL on demand, with Query Monitor-style tabs: **Lab (Core Web Vitals)**, **Components**, **Queries**, **Assets**, **HTTP calls**, **PHP errors**, **Content**, **Timeline**, and a raw **JSON** download. An optional A/B run measures the page with every speed tweak switched off, so the effect of the tweaks can be seen. The built-in lab test can be run on demand from this screen, on a mobile, desktop or both viewports, and PageSpeed Insights can also be run on demand from it.

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
- The Bricks CSS delete fix (`bricks_delete_css_files`) removes orphaned `post-{ID}.min.css` files from `uploads/bricks/css`. It is destructive, so the backup acknowledgment applies. It acts only on the files the last scan listed, accepts only the strict file-name pattern, resolves every path inside the Bricks CSS folder and skips symbolic links, re-checks that the post is still gone right before deleting, and writes an audit-log entry. This plugin never edits Bricks data (classes, templates, settings). Cleaning those up is a manual step in Bricks.
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
| `blt_optimized_lab_thresholds` | filter | Lab test thresholds, keyed by constant name |
| `blt_optimized_lab_findings` | filter | Change the lab test findings (and receives the measured pages) before they are returned |
| `blt_optimized_bricks_thresholds` | filter | Bricks check thresholds, keyed by constant name |
| `blt_optimized_bricks_edit_url` | filter | Change the "Edit with Bricks" link used in findings |
| `blt_optimized_rule_haystack` | filter | Change the text a "content contains" asset rule searches for a request's post (Bricks adds its active header, footer and popup templates) |
| `blt_optimized_rule_meta_keys` | filter | Change the post meta keys a "content contains" asset rule searches (Bricks adds its page content, header and footer keys) |

## Architecture notes / decisions

- **Background processing:** Action Scheduler is used when present (`as_enqueue_async_action`, e.g. bundled with WooCommerce); otherwise chained WP-Cron single events. Interactive scans are additionally driven by AJAX polling from the open admin page, so they progress regardless. This resolves the "bundle vs. rely on Woo" open question in favor of *rely when present, degrade to WP-Cron* — no vendored copy to keep patched.
- **Performance Scan state machine:** the scan is resumable. It runs as a sequence of steps (health → media → pages → lab → psi → compile), advanced in time-boxed ticks by the open admin page. The lab and psi steps run only when their settings are on. During the lab step the browser measures each page and posts the result back, and the server waits for it. Its state and partial results are kept in non-autoloaded options, so an interrupted scan picks up where it stopped.
- **Lazy-loaded performance classes:** the performance-scan classes (`BLT_Optimized_*`) are loaded on first use through an `spl_autoload_register()` autoloader. A front-end page view never parses the auditors, the findings rules or the PageSpeed client. The disk and database classes keep their explicit `require_once` calls.
- **Profiler gate and collector:** `class-blt-optimized-profiler.php` is loaded on every request, but on an ordinary request its `maybe_start()` only checks for a profile token and a lab token. The collector, which records queries, HTTP calls, PHP notices, transient writes and enqueued assets, is loaded only for a request that carries a single-use token.
- **Lab test: tokens and the logged-out render:** a lab request carries a single-use token, stored as a transient (`blt_optimized_labt_*`, valid for three minutes) and consumed when the page renders. Lab mode then renders the page with no logged-in user and no admin bar, sends no-cache headers, bypasses page caches (`DONOTCACHEPAGE`), cache-busts this site's CSS and JavaScript URLs, relaxes frame-blocking headers that PHP sends, and prints the observer script first in `<head>`, so its observers see the whole page load.
- **Lab test: the browser posts, the server decides:** the browser posts its raw measurements back. `BLT_Optimized_Lab::sanitize()` treats them as untrusted data: it bounds their size and nesting depth, rebuilds each field with its type, range and length limits, drops unknown keys, and accepts only http(s) URLs on this site. Attribution (which plugin or theme owns a file) is computed on the server from the URL, never taken from the posted data.
- **Bricks loading:** `BLT_Optimized_Bricks` loads only when Bricks is the active theme, and then only when one of its hooks fires: a scan being compiled in the admin, or a "content contains" asset rule being evaluated on the front end. Ordinary page views never load it.
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
| `blt_optimized_perf_lab` | no | Lab test results for the running scan, per page and viewport |
| `blt_optimized_bricks_css_orphans` | no | Orphaned Bricks CSS files found by the last scan (the allow-list for the Bricks CSS delete fix) |

`uninstall.php` removes every option above, along with the disk-scan and settings options. An option whose autoload a fix switched off stays off after uninstall, so the optimization outlives the plugin.

## Roadmap

- **v1.0 (released):** disk scanner, orphaned data cleanup, DB optimization — fully standalone.
- **v1.1 (released):** optional image-optimization module.
- **v1.2 (released):** shared BLT admin design system, BLT family update policy and shared settings layer.
- **v1.3 (this release):** Performance Scan, Page Profiler, Speed Tweaks, the built-in lab test, Bricks Builder support, and the optional PageSpeed Insights check.
- **Not yet scheduled:** email summary reports for scheduled scans; richer exclusion UI; a throttled lab run in headless Chrome, through Cloudflare Browser Rendering, with JavaScript coverage; opt-in monitoring of real visitors' Core Web Vitals.
- **v2.0:** MSP Mode — Cloudflare Worker/D1 central reporting (or NZT/Arcadia integration).

## Development

Requirements: PHP 8.0+, WordPress 6.0+. Coding style: WordPress Coding Standards.

```sh
# Lint
find . -path ./plugin-update-checker -prune -o -name '*.php' -print | xargs -n1 php -l
```
