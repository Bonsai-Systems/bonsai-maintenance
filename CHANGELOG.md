# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
-

### Changed
-

### Fixed
-

### Removed
-

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
