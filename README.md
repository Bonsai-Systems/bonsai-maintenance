# Bonsai Digital Maintenance Mode

A lightweight WordPress plugin that displays a customisable maintenance page to visitors while allowing authorised users to keep working. It can optionally replace WordPress' core `wp-content/maintenance.php` during updates.

> **Status:** Stable
> **Requires:** WordPress 5.8+ • PHP 7.4+ (8.x supported)

---

## Features

- Sends proper **503 Service Unavailable** + `Retry-After` (calculated from the scheduled end time when set)
- Clean, responsive template with **background image**
- **Media library picker** for logo and background image, with thumbnail preview
- **Editable badge** and **header text**
- **WYSIWYG** main content (lists, headings, links)
- **Toggle** to show/hide all on-page content (background-only mode)
- **Background colour** and **font colour** pickers for full visual control
- **Scheduled start/end datetime** — auto-enable/disable without a manual toggle
- **Preview bypass link** — shareable `?cmm_preview=TOKEN` URL for client previews without wp-admin access
- **IP allowlist** — always let specific IPs through
- **Site password** — visitors enter a shared password via a "Have a password?" link to access the site (hashed, throttled, configurable remember duration)
- **Basic SEO**: custom page title + meta description (still `noindex, nofollow`)
- Optional override of WordPress' maintenance screen
- Writes **static HTML snapshot** to `wp-content/maintenance-template.html`
- Auto-checks for updates every 6 hours via GitHub Releases
- Translation-ready, escaped/sanitised, minimal footprint

---

## Requirements

- **WordPress:** 5.8 or newer
- **PHP:** 7.4 or newer (8.0–8.3 tested)
- **Composer:** Required to install dependencies before deployment
- **Filesystem:** `wp-content/` must be writable for the static template and optional `maintenance.php` copy

---

## Installation

### Option A — Zip upload (recommended)
1. Run `composer install --no-dev` in the plugin folder to generate `vendor/`.
2. Zip the entire `bonsai-maintenance/` folder (including `vendor/`).
3. Go to **Plugins → Add New → Upload Plugin** and upload the zip.
4. Activate **Bonsai Digital Maintenance Mode**.

### Option B — Direct deploy
1. Upload the plugin folder to `/wp-content/plugins/`.
2. Ensure `vendor/autoload.php` is present (run `composer install --no-dev` locally first).
3. Activate via **Plugins**.

---

## Development Setup

```bash
cd bonsai-maintenance
composer install
```

Dependencies are managed via Composer. The `vendor/` directory should be built before packaging for deployment.

---

## Settings Reference

Navigate to **Settings → Maintenance Mode** to configure.

### Status
| Option | Key | Description |
|--------|-----|-------------|
| Enable Maintenance Mode | `cmm_enabled` | Master switch |
| Override WP Maintenance Page | `cmm_override_wp_maintenance` | Replace `wp-content/maintenance.php` |

### Schedule
| Option | Key | Description |
|--------|-----|-------------|
| Enable Scheduled Maintenance | `cmm_schedule_enabled` | When on, dates below control maintenance mode instead of the manual toggle |
| Start | `cmm_schedule_start` | Leave blank to start immediately once enabled |
| End | `cmm_schedule_end` | Leave blank to require manual turn-off; also drives `Retry-After` |

### Preview & Access
| Option | Key | Description |
|--------|-----|-------------|
| Preview Token | `cmm_preview_token` | Set + save to generate a `?cmm_preview=TOKEN` link that bypasses maintenance mode for anyone who has it |
| IP Allowlist | `cmm_ip_allowlist` | Comma/newline separated IPs that always bypass maintenance mode |
| Site Password | `cmm_site_password` | Shared password entered on the maintenance page to access the site. Stored hashed; leave blank to keep, tick "Remove password" to clear |
| Remember Password For | `cmm_password_days` | Days access lasts after entering the password (default 7; 0 = browser session, max 24h) |

### Design
| Option | Key | Description |
|--------|-----|-------------|
| Header Logo | `cmm_logo` | Optional logo image, chosen via the media library |
| Background Image | `cmm_background_image` | Full-page background photo, chosen via the media library |
| Background Colour | `cmm_bg_colour` | Page background colour (default: `#ffffff`) |
| Font Colour | `cmm_font_colour` | Body text colour (default: `#111111`) |

### Content
| Option | Key | Description |
|--------|-----|-------------|
| Show Main Content | `cmm_show_main_content` | If off, only background/image shows |
| Badge Text | `cmm_badge_text` | Optional pill-style label |
| Header Text | `cmm_header_text` | Main heading |
| Main Content (WYSIWYG) | `cmm_main_content` | Rich text body |
| Embed Code / HTML | `cmm_embed_content` | For embeds or extra HTML |
| Footer Text | `cmm_footer_text` | Appears after © YEAR |

### Social Links
| Option | Key |
|--------|-----|
| LinkedIn URL | `cmm_linkedin` |
| Facebook URL | `cmm_facebook` |
| Instagram URL | `cmm_instagram` |

### SEO
| Option | Key | Description |
|--------|-----|-------------|
| Page Title | `cmm_seo_title` | Custom `<title>` tag |
| Meta Description | `cmm_seo_description` | `<meta name="description">` (~320 chars) |

---

## Files Written to wp-content

| File | Created when |
|------|-------------|
| `wp-content/maintenance-template.html` | Always, on settings save |
| `wp-content/maintenance.php` | Only if "Override WordPress Maintenance Page" is enabled |

Ensure the web server can write to `wp-content/`.

---

## Project Structure

```
bonsai-maintenance/
├── bonsai-maintenance.php   # Main plugin file
├── composer.json            # Composer dependency manifest
├── composer.lock            # Locked dependency versions
├── vendor/                  # Composer-managed dependencies (not committed)
│   └── autoload.php
├── includes/                # Legacy manual update checker (unused — safe to remove)
├── README.md
├── readme.txt               # WordPress.org-format readme
└── CHANGELOG.md
```

---

## Updates

This plugin self-updates via **GitHub Releases** using the Plugin Update Checker library (Composer-managed). A new update will appear in **Plugins → Updates** when a new release is published on GitHub. Update checks run every 6 hours.

---

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for full version history.

---

## Author

**The Bonsai Digital Collective**
Maintainer: Ben Ervine

For issues and enhancements, please open a GitHub issue with:
- Steps to reproduce
- Environment details (PHP/WP versions)
- Any relevant logs
