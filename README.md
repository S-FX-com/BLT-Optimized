# BLT Optimized

WordPress disk & database optimization plugin by [S-FX.com Small Business Solutions](https://s-fx.com).

Most optimization plugins treat database bloat as the whole problem. In practice, what actually eats a shared-hosting quota is sitting in `wp-content`: backup archives nobody deleted, cache directories from a long-replaced caching plugin, a `debug.log` growing for years, or a folder left over from a plugin deleted via FTP. BLT Optimized's differentiator is the **disk-usage forensics layer** — a real folder-by-folder breakdown of `wp-content` — with database cleanup and optimization included as the secondary feature.

Works standalone with zero external dependency. Handoff clients get full value from day one.

### Optional: image-optimization module (v1.1)

BLT Optimized also bundles an **optional image-optimization module** (off by default), merged in from the former standalone *Blt Image Optimizer* plugin. It permanently optimizes images on disk — compression + WebP conversion — by routing them through a self-hosted Cloudflare Worker (`worker/`), then rewrites front-end URLs/srcset to serve the `.webp`.

- Enable under **Settings → Image optimization** (setting key `enable_images`). When off, no image code runs and no image table/options are created — the disk/DB core keeps its zero-dependency guarantee.
- Self-contained under the `BltImageOptimizer\` namespace in `includes/images/` + `admin/images/`, with its own log table (`{prefix}blt_optimizer_log`) and settings option (`blt_optimizer_settings` — note: one letter off from the core's `blt_optimized_settings`, kept intentionally distinct).
- Requires the Worker deployed to a real **zone route** (not `*.workers.dev` — `cf.image` transforms are unavailable there). Configure the Worker URL + shared secret under **Image Settings** and use **Test Connection** to verify.
- Hand-off safe: optimized `.webp` files and their `_blt_webp_sizes` / `_blt_optimized` postmeta are preserved on uninstall, so the site keeps serving WebP after the plugin (and Worker) are gone.

## Features (v1.0)

### Disk usage scanner (primary)
- Breadth-first, **time-boxed, resumable** scan of `wp-content` — built to survive cheap shared hosting. Partial scan state persists between ticks, so a scan resumes after a timeout, deploy, or restart.
- `du -sb` fast path when `exec()` is available; pure-PHP `RecursiveDirectoryIterator` fallback when it isn't (SiteGround / GoDaddy / Pressable shared plans).
- Drill-down tree: `uploads` by year/month, per-plugin and per-theme folder sizes; `wp-admin` / `wp-includes` reported as single reference figures.
- Known space-hog signatures (filterable): UpdraftPlus / AI1WM / Duplicator / BackWPup archives, orphaned cache dirs, WooCommerce logs, Elementor assets, `.git`, `node_modules`, multisite `uploads/sites/*`.
- Notable-file detection: growing `debug.log` / `error_log` files, large backup archives.
- **Orphaned plugin-folder detection** — folders in `wp-content/plugins` with no matching installed plugin header. Flag only, never auto-deleted.
- Registered image-size crop multiplier reported (never auto-deletes generated sizes).
- Top 20 space hogs panel, CSV export, manual Scan Now + scheduled weekly/monthly auto-scan.

### Orphaned data cleanup
Dry-run preview → confirm → execute → logged, for: orphaned postmeta / usermeta / commentmeta / term relationships / terms, expired + orphaned transients, oEmbed cache, expired session tokens, excess revisions (configurable retention, default 5), old trashed posts and spam/trashed comments (configurable age, default 30 days). Leftover tables from uninstalled plugins are **flagged only — never dropped**.

### Database optimization
- `OPTIMIZE TABLE` with engine detection surfaced first; large InnoDB tables warn (rebuild + brief lock) instead of running silently.
- Autoloaded-options audit: total autoload payload, top 20 largest options, anything over 100 KB flagged.
- Remaining MyISAM tables flagged for InnoDB conversion.
- Before/after summary: DB size, top tables, autoload size.

### Safety
- Every destructive action: `manage_options` + nonce, POST only.
- Dry-run is the default state everywhere.
- Backup acknowledgment before the first bulk deletion in a session.
- Full exportable audit log (who, what, when, how much reclaimed).
- Hard exclusions (active theme/plugin folders, `wp-config.php`, `.htaccess`) plus an admin-editable exclusion list and `blt_optimized_exclude_paths` filter. Backup archives are never swept into a generic bulk delete.

## Hooks

| Hook | Type | Purpose |
| --- | --- | --- |
| `blt_optimized_before_scan` / `blt_optimized_after_scan` | action | Scan lifecycle |
| `blt_optimized_space_hog_signatures` | filter | Extend the known-signature list |
| `blt_optimized_before_cleanup` / `blt_optimized_after_cleanup` | action | Cleanup lifecycle |
| `blt_optimized_exclude_paths` | filter | Extend scanner exclusions |
| `blt_optimized_max_depth` | filter | Tree depth (default 4) |
| `blt_optimized_tick_budget` | filter | Seconds per scan tick (default 15) |

## Architecture notes / decisions

- **Background processing:** Action Scheduler is used when present (`as_enqueue_async_action`, e.g. bundled with WooCommerce); otherwise chained WP-Cron single events. Interactive scans are additionally driven by AJAX polling from the open admin page, so they progress regardless. This resolves the "bundle vs. rely on Woo" open question in favor of *rely when present, degrade to WP-Cron* — no vendored copy to keep patched.
- **Tables:** `wp_blt_optimized_scans` (scan tree; includes `run_id`, `item_type`, `flags` beyond the spec's baseline columns) and `wp_blt_optimized_audit_log`.
- **Updates:** [plugin-update-checker](https://github.com/YahnisElsts/plugin-update-checker) v5.x, GitHub-hosted, guarded require — see `plugin-update-checker/README-VENDOR.md` for the vendoring step.
- **Multisite:** deferred beyond flagging `uploads/sites/*` (per spec open question).
- **MSP Mode (Cloudflare central reporting):** Phase 2 / v2.0 — intentionally not in this codebase yet. The `blt_optimized_after_scan` action receives the compact scan summary that a future `class-blt-optimized-reporter.php` will POST to the Worker endpoint.

## Roadmap

- **v1.0 (this release):** disk scanner, orphaned data cleanup, DB optimization — fully standalone.
- **v1.1:** email summary reports for scheduled scans; richer exclusion UI.
- **v2.0:** MSP Mode — Cloudflare Worker/D1 central reporting (or NZT/Arcadia integration).

## Development

Requirements: PHP 8.0+, WordPress 6.0+. Coding style: WordPress Coding Standards.

```sh
# Lint
find . -path ./plugin-update-checker -prune -o -name '*.php' -print | xargs -n1 php -l
```
