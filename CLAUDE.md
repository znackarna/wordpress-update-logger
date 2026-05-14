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

### ZIP-upload reinstalls (Add New → Upload Plugin → Replace)

This path fires `upgrader_process_complete` with `action='install'`, `type='plugin'`, and **nothing else** in `$hook_extra` — no `plugins`, no `plugin`, and **no `overwrite` key** (a previous version of the code checked `$hook_extra['overwrite']`, which WordPress never sets — that was a real bug, fixed in 1.1.7).

The two reliable signals from this path live on the `$upgrader` object:

- `$upgrader->result['clear_destination'] === true` → distinguishes overwrite-reinstall from a fresh install (we only want to log overwrites; fresh installs are not "updates").
- `$upgrader->plugin_info()` (or `theme_info()->get_stylesheet()`) → returns the plugin file / theme stylesheet, since `$hook_extra` doesn't include it.

If you ever rewrite `on_manual_update`, preserve both branches: regular `action='update'` (bulk_upgrade — covers both manual *Update Now* and the auto-update internal path) AND `action='install'` with `clear_destination=true` (upload-overwrite). Fresh installs (`action='install'` without clear_destination) must NOT be logged.

There is also a redundant second hook on `upgrader_overwrote_package` (added 1.1.8). It fires from `Plugin_Upgrader::install()` / `Theme_Upgrader::install()` only after a successful overwrite, with `($package, $new_data, 'plugin'|'theme')` — no upgrader instance, no plugin file path. The handler resolves the plugin file by matching `$new_data['Name']` + `$new_data['Version']` against `get_plugins()` (or `wp_get_themes()` for themes). Per-request dedup in `insert_row` prevents double-logging when both this and `upgrader_process_complete` fire for the same overwrite.

### What 1.1.8 diagnostic told us, and what's left

1.1.8 shipped a temporary diagnostic ring buffer (`update_logger_hook_log` site option, `record_hook_fire()` helper, admin notice block) to debug a hosting where the upload-overwrite flow wasn't logged. It was removed in 1.1.9 — the redundant `upgrader_overwrote_package` hook reliably catches the case in production. Don't reintroduce a debug surface as a permanent feature; if a similar diagnostic is needed again, ship it and remove it as a one-cycle add.

What the diagnostic revealed (preserved here so the lesson isn't lost):

- `upgrader_process_complete` *does* fire for upload-overwrite with `clear_destination=true` and `destination_name` set in `$upgrader->result`.
- `$upgrader->plugin_info()` calls `get_plugins('/' . $destination_name)` which on at least one hosting setup returns empty inside that callback (likely a cache/timing artifact between the `wp_clean_plugins_cache` priority-9 callback and the subsequent `get_plugins()` lookup). So relying solely on `plugin_info()` was flaky.
- `find_plugin_file_by_destination_name()` (1.1.9) iterates the full canonical `get_plugins()` map and matches by directory name. More cache-resilient. Falls back to `plugin_info()` only if that misses.
- `upgrader_overwrote_package` (1.1.8) remains as a permanent second hook. It fires only after a successful overwrite and does its own Name+Version match via `find_plugin_file_by_data()`. Per-request dedup in `insert_row` keeps duplicates out when both hooks fire for the same operation.

There's an orphaned `update_logger_hook_log` site option on sites that ran 1.1.8. It is harmless (≤2 KB ring buffer) and not cleaned up by 1.1.9 — adding a one-shot cleanup costs more code than the orphan is worth. If a future task requires touching network options, opportunistically delete it.

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
6. Push the commit (`git push`) and publish a GitHub release with tag `vX.Y.Z` (`gh release create vX.Y.Z --title "vX.Y.Z" --notes "<changelog bullets>"`). This is the trigger every WordPress site needs — the bundled plugin-update-checker polls GitHub releases, so without a published release no site sees the update.

## Update distribution (GitHub releases)

The plugin self-updates via a tiny custom checker — no external library. It lives in the "Update checker (GitHub releases)" section of [log-updates.php](log-updates.php), wired up by `Update_Logger::register_update_checker()` from `init()`. Each site polls `api.github.com/repos/znackarna/wordpress-update-logger/releases/latest` once per 12 h, compares the installed `Version:` header against the latest release `tag_name`, and injects an entry into the `update_plugins` site transient when newer.

Why it's hand-rolled instead of using `YahnisElsts/plugin-update-checker`: PUC is ~700 KB of library code covering BitBucket, GitLab, Gitea, theme updates, DebugBar integration, Parsedown, `readme.txt` parsing, etc. We use ~0.5% of it. For an in-house plugin distributed to known sites, the four hooks below are the entire surface area.

### The four hooks

1. **`pre_set_site_transient_update_plugins`** → `inject_update()`. Adds a fake update entry pointing `package` at `zipball_url` from the GitHub release. Only fires when remote version > installed version (`version_compare(..., '<=')` short-circuits the rest).
2. **`plugins_api`** → `plugins_api_details()`. Powers the "View version x.y.z details" popup. Plugin name/author come from `get_file_data(__FILE__, …)` so the popup matches whatever's in the header. Changelog is the GitHub release body rendered as `<pre style="white-space:pre-wrap">` — no Markdown parsing, on purpose (it's admin-facing, escaped, readable enough).
3. **`upgrader_source_selection`** → `rename_update_folder()`. GitHub's zipball extracts to `znackarna-wordpress-update-logger-<sha>/`. Without renaming to the canonical `wordpress-update-logger/` slug, WP would install the new version under the random folder and the plugin path (`wordpress-update-logger/log-updates.php`) would break, silently deactivating the plugin after every update. **Do not remove this filter.**
4. The cache layer: `fetch_latest_release()` stores the release payload in site transient `update_logger_remote_release` for 12 h (`UPDATE_CACHE_TTL`). On HTTP error / 4xx / malformed JSON it stores `['error' => true]` for 30 min (`UPDATE_ERROR_TTL`) — short enough to recover from a transient outage, long enough to avoid hammering GitHub during a sustained rate-limit.

### Conventions that the code assumes

- **Header version must be the canonical version.** `inject_update()` reads `Version:` from `__FILE__` and compares against the release `tag_name` (with leading `v` stripped). Keep `Version: X.Y.Z` (no `v`) in the plugin header and tag releases as `vX.Y.Z`.
- **No `setBranch`-like override.** We always look at `/releases/latest`, which automatically excludes drafts and prereleases. If you ever need rolling updates from `main`, that's a different mechanism — don't bolt it onto the release flow without a config flag.
- **Public repo, no token.** If the repo ever goes private, add an `Authorization: token …` header in `fetch_latest_release()` reading from a `wp-config.php` constant (e.g. `UPDATE_LOGGER_GH_TOKEN`). Do not commit tokens to this repo, and remember the `package` URL (zipball) also needs the same Authorization header — for private repos you'd need an `upgrader_pre_download` filter to set it on the package download. (We don't need this today, but it's the gotcha that bites people first.)
- **No release assets.** We use `zipball_url` (auto-generated source ZIP of the tag). If a future change introduces a build step that produces a different artifact, switch `fetch_latest_release()` to read `assets[0].browser_download_url` and update the release flow to upload that asset.

## What this plugin deliberately does NOT do

(Avoid scope-creep edits that add any of these without the user asking.)

- No CSV/JSON export.
- No retention / auto-trim of old rows.
- No email or webhook notifications on `status = fail`.
- No REST endpoints.
- No settings page.
- No WP-CLI commands.
- No custom user capability — relies on built-in `manage_options` / `manage_network`.
