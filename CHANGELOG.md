# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.21] - 2026-10-03

### Added
- [lib/bonsai-hub/] Bundled Bonsai Hub 1.0.0: a shared top-level **Bonsai** admin menu with a left-hand nav for every Bonsai plugin, plus a **Plugins** screen to install, activate and deactivate the rest of the suite from GitHub releases.

### Changed
- [bonsai-maintenance.php] Settings moved from **Settings → Maintenance Mode** to **Bonsai → Maintenance Mode** (`admin.php?page=cmm-settings`) and split into tabs: Status (status and schedule), Preview & Access, Design, and Content & SEO. Old `options-general.php` links redirect.
- [bonsai-maintenance.php] Each tab saves through its own option group (`cmm_settings_status`, `_access`, `_design`, `_content`) so saving one tab can't blank another's fields. Option names and stored values are unchanged.

### Removed
- [includes/admin-ui.php, assets/] Per-plugin header and design-system copy. The hub now provides both.

## [1.20] - 2026-09-30

### Added
- [bonsai-maintenance.php] Site password (`cmm_site_password`) — visitors can click "Have a password?" on the maintenance page and enter a shared password to access the site. Stored hashed via `wp_hash_password()`; access is granted by an HttpOnly, HMAC-signed cookie tied to the current hash, so changing or removing the password revokes all existing access. Form is nonce-protected and throttled to 5 failed attempts per IP per 15 minutes.
- [bonsai-maintenance.php] "Remember Password For" setting (`cmm_password_days`, default 7, 0 = browser session capped at 24 hours).

### Changed
- [bonsai-maintenance.php] `cmm_render_maintenance_page()` now accepts an `$args` array; the password form is only rendered on live requests, never in the static `maintenance-template.html` snapshot.

### Fixed
-

### Removed
-

---

### Changed
- [includes/admin-ui.php, assets/] Settings → Maintenance Mode restyled with the Bonsai admin design system: logo header with version, GitHub/changelog/"View site" links; each settings section (Status, Schedule, Preview & Access, Design, Content, Social Links, SEO) in its own card; the Status card shows whether maintenance mode is currently on. No option or field changes.
- [bonsai-maintenance.php] Added `CMM_VERSION` and `CMM_URL` constants. `CMM_VERSION` must be kept in step with the `Version` header.
- [assets/js/admin.js] Media-picker and preview-token JS moved out of inline `<script>`/`wp_add_inline_script()` into an enqueued jQuery file, loaded on the settings screen only.

### Fixed
- [bonsai-maintenance.php] The default maintenance page `<title>` showed a literal `\u2013` (single-quoted PHP string), e.g. "Site \u2013 Scheduled Maintenance". Same bug in the SEO title placeholder and the footer text help (`\u00a9`). Now real – and © characters.
- [assets/js/admin.js] Preview tokens were generated with `Math.random()`; now `crypto.getRandomValues()`, since the token is what lets people bypass maintenance mode.
- [bonsai-maintenance.php] Most fields had no `label_for`/`id`, so clicking a label didn't focus its input and screen readers didn't announce the label. Added throughout.
- [bonsai-maintenance.php] Removed inline `style` attributes and the `onclick` handler from the media fields and preview link.
- [bonsai-maintenance.php] Settings page callback now checks `manage_options` itself.

## [1.19] - 2026-08-17

### Fixed
- [bonsai-maintenance.php] Static maintenance page regeneration (a 13-call `get_option()` burst plus a file write) was running on the `shutdown` hook of every front-end request instead of only after a settings change, contrary to its own "de-bounced" comment. Now gated behind `updated_option`/`added_option` for `cmm_*` keys, so the page only regenerates when a setting is actually saved.

---

## [1.18] - 2026-08-13

### Fixed
- [composer.json] `composer.json` was byte-identical to several other Bonsai plugins' (all requiring `yahnis-elsts/plugin-update-checker`), which risked Composer generating the same `ComposerAutoloaderInit{hash}` autoloader class across plugins — a fatal "class already in use" error if two such plugins were ever active together on the same site (confirmed happening on a live client site between `cookie-consent-video-embed-CookieScript` and this plugin). Added a unique `name` field to `composer.json` and regenerated `vendor/` from a clean install; also picked up `yahnis-elsts/plugin-update-checker` v5.6 → v5.7 in the process.
- [bonsai-maintenance.php] Update checker's GitHub source corrected from `gakdesign/bonsai-maintenance` to `Bonsai-Systems/bonsai-maintenance`, matching the org releases are actually published under (same pattern as `bonsai-code-injector`)

---

## [1.17] - 2026-07-23

### Added
- [bonsai-maintenance.php] Preview bypass link (`cmm_preview_token`) — a shareable `?cmm_preview=TOKEN` URL that lets clients view the live site during maintenance without wp-admin access; sets a signed cookie so the token doesn't need to stay in the URL on every page
- [bonsai-maintenance.php] Scheduled start/end datetime (`cmm_schedule_enabled`, `cmm_schedule_start`, `cmm_schedule_end`) — auto-enables/disables maintenance mode without relying on the manual toggle
- [bonsai-maintenance.php] `Retry-After` header now calculated from the scheduled end time when set, falling back to 3600 seconds
- [bonsai-maintenance.php] IP allowlist (`cmm_ip_allowlist`) — comma/newline separated IPs that always bypass the maintenance gate
- [bonsai-maintenance.php] Media library picker (`cmm_render_media_field()`) replacing plain URL inputs for Header Logo and Background Image, with thumbnail preview and remove button
- [bonsai-maintenance.php] New settings sections: Schedule, Preview & Access

### Changed
- [bonsai-maintenance.php] Front-end gate now checks `cmm_is_maintenance_active()` instead of reading `cmm_enabled` directly, so scheduling and the manual toggle share one code path

---

## [1.16] - 2026-07-23

### Fixed
- [bonsai-maintenance.php] Fixed fatal error on plugin load caused by calling `setUpdateCheckInterval()`, a method not present on `Vcs\PluginUpdateChecker` in the resolved PUC v5.6 build — interval is now set via the `buildUpdateChecker()` constructor argument instead, matching the pattern used in `bonsai-code-injector`

---

## [1.15] - 2026-03-30

### Added
- [bonsai-maintenance.php] Background colour picker option (`cmm_bg_colour`, default `#ffffff`) in Design settings section
- [bonsai-maintenance.php] Font colour picker option (`cmm_font_colour`, default `#111111`) in Design settings section
- [bonsai-maintenance.php] CSS custom properties (`--cmm-bg`, `--cmm-color`) applied to `:root` from saved options, controlling body background and text colour
- [bonsai-maintenance.php] `composer.json` and Composer dependency on `yahnis-elsts/plugin-update-checker ^5.6`
- [bonsai-maintenance.php] `enableReleaseAssets()` call so updates are delivered as ZIP files attached to GitHub Releases
- [CHANGELOG.md] Introduced Keep a Changelog format

### Changed
- [bonsai-maintenance.php] Update checker switched from manually bundled `includes/plugin-update-checker/` to Composer-managed `vendor/autoload.php`, matching `bonsai-core` pattern
- [bonsai-maintenance.php] Update checker variable renamed from `$updateChecker` to `$cmm_update_checker` (prefixed, WP standards)
- [bonsai-maintenance.php] Settings page reorganised from one flat section into five labelled sections: Status, Design, Content, Social Links, SEO
- [bonsai-maintenance.php] Social link fields refactored from duplicate blocks into a DRY loop over `$social_fields` array
- [bonsai-maintenance.php] ABSPATH guard updated from `if (!defined('ABSPATH')) { exit; }` to `defined( 'ABSPATH' ) || exit;`
- [README.md] Updated to reflect Composer-based setup, colour options, and new settings structure

### Fixed
- [bonsai-maintenance.php] Replaced `@unlink()` with `wp_delete_file()` (WP-native, no error suppression)
- [bonsai-maintenance.php] Replaced `@file_put_contents()` and `@copy()` with `is_writable()` checks before file operations
- [bonsai-maintenance.php] Full WordPress Coding Standards compliance pass: spaces in control structure parentheses, tab indentation, Yoda conditions, single-quote strings, PHPDoc blocks

### Removed
- [bonsai-maintenance.php] Dark mode `@media (prefers-color-scheme: dark)` overrides removed so user-chosen colours always take precedence
- [includes/] Manually bundled Plugin Update Checker is now superseded by Composer dependency (directory can be safely deleted)

---

## [1.14] - 2025-01-01

### Changed
- Set update check interval to 6 hours

---

## [1.13] - 2024-01-01

### Changed
- Change of ownership to The Bonsai Digital Collective

---

## [1.12] - 2024-01-01

### Added
- WordPress admin plugin update functionality via GitHub

---

## [1.11] - 2024-01-01

### Added
- Toggle to show/hide main content (background-only mode)
- SEO options: custom page title and meta description

---

## [1.10] - 2024-01-01

### Added
- Editable badge text
- WYSIWYG editor for main content

---

## [1.9] - 2024-01-01

### Added
- Background image option

---

## [1.8] - 2024-01-01

### Added
- Optional override of WordPress core maintenance page
- Static HTML template snapshot written to `wp-content/maintenance-template.html`

### Changed
- Safer hook order
- Improved sanitisation throughout
