# Vendoring plugin-update-checker

This directory is a placeholder. The main plugin file guards its require with
`file_exists()`, so the plugin functions identically until the library is
vendored — update checks simply stay off.

To enable GitHub-powered updates, vendor
[YahnisElsts/plugin-update-checker](https://github.com/YahnisElsts/plugin-update-checker)
v5.7 (or later v5.x) into this directory so that
`plugin-update-checker/plugin-update-checker.php` exists at the plugin root:

```sh
cd blt-optimized
curl -L -o puc.zip https://github.com/YahnisElsts/plugin-update-checker/archive/refs/tags/v5.7.zip
unzip puc.zip
rm -rf plugin-update-checker && mv plugin-update-checker-5.7 plugin-update-checker
rm puc.zip
```

Or via Composer: `composer require yahnis-elsts/plugin-update-checker` and
copy `vendor/yahnis-elsts/plugin-update-checker` here.

If the GitHub repository stays private, define a token in `wp-config.php`:

```php
define( 'BLT_OPTIMIZED_GITHUB_TOKEN', 'ghp_…' );
```

Releases are read from the `main` branch / GitHub Releases of
`S-FX-com/BLT-Optimized`; release notes populate the "View version details"
modal via `readme.txt`.
