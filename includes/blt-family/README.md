# BLT Family — shared settings layer

A drop-in library, **vendored byte-identical into every BLT plugin**, that lets
a site running more than one BLT plugin enter a shared connection once instead
of once per plugin.

Do not fork this directory inside a single plugin. Change it in one repo, then
port the identical change to the others in the same pass.

```
includes/blt-family/
  bootstrap.php                    Nominate this copy; declare the ABI; eager utilities
  loader.php                       Require the elected copy's stateful classes
  class-blt-family.php             Registry, detection, the opt-in gate, BLT_Family::get()
  class-blt-family-groups.php      Group/field definitions + write-time validation
  class-blt-family-store.php       The one shared option; encrypt/decrypt on the way in/out
  class-blt-family-crypto.php      sodium -> AES-GCM -> tagged base64 envelope
  class-blt-family-brand.php       The BLT mark: menu icon, inline SVG, plugin-card icons
  class-blt-family-updates.php     The family update policy (daily, midnight, manual)
  class-blt-family-admin.php       The shared "BLT" screen (only when 2+ plugins are active)
```

## Wiring a plugin in

Two calls, both during the plugin's own load — before `plugins_loaded`, so the
registry is complete when the library boots:

```php
require_once BLT_THING_DIR . 'includes/blt-family/bootstrap.php';

blt_family_register(
	BLT_THING_FILE,
	array(
		'name'    => 'BLT Thing',
		'slug'    => 'blt-thing',
		'version' => BLT_THING_VERSION,
		'menu'    => 'blt-thing',              // admin page slug, for the overview link
		'groups'  => array( 'github', 'cloudflare' ),
		// Only when the update checker was built with an empty slug and so
		// derives one from the install directory:
		// 'update_slug' => dirname( plugin_basename( __FILE__ ) ),
	)
);
```

Then, at each accessor, **after** the plugin's own resolution has failed:

```php
$token = $settings['api_token'];

if ( '' === $token && class_exists( 'BLT_Family' ) ) {
	$token = BLT_Family::get( 'blt-thing', 'cloudflare', 'api_token' );
}
```

The `class_exists` guard keeps the accessor working if the library is ever
absent. Precedence is always **wp-config constant → the plugin's own option →
shared store**, and nothing in the library ever writes to a plugin's options.

## The opt-in rule

`BLT_Family::get()` returns the default unless *all* of these hold:

1. two or more BLT plugins are active,
2. the plugin named the group in `blt_family_register()`,
3. the site owner ticked that plugin for that group on the BLT screen,
4. the group actually holds a value.

**Default is off, always** — including on upgrade. That is deliberate, and it
is the most important design decision in here.

These credentials sit underneath `is_configured()`-style gates that decide
whether a module boots, whether cron jobs enqueue, and what Site Health says.
If installing a second BLT plugin silently filled in the first one's empty
fields, it could start an image bulk run, switch on a payment path, or wake a
dormant module on a site whose owner never entered that credential. Worse,
"empty" is not "unset": most of these plugins merge settings over defaults of
`''`, so a field an admin *deliberately cleared* looks exactly like one that was
never filled in. An admin who blanks a Stripe secret key to stop taking
payments must not silently inherit a live key from somewhere else.

Consequence worth stating plainly: **a site with every local field populated
behaves bit-identically with this library present or absent.**

## What is shared, and what is not

Shared, because two or more plugins genuinely consume the same credential:

| Group | Fields | Consumers |
|---|---|---|
| `github` | `token` | every plugin's update checker |
| `cloudflare` | `account_id`, `zone_host`, `api_token` | BLT Secure, BLT Gallery |
| `r2` | `endpoint`, `region`, `bucket`, `access_key_id`, `secret_access_key`, `public_url` | BLT Gallery |
| `google` | `maps_api_key`, `youtube_api_key`, `oauth_client_id`, `oauth_client_secret` | BLT Events, BLT Tube |
| `microsoft` | `tenant_id`, `client_id`, `client_secret` | BLT M365 WP SSO, BLT Events |
| `stripe` | `publishable_key`, `secret_key` | BLT Events, BLT SureCart Extensions |
| `surecart` | `api_token` | BLT Events, BLT SureCart Extensions |
| `image_worker` | `worker_url`, `worker_secret` | BLT Optimized, BLT Image Optimizer |

Deliberately **not** shared:

- **BLT Documents' `worker_url` / `worker_secret`.** Byte-identical field names
  to the image optimizers', pointing at a completely different Cloudflare
  Worker. A single canonical pair would send HMAC-signed document reads to the
  image Worker the moment one local field went blank — every board-document
  download would 500 while the settings screen still reported "configured".
  This is the sharpest trap in the whole exercise, and why `image_worker` is
  scoped to the optimizers and nothing from BLT Documents is shared at all.
- **Google keys across APIs.** BLT Events' key is a referrer-restricted browser
  key for Maps JS; BLT Tube's is an IP-restricted server key for YouTube Data
  v3. They can never be the same string, so the group gives each API its own
  field and never cross-feeds them. Sharing here unifies the *screen*, not the
  key.
- **Webhook signing secrets** (Stripe, Shippo). Endpoint-scoped, not
  account-scoped — two plugins on one account still need different ones.
- **Single-consumer credentials**: Shippo's API token, Zoom / GoTo /
  ClickMeeting credentials, BLT Events' Teams organizer, BLT Gallery's AWS
  group, BLT Documents' site ID. One consumer means a shared field would add a
  second place to look for no benefit.
- **`r2` note**: BLT Gallery is currently its only consumer. BLT Documents also
  uses R2 but stores no R2 credentials — its bucket is bound to the Worker. The
  group is defined anyway because it is the canonical shape for the next plugin
  that needs object storage, and because it already unifies Gallery's own R2 and
  AWS configurations.

## Sharp edges

- **`client_id` / `client_secret` in `microsoft`.** A delegated sign-in app
  registration has no `OnlineMeetings.ReadWrite.All`, and an app-only meetings
  registration has no sign-in redirect URI. Sharing the app pair between two
  plugins that need different scopes yields `403 Authorization_RequestDenied` on
  meeting creation, or a broken login — and the consuming plugin's
  `is_configured()` only checks the strings are non-empty, so the screen turns
  green either way. `tenant_id` is the field that is unambiguously one value per
  site; share the app pair only when one registration really covers both uses.
- **Cloudflare `account_id` is not always "this site's account".** BLT Gallery's
  is whichever account owns the R2 bucket (often the agency's); BLT Secure
  discovers its own from the site's zone. Any fallback here must be read-only
  and must never write back into a plugin's own discovered state.
- **Stripe key typing.** `publishable_key` is sent to the browser. A secret key
  pasted into it would be localized to every anonymous visitor, so
  `BLT_Family_Groups::validate()` rejects `sk_` there (and `pk_` in the secret
  field) at write time. Keep that check if you add another keyed service.
- **Salt rotation.** Secrets here are encrypted with a key derived from the
  WordPress salts, exactly as each plugin's own credential store already is.
  Rotating salts therefore empties this row too — and every plugin resolving
  through it loses its credential at the same moment. One plugin failing is a
  ticket; several failing at once during incident response is an outage. Rotate
  salts and re-enter shared credentials as one planned operation.
- **Uninstall.** No plugin's `uninstall.php` touches `blt_family_shared` or
  `blt_family_opt_in`. That is intentional: whichever plugin happened to be
  removed first would otherwise break every plugin still resolving through the
  row. (For precedent on how bad that is: BLT Optimized's uninstall already
  deletes `blt_optimizer_settings`, which is the standalone BLT Image
  Optimizer's option key.) Removing shared credentials is a deliberate act
  performed on the BLT screen.
- **Multisite.** The store uses `get_option()` / `update_option()`, so it is
  strictly per-site. Never switch it to `get_site_option()`: that would hand one
  tenant's Stripe or Worker credentials to every other site in the network.
- **Version election.** Every copy nominates itself in `bootstrap.php`; the
  highest version with a readable loader wins on `plugins_loaded` priority 0.
  The three functions in `bootstrap.php` are the stable ABI — an *older* copy
  may be the one that declares them while a *newer* copy supplies the classes,
  so their signatures must never change incompatibly. Anything that needs to
  evolve belongs in a class.
- **i18n.** Strings here use the `blt-family` text domain, which has no
  translation catalogue of its own — the library is shared, so it cannot borrow
  a host plugin's domain. Strings fall back to English.

## Tests

`blt-family-smoke.php` at the plugin root shims enough of WordPress to exercise the
library standalone — election, the opt-in gate, encrypted round-trips, store
semantics, validation, and the update-policy maths:

```bash
php blt-family-smoke.php
```
