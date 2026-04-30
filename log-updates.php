<?php

/**
 * Plugin Name:  Update Logger
 * Description:  Logs all WordPress core, plugin and theme updates (auto & manual) to the database.
 * Author:       značkárna s.r.o.
 * Version:      1.1.8
 * Text Domain:  update-logger
 * Domain Path:  /languages
 * Network:      true
 * License:      GPLv2 or later
 * License URI:  https://www.gnu.org/licenses/gpl-2.0.html
 */

/*
Copyright (C) 2026 značkárna s.r.o.

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License, version 2, as
published by the Free Software Foundation.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software Foundation,
Inc., 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301 USA.
*/

defined('ABSPATH') || exit;

final class Update_Logger
{

	const DB_VERSION  = '1.0';
	const OPTION_KEY  = 'update_logger_db_version';
	const SNAP_KEY    = 'update_logger_version_snapshot';
	const HOOK_LOG_KEY = 'update_logger_hook_log'; // Diagnostic ring buffer (added 1.1.8).
	const HOOK_LOG_MAX = 10;
	const TEXT_DOMAIN = 'update-logger';
	const MENU_SLUG   = 'update-logger';
	const PER_PAGE    = 40;

	/**
	 * Per-request dedup so the same overwrite-install isn't logged twice when both
	 * upgrader_process_complete and upgrader_overwrote_package fire for it.
	 *
	 * @var array<string,bool>
	 */
	private static array $logged_keys = [];

	/* ===========================================================
	 *  Bootstrap
	 * =========================================================== */

	public static function init(): void
	{
		add_action('admin_init', [__CLASS__, 'maybe_create_table']);
		add_action('plugins_loaded', [__CLASS__, 'load_textdomain']);

		// Version snapshot — only on pages where a manual update can occur.
		add_action('load-update.php',         [__CLASS__, 'snapshot_versions']);
		add_action('load-update-core.php',    [__CLASS__, 'snapshot_versions']);
		add_action('load-plugins.php',        [__CLASS__, 'snapshot_versions']);
		add_action('load-themes.php',         [__CLASS__, 'snapshot_versions']);
		add_action('load-plugin-install.php', [__CLASS__, 'snapshot_versions']);
		add_action('load-theme-install.php',  [__CLASS__, 'snapshot_versions']);

		// Auto-updates — the hook provides both old and new version numbers.
		add_action('automatic_updates_complete', [__CLASS__, 'on_auto_update']);

		// Manual updates via admin (single & bulk).
		add_action('upgrader_process_complete', [__CLASS__, 'on_manual_update'], 10, 2);

		// ZIP-upload reinstalls — canonical hook fired by Plugin_Upgrader::install() / Theme_Upgrader::install()
		// after a successful overwrite. Belt-and-suspenders alongside upgrader_process_complete because some
		// hosting setups appear to suppress the latter for the install path.
		add_action('upgrader_overwrote_package', [__CLASS__, 'on_overwrite_package'], 10, 3);

		// Admin log page.
		if (is_multisite()) {
			add_action('network_admin_menu', [__CLASS__, 'register_menu']);
		} else {
			add_action('admin_menu', [__CLASS__, 'register_menu']);
		}
	}

	/* ===========================================================
	 *  Translations
	 * =========================================================== */

	public static function load_textdomain(): void
	{
		load_plugin_textdomain(self::TEXT_DOMAIN, false, dirname(plugin_basename(__FILE__)) . '/languages');
	}

	/* ===========================================================
	 *  Database table
	 * =========================================================== */

	private static function table_name(): string
	{
		global $wpdb;
		// On multisite we use base_prefix → one table for the entire network.
		return $wpdb->base_prefix . 'update_log';
	}

	public static function maybe_create_table(): void
	{
		if (get_site_option(self::OPTION_KEY) === self::DB_VERSION) {
			return;
		}

		global $wpdb;
		$table   = self::table_name();
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			logged_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			update_type VARCHAR(20)     NOT NULL COMMENT 'core | plugin | theme',
			slug        VARCHAR(255)    NOT NULL,
			old_version VARCHAR(40)     NOT NULL DEFAULT '',
			new_version VARCHAR(40)     NOT NULL DEFAULT '',
			status      VARCHAR(10)     NOT NULL DEFAULT 'ok' COMMENT 'ok | fail',
			method      VARCHAR(10)     NOT NULL DEFAULT 'auto' COMMENT 'auto | manual',
			PRIMARY KEY (id),
			KEY idx_logged_at (logged_at),
			KEY idx_type_slug (update_type, slug)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta($sql);

		update_site_option(self::OPTION_KEY, self::DB_VERSION);
	}

	/* ===========================================================
	 *  Version snapshot (for manual updates)
	 * =========================================================== */

	public static function snapshot_versions(): void
	{
		$snapshot = [];

		// Plugins.
		if (! function_exists('get_plugins')) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		foreach (get_plugins() as $file => $data) {
			$snapshot['plugin'][$file] = $data['Version'] ?? '';
		}

		// Themes.
		foreach (wp_get_themes() as $stylesheet => $theme) {
			$snapshot['theme'][$stylesheet] = $theme->get('Version');
		}

		// Core.
		$snapshot['core'] = get_bloginfo('version');

		set_site_transient(self::SNAP_KEY, $snapshot, DAY_IN_SECONDS);
	}

	/* ===========================================================
	 *  Logging — automatic updates
	 * =========================================================== */

	public static function on_auto_update(array $results): void
	{
		foreach ($results as $type => $items) {
			foreach ($items as $item) {
				if ('translation' === $type) {
					$parent_type = $item->item->type ?? '?';
					$slug        = ($item->item->slug ?? 'unknown') . ' (' . ($item->item->language ?? '?') . ')';
					self::insert_row(
						'translation',
						$slug,
						$item->item->version ?? '',
						$item->item->version ?? '',
						! empty($item->result) ? 'ok' : 'fail',
						'auto'
					);
				} else {
					$slug = self::resolve_slug($type, $item);
					$old  = $item->item->current_version ?? '';
					$new  = $item->item->new_version     ?? '';
					$status = ! empty($item->result) ? 'ok' : 'fail';
					if ($old !== '' && $old === $new) {
						$status = 'fail'; // Version unchanged — likely a license issue.
					}
					self::insert_row($type, $slug, $old, $new, $status, 'auto');
				}
			}
		}

		// Refresh snapshot after update.
		self::snapshot_versions();
	}

	/* ===========================================================
	 *  Logging — manual updates
	 * =========================================================== */

	public static function on_manual_update($upgrader, array $hook_extra): void
	{
		$action = $hook_extra['action'] ?? '';
		$type   = $hook_extra['type']   ?? '';

		// Diagnostic capture (1.1.8) — record every fire before any guard so the user
		// can see in the admin UI whether the hook reaches us at all.
		self::record_hook_fire('upgrader_process_complete', [
			'action'           => $action,
			'type'             => $type,
			'has_plugin'       => isset($hook_extra['plugin']),
			'has_plugins'      => isset($hook_extra['plugins']),
			'has_themes'       => isset($hook_extra['themes']),
			'clear_destination' => is_object($upgrader) && isset($upgrader->result['clear_destination'])
				? (bool) $upgrader->result['clear_destination']
				: null,
			'destination_name' => is_object($upgrader) && isset($upgrader->result['destination_name'])
				? (string) $upgrader->result['destination_name']
				: '',
		]);

		// Accept two paths:
		//   - regular bulk/single updates (Plugins → Update, and auto-updates that
		//     internally route through bulk_upgrade) — action='update'.
		//   - ZIP-upload reinstalls that overwrite an existing plugin/theme — action='install'
		//     with $upgrader->result['clear_destination'] === true. WordPress does NOT add
		//     an 'overwrite' key to $hook_extra; the only reliable signal is on the upgrader.
		$is_overwrite_install = (
			'install' === $action
			&& is_object($upgrader)
			&& isset($upgrader->result['clear_destination'])
			&& true === $upgrader->result['clear_destination']
		);

		if ('update' !== $action && ! $is_overwrite_install) {
			return;
		}

		$snapshot = get_site_transient(self::SNAP_KEY) ?: [];

		// Fallback: if the snapshot is missing (WP-CLI, expired transient),
		// try the native WP update transient which keeps old versions in ->checked.
		$wp_update_transient = null;
		if ('plugin' === $type && empty($snapshot['plugin'])) {
			$wp_update_transient = get_site_transient('update_plugins');
		} elseif ('theme' === $type && empty($snapshot['theme'])) {
			$wp_update_transient = get_site_transient('update_themes');
		}

		switch ($type) {

			case 'plugin':
				// action=update populates $hook_extra with plugins[] (bulk) or plugin (single).
				// action=install (overwrite) provides neither — derive the plugin file from
				// the upgrader, which has scanned the unpacked package after install.
				$plugins = $hook_extra['plugins'] ?? (isset($hook_extra['plugin']) ? [$hook_extra['plugin']] : []);
				if (empty($plugins) && $is_overwrite_install && method_exists($upgrader, 'plugin_info')) {
					$derived = $upgrader->plugin_info();
					if (is_string($derived) && '' !== $derived) {
						$plugins = [$derived];
					}
				}
				if (! function_exists('get_plugin_data')) {
					require_once ABSPATH . 'wp-admin/includes/plugin.php';
				}
				foreach ($plugins as $file) {
					$old = $snapshot['plugin'][$file]
						?? $wp_update_transient->checked[$file]
						?? '?';
					$new = '';
					$abs = WP_PLUGIN_DIR . '/' . $file;
					if (file_exists($abs)) {
						$data = get_plugin_data($abs, false, false);
						$new  = $data['Version'] ?? '';
					}
					$slug = dirname($file);
					if ('.' === $slug) {
						$slug = basename($file, '.php');
					}
					$status = $new ? 'ok' : 'fail';
					if ($old !== '' && $old !== '?' && $old === $new) {
						$status = 'fail'; // Version unchanged — likely a license issue.
					}
					self::insert_row('plugin', $slug, $old, $new, $status, 'manual');
				}
				break;

			case 'theme':
				$themes = $hook_extra['themes'] ?? (isset($hook_extra['theme']) ? [$hook_extra['theme']] : []);
				if (empty($themes) && $is_overwrite_install && method_exists($upgrader, 'theme_info')) {
					$derived = $upgrader->theme_info();
					if ($derived instanceof WP_Theme) {
						$themes = [$derived->get_stylesheet()];
					}
				}
				foreach ($themes as $stylesheet) {
					$old   = $snapshot['theme'][$stylesheet]
						?? $wp_update_transient->checked[$stylesheet]
						?? '?';
					$theme = wp_get_theme($stylesheet);
					$new   = $theme->exists() ? $theme->get('Version') : '';
					$status = $new ? 'ok' : 'fail';
					if ($old !== '' && $old !== '?' && $old === $new) {
						$status = 'fail'; // Version unchanged — likely a license issue.
					}
					self::insert_row('theme', $stylesheet, $old, $new, $status, 'manual');
				}
				break;

			case 'core':
				$old = $snapshot['core'] ?? '?';
				$new = get_bloginfo('version');
				self::insert_row('core', 'wordpress', $old, $new, ($old === $new ? 'fail' : 'ok'), 'manual');
				break;
		}

		// Refresh snapshot.
		self::snapshot_versions();
	}

	/* ===========================================================
	 *  Logging — ZIP upload overwrite (canonical fallback)
	 * =========================================================== */

	/**
	 * Fired by Plugin_Upgrader::install() / Theme_Upgrader::install() after a successful
	 * overwrite-install. WordPress only passes ($package, $new_data, 'plugin'|'theme') —
	 * no upgrader instance, no file path — so we match new_data against the installed
	 * plugin/theme catalog by Name+Version to recover the slug/file.
	 */
	public static function on_overwrite_package($package, $new_data, $package_type): void
	{
		self::record_hook_fire('upgrader_overwrote_package', [
			'package_type' => (string) $package_type,
			'name'         => is_array($new_data) ? ($new_data['Name'] ?? '') : '',
			'version'      => is_array($new_data) ? ($new_data['Version'] ?? '') : '',
		]);

		if (! is_array($new_data)) {
			return;
		}

		$snapshot = get_site_transient(self::SNAP_KEY) ?: [];

		if ('plugin' === $package_type) {
			$file = self::find_plugin_file_by_data($new_data);
			if ('' === $file) {
				return; // Cannot resolve which plugin was overwritten.
			}
			$slug = dirname($file);
			if ('.' === $slug) {
				$slug = basename($file, '.php');
			}
			$old = $snapshot['plugin'][$file] ?? '?';
			if ('?' === $old) {
				$wp_up = get_site_transient('update_plugins');
				if ($wp_up && isset($wp_up->checked[$file])) {
					$old = $wp_up->checked[$file];
				}
			}
			$new = (string) ($new_data['Version'] ?? '');
			$status = $new ? 'ok' : 'fail';
			if ($old !== '' && $old !== '?' && $old === $new) {
				$status = 'fail';
			}
			self::insert_row('plugin', $slug, $old, $new, $status, 'manual');
		} elseif ('theme' === $package_type) {
			$stylesheet = self::find_theme_stylesheet_by_data($new_data);
			if ('' === $stylesheet) {
				return;
			}
			$old = $snapshot['theme'][$stylesheet] ?? '?';
			if ('?' === $old) {
				$wp_up = get_site_transient('update_themes');
				if ($wp_up && isset($wp_up->checked[$stylesheet])) {
					$old = $wp_up->checked[$stylesheet];
				}
			}
			$new = (string) ($new_data['Version'] ?? '');
			$status = $new ? 'ok' : 'fail';
			if ($old !== '' && $old !== '?' && $old === $new) {
				$status = 'fail';
			}
			self::insert_row('theme', $stylesheet, $old, $new, $status, 'manual');
		}

		self::snapshot_versions();
	}

	/* ===========================================================
	 *  Helper functions
	 * =========================================================== */

	private static function find_plugin_file_by_data(array $data): string
	{
		if (! function_exists('get_plugins')) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$name    = (string) ($data['Name'] ?? '');
		$version = (string) ($data['Version'] ?? '');
		if ('' === $name) {
			return '';
		}
		foreach (get_plugins() as $file => $info) {
			if (
				($info['Name'] ?? '') === $name
				&& ($version === '' || ($info['Version'] ?? '') === $version)
			) {
				return $file;
			}
		}
		return '';
	}

	private static function find_theme_stylesheet_by_data(array $data): string
	{
		$name    = (string) ($data['Name'] ?? '');
		$version = (string) ($data['Version'] ?? '');
		if ('' === $name) {
			return '';
		}
		foreach (wp_get_themes() as $stylesheet => $theme) {
			if (
				$theme->get('Name') === $name
				&& ($version === '' || $theme->get('Version') === $version)
			) {
				return $stylesheet;
			}
		}
		return '';
	}

	/**
	 * Diagnostic ring buffer (1.1.8). Records the last HOOK_LOG_MAX upgrader hook fires so
	 * the admin can confirm what reaches the plugin even when no row gets inserted.
	 */
	private static function record_hook_fire(string $hook, array $data): void
	{
		$entries = get_site_option(self::HOOK_LOG_KEY, []);
		if (! is_array($entries)) {
			$entries = [];
		}
		array_unshift($entries, [
			'ts'   => current_time('mysql'),
			'hook' => $hook,
			'data' => $data,
		]);
		$entries = array_slice($entries, 0, self::HOOK_LOG_MAX);
		update_site_option(self::HOOK_LOG_KEY, $entries);
	}

	private static function resolve_slug(string $type, $item): string
	{
		if ('core' === $type) {
			return 'wordpress';
		}
		return $item->item->slug
			?? $item->item->theme
			?? $item->item->stylesheet
			?? 'unknown';
	}

	/**
	 * Determine update importance based on semver diff.
	 *
	 * @return array{level: int, label: string, color: string}
	 */
	private static function get_importance(string $old, string $new): array
	{
		$parse = static function (string $v): array {
			// Strip leading 'v' and any pre-release/meta suffixes for comparison.
			$v = ltrim($v, 'vV');
			$parts = explode('.', $v);
			return [
				(int) ($parts[0] ?? 0),
				(int) ($parts[1] ?? 0),
				(int) ($parts[2] ?? 0),
			];
		};

		if ('' === $old || '' === $new || '?' === $old || '?' === $new) {
			return ['level' => 0, 'label' => '?', 'color' => '#999'];
		}

		[$oldMajor, $oldMinor] = $parse($old);
		[$newMajor, $newMinor] = $parse($new);

		if ($newMajor !== $oldMajor) {
			return ['level' => 1, 'label' => __('Major', self::TEXT_DOMAIN), 'color' => '#dc3232'];
		}
		if ($newMinor !== $oldMinor) {
			return ['level' => 2, 'label' => __('Minor', self::TEXT_DOMAIN), 'color' => '#f0a500'];
		}
		return ['level' => 3, 'label' => __('Patch', self::TEXT_DOMAIN), 'color' => '#46b450'];
	}

	private static function insert_row(
		string $type,
		string $slug,
		string $old,
		string $new,
		string $status,
		string $method
	): void {
		// Per-request dedup: when an upload-overwrite triggers both upgrader_process_complete
		// and upgrader_overwrote_package in the same request, both callbacks would otherwise
		// insert identical rows. Key on everything that uniquely identifies the event.
		$key = "{$method}|{$type}|{$slug}|{$old}|{$new}|{$status}";
		if (isset(self::$logged_keys[$key])) {
			return;
		}
		self::$logged_keys[$key] = true;

		global $wpdb;
		$wpdb->insert(
			self::table_name(),
			[
				'update_type' => $type,
				'slug'        => $slug,
				'old_version' => $old,
				'new_version' => $new,
				'status'      => $status,
				'method'      => $method,
			],
			['%s', '%s', '%s', '%s', '%s', '%s']
		);
	}

	/* ===========================================================
	 *  Admin page
	 * =========================================================== */

	public static function register_menu(): void
	{
		add_submenu_page(
			is_multisite() ? 'settings.php' : 'tools.php',
			__('Update Log', self::TEXT_DOMAIN),
			__('Update Log', self::TEXT_DOMAIN),
			is_multisite() ? 'manage_network' : 'manage_options',
			self::MENU_SLUG,
			[__CLASS__, 'render_page']
		);
	}

	/**
	 * Fetch distinct year-month pairs from the log for the date dropdown.
	 */
	private static function get_available_months(): array
	{
		global $wpdb;
		$table = self::table_name();

		return $wpdb->get_results(
			"SELECT DISTINCT YEAR(logged_at) AS year, MONTH(logged_at) AS month
			 FROM {$table}
			 ORDER BY year DESC, month DESC"
		);
	}

	/**
	 * Resolve a human-readable display name for a slug in the admin table.
	 */
	private static function get_display_name(string $slug, string $type): string
	{
		if ('plugin' === $type) {
			if (! function_exists('get_plugins')) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			foreach (get_plugins() as $file => $data) {
				$dir = dirname($file);
				if ('.' === $dir) {
					$dir = basename($file, '.php');
				}
				if ($dir === $slug) {
					return $data['Name'] ?? $slug;
				}
			}
		} elseif ('theme' === $type) {
			$theme = wp_get_theme($slug);
			if ($theme->exists()) {
				return $theme->get('Name');
			}
		}
		return $slug;
	}

	public static function render_page(): void
	{
		global $wpdb;
		$table = self::table_name();

		// --- Filters -------------------------------------------------

		// Type filter.
		$allowed_types = ['core', 'plugin', 'theme', 'translation'];
		$filter_type   = isset($_GET['type']) && in_array($_GET['type'], $allowed_types, true)
			? sanitize_text_field($_GET['type'])
			: '';

		// Date filter (year-month).
		$filter_ym = isset($_GET['ym']) ? sanitize_text_field($_GET['ym']) : '';
		$filter_year  = 0;
		$filter_month = 0;
		if (preg_match('/^(\d{4})-(\d{1,2})$/', $filter_ym, $m)) {
			$filter_year  = (int) $m[1];
			$filter_month = (int) $m[2];
		}

		// Pagination.
		$page_num = max(1, absint($_GET['paged'] ?? 1));
		$offset   = ($page_num - 1) * self::PER_PAGE;

		// --- Build WHERE clause --------------------------------------

		$conditions = [];
		$values     = [];

		if ($filter_type) {
			$conditions[] = 'update_type = %s';
			$values[]     = $filter_type;
		}

		if ($filter_year && $filter_month) {
			$conditions[] = 'YEAR(logged_at) = %d AND MONTH(logged_at) = %d';
			$values[]     = $filter_year;
			$values[]     = $filter_month;
		}

		$where = '';
		if ($conditions) {
			$where = 'WHERE ' . implode(' AND ', $conditions);
			if ($values) {
				$where = $wpdb->prepare($where, ...$values);
			}
		}

		// --- Query ---------------------------------------------------

		$total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} {$where}");
		$rows  = $wpdb->get_results(
			"SELECT * FROM {$table} {$where}
			 ORDER BY logged_at DESC
			 LIMIT " . self::PER_PAGE . " OFFSET {$offset}"
		);

		$available_months = self::get_available_months();

		$base_url = is_multisite()
			? network_admin_url('settings.php?page=' . self::MENU_SLUG)
			: admin_url('tools.php?page=' . self::MENU_SLUG);

		// --- Diagnostic ring buffer (1.1.8) --------------------------

		if (
			isset($_GET['ul_clear_hook_log'], $_GET['_wpnonce'])
			&& wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'ul_clear_hook_log')
		) {
			delete_site_option(self::HOOK_LOG_KEY);
			wp_safe_redirect(remove_query_arg(['ul_clear_hook_log', '_wpnonce']));
			exit;
		}

		$hook_log = get_site_option(self::HOOK_LOG_KEY, []);
		if (! is_array($hook_log)) {
			$hook_log = [];
		}

		// --- Output --------------------------------------------------

		// Column labels for responsive data-label attributes.
		$col_date       = __('Date',        self::TEXT_DOMAIN);
		$col_type       = __('Type',        self::TEXT_DOMAIN);
		$col_slug       = __('Slug',        self::TEXT_DOMAIN);
		$col_old        = __('Old version', self::TEXT_DOMAIN);
		$col_new        = __('New version', self::TEXT_DOMAIN);
		$col_importance = __('Importance',  self::TEXT_DOMAIN);
		$col_status     = __('Status',      self::TEXT_DOMAIN);
		$col_method     = __('Method',      self::TEXT_DOMAIN);

?>
		<style>
			.ul-table {
				border-collapse: collapse;
				width: 100%;
			}

			.ul-table th,
			.ul-table td {
				padding: 8px 10px;
				text-align: left;
			}

			.ul-table code {
				word-break: break-all;
			}

			@media screen and (max-width: 782px) {
				.ul-table thead {
					display: none;
				}

				.ul-table tr {
					display: block;
					margin-bottom: 12px;
					border: 1px solid #ccd0d4;
					background: #fff;
				}

				.ul-table td {
					display: flex;
					justify-content: space-between;
					padding: 6px 10px;
					border-bottom: 1px solid #f0f0f1;
				}

				.ul-table td::before {
					content: attr(data-label);
					font-weight: 600;
					margin-right: 10px;
					flex-shrink: 0;
				}

				.ul-table td:last-child {
					border-bottom: 0;
				}
			}
		</style>

		<div class="wrap">
			<h1><?php echo esc_html($col_date ? __('Update Log', self::TEXT_DOMAIN) : ''); ?></h1>

			<?php if (! empty($hook_log)) :
				$clear_url = wp_nonce_url(
					add_query_arg('ul_clear_hook_log', '1', $base_url),
					'ul_clear_hook_log'
				);
			?>
				<div class="notice notice-info" style="margin:12px 0;padding:10px 14px;">
					<p style="margin:0 0 8px;font-weight:600;">
						Diagnostic — last <?php echo (int) count($hook_log); ?> upgrader hook fire(s)
						<a href="<?php echo esc_url($clear_url); ?>" style="float:right;font-weight:400;">Clear</a>
					</p>
					<ol style="margin:0 0 0 22px;padding:0;font-family:Consolas,Menlo,monospace;font-size:12px;line-height:1.5;">
						<?php foreach ($hook_log as $e) :
							$ts   = isset($e['ts'])   ? (string) $e['ts']   : '';
							$hook = isset($e['hook']) ? (string) $e['hook'] : '';
							$data = isset($e['data']) && is_array($e['data']) ? $e['data'] : [];
						?>
							<li>
								<strong><?php echo esc_html($ts); ?></strong>
								— <code><?php echo esc_html($hook); ?></code>
								<?php echo esc_html(wp_json_encode($data, JSON_UNESCAPED_UNICODE)); ?>
							</li>
						<?php endforeach; ?>
					</ol>
				</div>
			<?php endif; ?>

			<ul class="subsubsub">
				<li>
					<a href="<?php echo esc_url(remove_query_arg('type', $base_url)); ?>"
						<?php echo ! $filter_type ? 'class="current"' : ''; ?>>
						<?php esc_html_e('All', self::TEXT_DOMAIN); ?>
					</a> |
				</li>
				<?php foreach ($allowed_types as $i => $t) : ?>
					<li>
						<a href="<?php echo esc_url(add_query_arg('type', $t, $base_url)); ?>"
							<?php echo $filter_type === $t ? 'class="current"' : ''; ?>>
							<?php echo esc_html(translate(ucfirst($t), self::TEXT_DOMAIN)); ?>
						</a><?php echo $i < count($allowed_types) - 1 ? ' |' : ''; ?>
					</li>
				<?php endforeach; ?>
			</ul>

			<div class="tablenav top" style="clear:both">
				<div class="alignleft actions">
					<label for="filter-by-date" class="screen-reader-text">
						<?php esc_html_e('Filter by date', self::TEXT_DOMAIN); ?>
					</label>
					<select name="ym" id="filter-by-date">
						<option value=""><?php esc_html_e('All dates', self::TEXT_DOMAIN); ?></option>
						<?php
						global $wp_locale;
						foreach ($available_months as $row) :
							$val   = sprintf('%d-%d', $row->year, $row->month);
							$label = sprintf(
								__('%1$s %2$d', self::TEXT_DOMAIN),
								$wp_locale->get_month(str_pad($row->month, 2, '0', STR_PAD_LEFT)),
								$row->year
							);
						?>
							<option value="<?php echo esc_attr($val); ?>" <?php selected($val, $filter_ym); ?>>
								<?php echo esc_html($label); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<?php
					$filter_action = $base_url;
					if ($filter_type) {
						$filter_action = add_query_arg('type', $filter_type, $filter_action);
					}
					?>
					<button type="button" class="button" onclick="
						var ym = document.getElementById('filter-by-date').value;
						var url = '<?php echo esc_js($filter_action); ?>';
						if (ym) { url += '&ym=' + encodeURIComponent(ym); }
						window.location = url;
					"><?php esc_html_e('Filter', self::TEXT_DOMAIN); ?></button>
				</div>
			</div>

			<table class="widefat striped ul-table">
				<thead>
					<tr>
						<th><?php echo esc_html($col_date); ?></th>
						<th><?php echo esc_html($col_type); ?></th>
						<th><?php echo esc_html($col_slug); ?></th>
						<th><?php echo esc_html($col_old); ?></th>
						<th><?php echo esc_html($col_new); ?></th>
						<th><?php echo esc_html($col_importance); ?></th>
						<th><?php echo esc_html($col_status); ?></th>
						<th><?php echo esc_html($col_method); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if (empty($rows)) : ?>
						<tr>
							<td colspan="8"><?php esc_html_e('No records yet.', self::TEXT_DOMAIN); ?></td>
						</tr>
					<?php else : ?>
						<?php foreach ($rows as $r) :
							$imp = self::get_importance($r->old_version, $r->new_version);
							$display_name = self::get_display_name($r->slug, $r->update_type);
						?>
							<tr>
								<td data-label="<?php echo esc_attr($col_date); ?>"><?php echo esc_html($r->logged_at); ?></td>
								<td data-label="<?php echo esc_attr($col_type); ?>"><?php echo esc_html($r->update_type); ?></td>
								<td data-label="<?php echo esc_attr($col_slug); ?>">
									<?php echo esc_html($display_name); ?>
									<?php if ($display_name !== $r->slug) : ?>
										<br><code><?php echo esc_html($r->slug); ?></code>
									<?php endif; ?>
								</td>
								<td data-label="<?php echo esc_attr($col_old); ?>"><?php echo esc_html($r->old_version); ?></td>
								<td data-label="<?php echo esc_attr($col_new); ?>"><?php echo esc_html($r->new_version); ?></td>
								<td data-label="<?php echo esc_attr($col_importance); ?>"><span style="color:<?php echo esc_attr($imp['color']); ?>;font-weight:600"><?php echo esc_html($imp['level'] ? $imp['level'] . ' – ' . $imp['label'] : $imp['label']); ?></span></td>
								<td data-label="<?php echo esc_attr($col_status); ?>"><?php echo 'ok' === $r->status ? '<span style="color:#46b450">&#10003;</span>' : '<span style="color:#dc3232">&#10007;</span>'; ?></td>
								<td data-label="<?php echo esc_attr($col_method); ?>"><?php echo esc_html($r->method); ?></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>

			<?php
			$total_pages = (int) ceil($total / self::PER_PAGE);
			if ($total_pages > 1) {
				$pag_base = $base_url;
				if ($filter_type) {
					$pag_base = add_query_arg('type', $filter_type, $pag_base);
				}
				if ($filter_ym) {
					$pag_base = add_query_arg('ym', $filter_ym, $pag_base);
				}

				$page_url = static function (int $p) use ($pag_base): string {
					return add_query_arg('paged', max(1, $p), $pag_base);
				};

				$on_first = $page_num <= 1;
				$on_last  = $page_num >= $total_pages;

				$items_text = sprintf(
					_n('%s item', '%s items', $total, self::TEXT_DOMAIN),
					number_format_i18n($total)
				);

				$paging_text = sprintf(
					/* translators: 1: Current page number, 2: Total page count. */
					_x('%1$s of %2$s', 'paging', self::TEXT_DOMAIN),
					'<span class="current-page">' . esc_html(number_format_i18n($page_num)) . '</span>',
					'<span class="total-pages">' . esc_html(number_format_i18n($total_pages)) . '</span>'
				);
			?>
				<div class="tablenav bottom">
					<div class="tablenav-pages">
						<span class="displaying-num"><?php echo esc_html($items_text); ?></span>
						<span class="pagination-links">
							<?php if ($on_first) : ?>
								<span class="tablenav-pages-navspan button disabled" aria-hidden="true">&laquo;</span>
								<span class="tablenav-pages-navspan button disabled" aria-hidden="true">&lsaquo;</span>
							<?php else : ?>
								<a class="first-page button" href="<?php echo esc_url($page_url(1)); ?>">
									<span class="screen-reader-text"><?php esc_html_e('First page', self::TEXT_DOMAIN); ?></span>
									<span aria-hidden="true">&laquo;</span>
								</a>
								<a class="prev-page button" href="<?php echo esc_url($page_url($page_num - 1)); ?>">
									<span class="screen-reader-text"><?php esc_html_e('Previous page', self::TEXT_DOMAIN); ?></span>
									<span aria-hidden="true">&lsaquo;</span>
								</a>
							<?php endif; ?>

							<span class="paging-input">
								<span class="tablenav-paging-text"><?php echo $paging_text; // safe HTML built above ?></span>
							</span>

							<?php if ($on_last) : ?>
								<span class="tablenav-pages-navspan button disabled" aria-hidden="true">&rsaquo;</span>
								<span class="tablenav-pages-navspan button disabled" aria-hidden="true">&raquo;</span>
							<?php else : ?>
								<a class="next-page button" href="<?php echo esc_url($page_url($page_num + 1)); ?>">
									<span class="screen-reader-text"><?php esc_html_e('Next page', self::TEXT_DOMAIN); ?></span>
									<span aria-hidden="true">&rsaquo;</span>
								</a>
								<a class="last-page button" href="<?php echo esc_url($page_url($total_pages)); ?>">
									<span class="screen-reader-text"><?php esc_html_e('Last page', self::TEXT_DOMAIN); ?></span>
									<span aria-hidden="true">&raquo;</span>
								</a>
							<?php endif; ?>
						</span>
					</div>
				</div>
			<?php
			}
			?>
		</div>
<?php
	}
}

Update_Logger::init();
