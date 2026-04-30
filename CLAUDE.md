# CLAUDE.md — Update Logger

Notes for future Claude Code sessions working on this repository.

## What this repo is

Single-file WordPress plugin that logs core / plugin / theme / translation updates (auto + manual) into a custom DB table and exposes an admin log page. Public on GitHub at `znackarna/wordpress-update-logger`. Author: značkárna s.r.o.

The user's working language is Czech, but the codebase, comments, README, changelog, and POT template are in **English**. Czech only appears in `languages/update-logger-cs_CZ.po`. Match this split when adding code or docs — English in code, translations via the `update-logger` text domain.

## File layout

- [log-updates.php](log-updates.php) — the entire plugin: `final class Update_Logger`, all logic static. ~630 lines.
- [changelog.txt](changelog.txt) — WordPress.org-style changelog (`== x.y.z – YYYY-MM-DD ==` headings, `*` bullets).
- [languages/](languages/) — `.pot` template, `cs_CZ` `.po`/`.mo`. Keep all three in sync when adding translatable strings.
- [README.md](README.md) — public, technical, English.

There is no `composer.json`, no build step, no test suite, no CI. Edits to `log-updates.php` are the deployable artifact.

## Conventions

- **Single class, all-static.** Don't introduce instances or DI — every entry point is a static method on `Update_Logger` registered as a hook callback in `init()`.
- **No external dependencies.** Pure WordPress + PHP. Keep it that way unless the user explicitly asks otherwise.
- **PHP target: 7.4+.** Scalar/return type hints used throughout. Don't introduce 8.x-only syntax (`readonly`, enums, first-class callables, `match` arms with multiple conditions) without confirming with the user.
- **Tabs for indentation** in `log-updates.php` (not spaces). Match the existing file when editing.
- **Multisite-first.** Always use `get_site_option` / `update_site_option` / `get_site_transient` / `set_site_transient` and `$wpdb->base_prefix` for the log table — never `$wpdb->prefix`. The plugin header declares `Network: true`.
- **Capability checks.** Admin page uses `manage_options` (single-site) or `manage_network` (multisite). Preserve this split.
- **SQL safety.** All user-controlled query fragments go through `$wpdb->prepare`; pagination `LIMIT/OFFSET` use `absint()` before string interpolation. Don't relax this.
- **i18n.** Every user-visible string goes through `__()`, `_e()`, `esc_html__()`, etc. with text domain `'update-logger'` (or `self::TEXT_DOMAIN`). After adding a string, update **both** `update-logger.pot` and `update-logger-cs_CZ.po`, then recompile `.mo`. The user has caught untranslated UI before — don't ship a string in code without also adding it to the catalogs.

## Versioning & releases

- Version lives in **two places**: the `Version:` header in `log-updates.php` and `Project-Id-Version` in the `.po` file. Bump both.
- `changelog.txt` uses `== X.Y.Z – YYYY-MM-DD ==` with one `* Bullet:` line per change.
- Commit messages for releases follow the pattern `vX.Y.Z: <one-line summary>` (see `git log`). Non-release commits use sentence-case English, no prefix.
- Schema changes **must** bump `Update_Logger::DB_VERSION` so `maybe_create_table()` re-runs `dbDelta` on the next admin request.

## Database

- Table: `{base_prefix}update_log` (one shared table per network on multisite).
- Schema version stored in site option `update_logger_db_version`.
- Pre-update version snapshot stored in site transient `update_logger_version_snapshot` (24h TTL).
- No uninstall hook is registered — uninstalling does not drop the table or clean options. If the user asks for an uninstaller, add a `uninstall.php` rather than a deactivation hook (deactivation should not destroy data).

## How updates are observed

Two distinct paths, do not confuse them:

- **Automatic updates** — hook `automatic_updates_complete`, payload is a structured `$results` array with old/new versions already populated. Easy case.
- **Manual updates** — hook `upgrader_process_complete`, fires *after* files are overwritten. Old version must come from the snapshot taken when the user loaded the update screen. Snapshot fallback is the WordPress `update_plugins` / `update_themes` site transient (its `->checked` map). When both are missing (rare WP-CLI flows), `old_version = '?'` and the row is still inserted — don't silently drop rows.

The "version unchanged → status = fail" heuristic is intentional: it surfaces expired licenses on commercial plugins (the upgrader returns success but the same files are reinstalled). Do not remove this.

## Admin UI

- Renders inline `<style>` and inline `onclick` (the filter button uses a tiny inline script). No separate JS/CSS files. Keep it inline unless the page grows enough to need enqueueing.
- Mobile breakpoint at 782 px (the WordPress admin convention) collapses the table into a card layout via `data-label` attributes — every `<td>` must carry one.
- Pagination uses `paginate_links()`; `PER_PAGE` is hard-coded to 40.

## Common pitfalls

- **Do not switch to `$wpdb->prefix`** for the log table. On multisite each site would get its own table and the network admin page would show only the main site.
- **Do not add nonces to the filter form** without also wiring submission through a real `<form>` — the current "filter" button is a JS redirect, not a form submit, so a nonce check would break it. If you refactor to a real form, add the nonce in the same change.
- **Translation-row slug format** is `slug (lang)` (with the parentheses) — older queries assume this.
- **POT/PO/MO sync** — the user has previously asked specifically for translation labels to be translatable (1.1.3) and for translation consistency (1.1.4). When adding strings, run through the catalogs in the same commit.

## When the user asks for a release

1. Bump `Version:` in [log-updates.php](log-updates.php).
2. Bump `Project-Id-Version` in [languages/update-logger-cs_CZ.po](languages/update-logger-cs_CZ.po).
3. Add a `== X.Y.Z – YYYY-MM-DD ==` block at the top of [changelog.txt](changelog.txt).
4. If any `.po` strings changed, recompile `.mo` (msgfmt or equivalent).
5. Commit with `vX.Y.Z: <summary>`.
6. Do **not** push, tag, or create a GitHub release unless explicitly asked.

## What this plugin deliberately does NOT do

(Avoid scope-creep edits that add any of these without the user asking.)

- No CSV/JSON export.
- No retention / auto-trim of old rows.
- No email or webhook notifications on `status = fail`.
- No REST endpoints.
- No settings page.
- No WP-CLI commands.
- No custom user capability — relies on built-in `manage_options` / `manage_network`.
