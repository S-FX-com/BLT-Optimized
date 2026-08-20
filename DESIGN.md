# BLT — Design & Convention Standard

The house standard for every plugin in the S-FX.com **BLT** family:

BLT Documents · BLT Events · BLT Gallery · BLT Image Optimizer ·
BLT M365 WP SSO · BLT Optimized · BLT Popups · BLT Secure ·
BLT SureCart Extensions · BLT Tube

This file is maintained as **one canonical copy, vendored byte-identical into
every BLT repo**. If you change it, port the same change to the other repos in
the same pass — never fork it per plugin. Everything below applies to all of
them; nothing in here is plugin-specific by design.

Reference implementation for the admin UI: **BLT Events**. When a component
here is ambiguous, that repo's Settings, Registrations and Fieldsets screens
are the worked examples.

---

## 1. Brand

### The mark

The BLT mark is a shield monogram: a top rail, a `B` with two punched
counters, a `T`, and an `L` stem that drops through the middle into the
shield's point.

| File | Grid | Use |
|---|---|---|
| `assets/img/blt-mark.svg` | 100 × 100 | The logo. Page headers, docs, plugin-card icons — everywhere except the admin menu. |
| `assets/img/blt-mark-menu.svg` | 20 × 20 | **Only** the WordPress admin menu icon. |
| `assets/img/icon-128x128.png` | 128 × 128 | Plugin card icon (1x), transparent ground. |
| `assets/img/icon-256x256.png` | 256 × 256 | Plugin card icon (2x), transparent ground. |

Both SVGs are authored with `fill="currentColor"`, so the mark inherits the
colour of whatever it sits in and needs no per-context variant.

**Why two SVGs.** WordPress paints an admin menu icon at exactly 20 × 20, as a
CSS background image, with no way to hint it. At that size the full-detail
mark's hairline channels and chamfers land on fractional pixels and anti-alias
into mud. `blt-mark-menu.svg` is the same silhouette redrawn on a 20-unit grid
with whole-number coordinates, so every edge falls on a pixel boundary and the
mark stays crisp.

That variant exists **only** for that one constrained case. Everywhere else you
control the size and the mark is inline SVG, where the detailed version reads
correctly from about 20px up — `.blt-brand-mark` renders it at 22px in a page
header. Do not use the menu variant at large sizes, and do not "simplify" the
detailed one to match it.

### Admin menu icon

Register the top-level menu with the menu mark as a base64 data URI, never a
dashicon:

```php
add_menu_page(
	__( 'BLT Thing', 'blt-thing' ),
	__( 'BLT Thing', 'blt-thing' ),
	'manage_options',
	'blt-thing',
	array( $this, 'render' ),
	BLT_Family_Brand::menu_icon( BLT_THING_DIR ),
	58
);
```

`BLT_Family_Brand::menu_icon()` reads `assets/img/blt-mark-menu.svg`, swaps
`currentColor` for the admin menu's own icon grey (`#a7aaad`), and returns the
data URI. It falls back to a dashicon if the file is missing, so a broken
install degrades instead of rendering a blank square.

WordPress paints an SVG `icon_url` as a CSS background image and never
recolours it, so — unlike a dashicon — it can't brighten on hover or while the
section is open. `BLT_Family_Brand::print_menu_icon_style( $menu_slug )` on
`admin_head` restores that behaviour with a `filter: brightness()` rule. Call
it whenever you use `menu_icon()`.

### Writing "BLT"

**"BLT" is always all caps, everywhere it is written for a human to read** —
plugin names, admin labels, page titles, notices, descriptions, readme files,
commit messages, docs. Never "Blt", never "blt" in prose.

Lowercase `blt` stays correct in machine identifiers, and those must **not** be
"corrected" — renaming them breaks live sites:

| Identifier | Example | Why it stays lowercase |
|---|---|---|
| Text domains | `blt-events` | Must match the `.pot`/`.mo` filenames |
| Option keys | `blt_secure_settings` | Renaming orphans every site's saved data |
| Menu/page slugs | `blt-sce-reports` | Renaming breaks bookmarks and links |
| Shortcodes | `[blt_documents]` | Renaming breaks published content |
| CSS classes | `.blt-card` | Cosmetic, but churns every template |
| Hook/filter names | `blt_secure_modules` | Renaming breaks third-party integrations |
| Function prefixes | `blt_events_*` | Would break every call site |
| DB tables | `wp_blt_documents_files` | Renaming loses the data |

Class names and namespaces (`Blt_Secure_Crypto`, `BltGallery\Admin`) are
internal identifiers too — leave them as each repo already has them. The
all-caps rule is about what a site owner reads, not what PHP resolves.

### Authorship

Every plugin is authored by **S-FX.com**, linked to **https://www.s-fx.com**.
Exactly this, in every header, readme and package manifest:

```php
 * Author:            S-FX.com
 * Author URI:        https://www.s-fx.com
```

No personal names, no "Small Business Solutions" suffix, no `S-FX.COM`, no
bare `s-fx.com`, no trailing slash on the URI. `Plugin URI` is separate and
stays whatever each plugin points at (its product page or GitHub repo).

---

## 2. Admin design system

Two systems, because they live in two different structural contexts. Use
whichever matches where your UI lives.

| | **Custom admin pages** | **Post-type editor screens** |
|---|---|---|
| Where | Any `add_menu_page()` / `add_submenu_page()` screen | Meta boxes on a CPT edit screen |
| Stylesheet | `assets/css/blt-design-system.css` | The plugin's own editor stylesheet |
| Scope class | `.blt-ui` (add to the page's `.wrap`) | `body.post-type-<cpt>` (automatic) |
| Card mechanism | `.blt-card` div you write | WordPress's native `.postbox`, tagged `.blt-card` via `postbox_classes_{screen}_{id}` |
| Colour tokens | CSS custom properties on `:root` | The same values, redeclared on the body class |

Both use the *same colours*, so the family feels like one product, but the
component markup differs because a custom page controls its own HTML while a
metabox is constrained by WordPress's postbox chrome.

### Adding a custom admin page

1. Register it with `add_menu_page()` / `add_submenu_page()`.
2. Enqueue `blt-design-system.css` on that screen's hook only — never
   unconditionally. Handle name: `<plugin-slug>-design-system`.
3. Wrap the content: `<div class="wrap blt-ui blt-my-page">`. The `blt-ui`
   class is what scopes every component below — without it, none of these
   rules apply.
4. Compose from the catalog below. Only write page-specific CSS for layout no
   existing component covers.

**Naming gotcha:** component class names (`.blt-card`, `.blt-field`,
`.blt-toggle`, `.blt-badge`, `.blt-select-card`, …) are shared globally under
`.blt-ui`. Grep the page's PHP/CSS/JS for a class name before introducing a
shared component to a page that already has custom markup — a page-local
`.blt-field-label` colliding with the shared field-row label is the exact bug
this warning exists for. Rename the page-specific one.

### Component catalog (`.blt-ui` scope)

#### Page header

```html
<div class="wrap blt-ui blt-my-page">
  <div class="blt-admin-page-header">
    <h1>
      <svg class="blt-brand-mark" aria-hidden="true"><!-- blt-mark.svg --></svg>
      Page Title <span class="blt-admin-page-header-sub">Optional subtitle</span>
    </h1>
    <div class="blt-admin-page-actions">
      <a href="#" class="button button-primary">Primary action</a>
    </div>
  </div>
```

The mark in the header is optional; when present it's `.blt-brand-mark` and
inherits the heading colour.

#### Card

```html
<div class="blt-card">
  <div class="blt-card-header">
    <h2>Card title</h2>
    <p>One-line description shown under the title.</p>
    <div class="blt-card-header-badges">
      <span class="blt-badge blt-badge-on">Connected</span>
    </div>
  </div>
  <div class="blt-card-body">
    ... fields, a table, anything ...
  </div>
</div>
```

A `<table class="widefat">` (or a `WP_List_Table`'s output) inside
`.blt-card-body` automatically loses its own border/shadow so the card supplies
it once.

#### Field row

```html
<div class="blt-field">
  <div class="blt-field-label">Label</div>
  <div>
    <input type="text" class="regular-text" />
    <p class="blt-field-desc">Helper text under the control.</p>
  </div>
</div>
```

Stack `.blt-field` rows inside a `.blt-card-body`; each gets a divider except
the first. Use these instead of `<table class="form-table">`.

#### Toggle switch

```html
<label class="blt-toggle">
  <input type="checkbox" name="my_option" value="1" />
  <span class="blt-toggle-track" aria-hidden="true"><span class="blt-toggle-thumb"></span></span>
  <span class="blt-toggle-text">
    <span class="blt-toggle-label">Show currency code</span>
    <span class="blt-toggle-desc">Appends the code after prices, e.g. 25.00 USD.</span>
  </span>
</label>
```

Wrap several in `.blt-toggle-stack`. Use a toggle for any boolean setting — not
a bare checkbox.

#### Badge / status pill

```html
<span class="blt-badge blt-badge-on">Connected</span>
<span class="blt-badge blt-badge-off">Not connected</span>
<span class="blt-badge blt-badge-confirmed">Confirmed</span>
<span class="blt-badge blt-badge-pending">Pending</span>
<span class="blt-badge blt-badge-cancelled">Cancelled</span>
```

Badges are deliberately **not** scoped to `.blt-ui` — they also appear inside
native list tables that aren't wrapped in the page shell. Use these for any
connected/active/status indicator; never hand-roll `style="color:…"` spans.

#### Tabs

```html
<nav class="blt-settings-tabs">
  <a class="blt-settings-tab is-active" href="?page=blt-thing&tab=general">General</a>
  <a class="blt-settings-tab" href="?page=blt-thing&tab=advanced">Advanced</a>
</nav>
```

Real `<a>` elements, one URL per tab, so tabs are linkable and work without
JavaScript.

#### Save bar

```html
<div class="blt-settings-footer">
  <button type="submit" class="button button-primary blt-save-button">Save changes</button>
</div>
```

#### Stat tiles

```html
<div class="blt-stats">
  <div class="blt-stat">
    <span class="blt-stat-label">Total saved</span>
    <span class="blt-stat-value">1.4 GB</span>
    <span class="blt-stat-sub">across 812 images</span>
  </div>
</div>
```

#### Progress bar

```html
<div class="blt-progress"><div class="blt-progress-fill" style="width:42%"></div></div>
```

#### Selectable cards (radio/checkbox card grid)

```html
<div class="blt-select-cards" role="radiogroup">
  <label class="blt-select-card is-selected">
    <input type="radio" name="my_choice" value="a" checked />
    <span class="blt-select-card-check" aria-hidden="true"></span>
    <span class="blt-select-card-name">Option A</span>
    <span class="blt-select-card-desc">One-line description.</span>
  </label>
</div>
```

Toggle `.is-selected` with JS on `change` — don't rely on `:has()` alone.

#### Callout

```html
<div class="blt-callout">
  <strong>Heads up</strong>
  <span>One or two lines of context, optionally followed by chips:</span>
  <span class="blt-chips"><code class="blt-chip">{example_var}</code></span>
</div>
```

#### Empty state

```html
<div class="blt-empty">
  <span class="blt-empty-title">No documents yet</span>
  <span>Upload a file to get started.</span>
</div>
```

#### Toolbar, code block, URI chip

```html
<div class="blt-toolbar">
  <input type="search" /> <span class="blt-toolbar-spacer"></span>
  <button class="button">Filter</button>
</div>
<code class="blt-code">multi-line output</code>
<code class="blt-redirect-uri">https://example.com/callback</code>
```

### Colour tokens

Defined on `:root` in `assets/css/blt-design-system.css`. Post-type editor
stylesheets redeclare the same values on their body class.

| Token | Value | Use |
|---|---|---|
| `--blt-primary` | `#2271b1` | Accent, active tab, focus ring, checked toggle |
| `--blt-primary-hover` | `#135e96` | Hover state for primary accents |
| `--blt-primary-tint` | `#f6fafd` | Selected-card background, callout background |
| `--blt-fg` | `#1e1e1e` | Primary text |
| `--blt-muted-fg` | `#646970` | Secondary/helper text |
| `--blt-border` | `#dcdcde` | Card and control borders |
| `--blt-surface` | `#ffffff` | Card background |
| `--blt-surface-muted` | `#f6f7f7` | Card header dividers, code chip background |
| `--blt-success-bg` / `--blt-success-fg` | `#edfaef` / `#005c12` | "Connected"/"Confirmed" |
| `--blt-warning-bg` / `--blt-warning-fg` | `#fef8ee` / `#94660c` | "Pending" |
| `--blt-danger-bg` / `--blt-danger-fg` | `#fcf0f1` / `#8a2424` | "Cancelled"/error text |
| `--blt-neutral-bg` / `--blt-neutral-fg` | `#f0f0f1` / `#646970` | "Not connected"/"Refunded" |
| `--blt-radius` / `--blt-radius-sm` | `8px` / `4px` | Card / control corner radii |

Never hard-code these hexes in a plugin stylesheet — reference the token, with
a literal fallback only where the stylesheet may load without the design
system (`var(--blt-fg, #1e1e1e)`).

### Front end

Front-end output is deliberately **not** part of `.blt-ui`. It renders inside
the site's active theme, so each plugin keeps its own self-contained,
prefixed front-end CSS and must not depend on a utility-class framework.

---

## 3. Shared settings (`includes/blt-family/`)

A site running two or more BLT plugins shouldn't ask for the same Cloudflare
token twice. `includes/blt-family/` is a drop-in library, vendored
byte-identical into every BLT plugin, that provides one encrypted store of
shared connection settings.

- **Load it, don't own it.** Every plugin ships a copy and registers it; the
  newest version present on the site wins and boots exactly once. Never
  fork the library inside one plugin.
- **Register on load**, before `plugins_loaded`:
  ```php
  require_once BLT_THING_DIR . 'includes/blt-family/bootstrap.php';
  blt_family_register(
  	BLT_THING_FILE,
  	array(
  		'name'    => 'BLT Thing',
  		'slug'    => 'blt-thing',
  		'version' => BLT_THING_VERSION,
  		'menu'    => 'blt-thing',
  		'groups'  => array( 'cloudflare', 'r2' ),
  	)
  );
  ```
- **Read through a fallback, never a replacement.** A plugin's own setting always
  wins; the shared store is consulted only when the local value is empty. Every
  read names the plugin doing the reading:
  ```php
  $token = $settings['api_token'];

  if ( '' === $token && class_exists( 'BLT_Family' ) ) {
  	$token = BLT_Family::get( 'blt-thing', 'cloudflare', 'api_token' );
  }
  ```
  Precedence is always **wp-config constant → the plugin's own option → shared
  store**. Existing per-plugin option keys and their saved data keep working
  untouched, and nothing in the library ever writes to a plugin's own options.
- **Nothing is shared until the site owner says so.** `BLT_Family::get()`
  returns the default unless two or more BLT plugins are active, the plugin
  declared that group, *and* the owner enabled that group for that plugin on the
  BLT screen. The default is off, including on upgrade.

  That is the single most important rule in the layer, because these credentials
  sit underneath `is_configured()`-style gates that decide whether a module
  boots, whether cron jobs enqueue, and what Site Health reports. If installing a
  second BLT plugin silently filled in the first one's empty fields, it could
  start a bulk image run, switch on a payment path, or wake a dormant module on a
  site whose owner never entered that credential. And "empty" is not "unset":
  these plugins merge settings over defaults of `''`, so a field an admin
  deliberately *cleared* looks identical to one never filled in — an admin who
  blanks a Stripe secret key to stop taking payments must not inherit a live one.

  The consequence worth stating plainly: **a site with every local field
  populated behaves bit-identically with the library present or absent.**
- **The shared screen appears only when 2+ BLT plugins are active.** One plugin
  on a site sees no new menu, no notice and no new screen.
- Secrets are encrypted at rest with the same envelope every BLT plugin already
  uses (libsodium → OpenSSL AES-GCM), keyed from the WordPress salts so every
  plugin on the site derives the same key and none of them stores it. The store
  is strictly per-site (`get_option`, `autoload = no`) — never
  `get_site_option()`, which on multisite would hand one tenant's credentials to
  every other site in the network.
- **Group carefully.** A group earns its place only when two or more plugins
  genuinely consume the same credential. Two traps, both real in this fleet:
  identical field names addressing different services (BLT Documents and the
  image optimizers both store `worker_url`/`worker_secret` for *different*
  Cloudflare Workers), and one vendor with per-API keys that can never be the
  same string (a referrer-restricted Google Maps browser key vs an
  IP-restricted YouTube Data server key). Sharing those would break sites.
- No plugin's `uninstall.php` touches the shared option. Whichever plugin was
  removed first would otherwise break every plugin still resolving through it.

See `includes/blt-family/README.md` for the full contract and the group/field
reference.

---

## 4. Update policy

Every BLT plugin updates from its own GitHub Releases through the vendored
`plugin-update-checker`. The family policy, applied by
`BLT_Family_Updates::apply()`:

- **At most one automatic check per day**, anchored to **00:00 site time**.
  `plugin-update-checker` shortens its own interval to 60 seconds on
  Dashboard → Updates and 1 hour on the Plugins screen; the policy overrides
  that with a hard 24-hour floor.
- **Manual checks always work immediately.** The **Check for Updates** link on
  the Plugins row and the **Check again** button on Dashboard → Updates are
  explicit requests and bypass the floor. Each plugin also surfaces a
  "Check for Updates" action on its own settings screen.
- The BLT mark is attached to the plugin's card on the update screens, so a
  GitHub-hosted plugin doesn't fall back to WordPress's generic placeholder.

Wire it once, next to where the checker is built. The checker **must** be
constructed with `24` as `buildUpdateChecker()`'s `$checkPeriod` — one built with
`0` registers no scheduler hooks at all and cannot be revived afterwards:

```php
$checker = PucFactory::buildUpdateChecker( $repo_url, BLT_THING_FILE, 'blt-thing', 24 );

BLT_Family_Updates::apply(
	$checker,
	array(
		'basename'  => BLT_THING_BASENAME,
		'icons_url' => BLT_THING_URL . 'assets/img/',
	)
);
```

Never lower the floor below 24 hours, and never disable the manual path.

---

## 5. Checklist for a new admin screen

- [ ] Wrapped in `<div class="wrap blt-ui …">`
- [ ] `blt-design-system.css` enqueued on this hook only
- [ ] Composed from the catalog above; no `<table class="form-table">`
- [ ] Booleans are `.blt-toggle`, statuses are `.blt-badge`
- [ ] Colours come from tokens, not literals
- [ ] Nonce + capability check on every action; input sanitized, output escaped
- [ ] Every human-readable string wrapped in the plugin's text domain
- [ ] "BLT" all caps in every string a site owner reads
- [ ] Credentials read through the shared-store fallback where a group exists
