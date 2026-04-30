# Update Logger

WordPress plugin that records every core, plugin, theme, and translation update — automatic or manual — into a dedicated database table. Provides an admin log page with filtering and importance classification.

Author: [značkárna s.r.o.](https://github.com/znackarna) · Current version: **1.1.9** · License: **GPLv2 or later** · Network-aware (multisite-safe).

---

## Features

- **All update sources covered** — WordPress core, plugins, themes, and translation files.
- **Both auto and manual paths** — automatic background updates via `automatic_updates_complete`, admin-initiated updates via `upgrader_process_complete`, and "Replace existing" file uploads.
- **Old/new version capture** — for manual updates the previous version is captured ahead of time via a transient snapshot taken on update-related admin screens; falls back to WordPress's own `update_plugins` / `update_themes` site transient when the snapshot is missing (e.g. WP-CLI runs).
- **Importance classification** — semver-based comparison of old vs. new version produces a `Major / Minor / Patch` label with color coding.
- **License-fail heuristic** — when a paid plugin "updates" but the version number does not change (typical symptom of an expired license), the row is logged with `status = fail`.
- **Multisite-aware** — a single shared table per network (`{base_prefix}update_log`); the admin page registers under *Network Settings* on multisite and *Tools* on single-site installs.
- **Filtering & pagination** — filter by update type and by year-month; 40 rows per page.
- **i18n-ready** — text domain `update-logger`, `.pot` shipped, Czech (`cs_CZ`) translation included.

---

## How it works

The plugin is a single PHP file with one final class, [`Update_Logger`](log-updates.php). Lifecycle:

1. **Table creation** — on `admin_init`, [`maybe_create_table()`](log-updates.php#L76) calls `dbDelta` to create `{base_prefix}update_log` if `DB_VERSION` does not match the stored option.
2. **Pre-update snapshot** — on every load of `update.php`, `update-core.php`, `plugins.php`, `themes.php`, `plugin-install.php`, and `theme-install.php`, [`snapshot_versions()`](log-updates.php#L110) writes a site transient (`update_logger_version_snapshot`, 1-day TTL) listing the current version of every installed plugin, theme, and core. This is what supplies the "old version" for manual updates after the upgrader has already overwritten files.
3. **Auto updates** — [`on_auto_update()`](log-updates.php#L137) reads the structured `$results` array WordPress passes into `automatic_updates_complete` and inserts one row per item. Translations are recorded under their own `update_type = translation` (slug includes the language code).
4. **Manual updates** — [`on_manual_update()`](log-updates.php#L173) hooks `upgrader_process_complete` and accepts both `action = update` and `action = install` with `overwrite = true` (the "Replace existing" upload flow). It computes old version from the snapshot, new version by re-reading plugin/theme metadata after the upgrade.
5. **Admin UI** — [`render_page()`](log-updates.php#L381) renders a `widefat` table with subsubsub type filter, a year-month dropdown, and `paginate_links` pagination. Mobile breakpoint at 782 px collapses rows into card layout via `data-label` attributes.

### Database schema

`{base_prefix}update_log`:

| Column        | Type                | Notes                                |
|---------------|---------------------|--------------------------------------|
| `id`          | `BIGINT UNSIGNED`   | Primary key, auto increment          |
| `logged_at`   | `DATETIME`          | Defaults to `CURRENT_TIMESTAMP`      |
| `update_type` | `VARCHAR(20)`       | `core` / `plugin` / `theme` / `translation` |
| `slug`        | `VARCHAR(255)`      | Plugin folder, theme stylesheet, `wordpress`, or `slug (lang)` for translations |
| `old_version` | `VARCHAR(40)`       | May be `?` when the snapshot is missing |
| `new_version` | `VARCHAR(40)`       |                                      |
| `status`      | `VARCHAR(10)`       | `ok` / `fail`                         |
| `method`      | `VARCHAR(10)`       | `auto` / `manual`                     |

Indexes: `idx_logged_at (logged_at)`, `idx_type_slug (update_type, slug)`.

The schema version lives in `Update_Logger::DB_VERSION` (currently `1.0`) and is tracked in the `update_logger_db_version` site option. Schema changes require bumping that constant so `dbDelta` re-runs.

### WordPress hooks consumed

| Hook                                           | Purpose                                |
|------------------------------------------------|----------------------------------------|
| `admin_init`                                   | Lazy table creation / migration check  |
| `plugins_loaded`                               | Load text domain                       |
| `load-update.php`, `load-update-core.php`, `load-plugins.php`, `load-themes.php`, `load-plugin-install.php`, `load-theme-install.php` | Take version snapshot before user triggers an update |
| `automatic_updates_complete`                   | Log auto-updates                       |
| `upgrader_process_complete`                    | Log manual updates                     |
| `admin_menu` / `network_admin_menu`            | Register the *Update Log* admin page   |

---

## Installation

1. Copy the plugin folder into `wp-content/plugins/update-logger/` (or upload the ZIP via the admin).
2. Activate either site-wide or network-wide. The header declares `Network: true`, so on multisite it activates for the whole network and writes to a single shared table.
3. Open **Tools → Update Log** (single-site) or **Network Admin → Settings → Update Log** (multisite).

The first table creation happens lazily on the next admin request. No activation hook is registered.

### Requirements

- WordPress with the standard upgrader API (any reasonably current version).
- PHP 7.4+ — the code uses scalar return types, typed properties, and array-shape annotations.
- Capability `manage_options` (single-site) or `manage_network` (multisite) to view the log.

---

## Internationalization

- Text domain: `update-logger`
- Path: [`languages/`](languages/)
- Shipped: `.pot` template, Czech (`cs_CZ`) `.po` + `.mo`.
- Type-filter labels (`Core`, `Plugin`, `Theme`, `Translation`) are passed through `translate(ucfirst($t), 'update-logger')` so all four are translatable.

To add a language, copy `languages/update-logger.pot` to `languages/update-logger-{locale}.po`, translate, and compile to `.mo`.

---

## Project layout

```
update-logger/
├── log-updates.php         # Single-file plugin (Update_Logger class)
├── changelog.txt           # Version history
├── languages/
│   ├── update-logger.pot
│   ├── update-logger-cs_CZ.po
│   └── update-logger-cs_CZ.mo
└── README.md
```

---

## Changelog

See [changelog.txt](changelog.txt). Most recent:

- **1.1.9** — Removed the temporary diagnostic ring buffer; the install branch in `on_manual_update` now resolves the plugin/theme through a destination-name lookup against the full `get_plugins()` catalog (more cache-resilient than `$upgrader->plugin_info()`).
- **1.1.8** — Belt-and-suspenders fallback hook (`upgrader_overwrote_package`) for ZIP-upload reinstalls plus a temporary diagnostic ring buffer on the admin log page.
- **1.1.7** — Fix: ZIP-upload reinstalls (Add New → Upload Plugin → Replace) are now captured; the previous `$hook_extra['overwrite']` check was a no-op because WordPress never sets that key.
- **1.1.6** — Admin pagination switched to the canonical WP_List_Table layout (item count, first/prev/next/last buttons).
- **1.1.5** — GPLv2-or-later license declared and shipped; technical README added.
- **1.1.4** — Czech "Update Log" unified to *Záznam aktualizací*.
- **1.1.3** — Type filter labels (Core / Plugin / Theme / Translation) made translatable.
- **1.1.2** — Initial public release.

---

## Notes for forks

- The plugin uses one **site option** (`update_logger_db_version`) and one **site transient** (`update_logger_version_snapshot`); on multisite both are network-scoped.
- Constant `Update_Logger::PER_PAGE` (40) is the only pagination knob.
- There is no admin-side delete, export, or retention policy. Rows accumulate indefinitely unless trimmed manually (`DELETE FROM {base_prefix}update_log WHERE logged_at < …`).
- No REST endpoints, no settings page, no cron jobs.

---

## License

GPL-2.0-or-later. Full text in [LICENSE](LICENSE).

Copyright (C) 2026 značkárna s.r.o. This program is free software; you can redistribute it and/or modify it under the terms of the GNU General Public License, version 2 (or, at your option, any later version), as published by the Free Software Foundation. This program is distributed WITHOUT ANY WARRANTY.
