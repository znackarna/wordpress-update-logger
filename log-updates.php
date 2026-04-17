<?php

/**
 * Plugin Name:  Update Logger
 * Description:  Logs all WordPress core, plugin and theme updates (auto & manual) to the database.
 * Author:       značkárna s.r.o.
 * Version:      1.1.3
 * Text Domain:  update-logger
 * Domain Path:  /languages
 * Network:      true
 */

defined('ABSPATH') || exit;

final class Update_Logger
{

	const DB_VERSION  = '1.0';
	const OPTION_KEY  = 'update_logger_db_version';
	const SNAP_KEY    = 'update_logger_version_snapshot';
	const TEXT_DOMAIN = 'update-logger';
	const MENU_SLUG   = 'update-logger';
	const PER_PAGE    = 40;

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

		// Accept regular updates and file-upload installs that overwrite an existing plugin/theme.
		$is_overwrite = ! empty($hook_extra['overwrite']);
		if ('update' !== $action && ! ('install' === $action && $is_overwrite)) {
			return;
		}

		$type     = $hook_extra['type'] ?? '';
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
				$plugins = $hook_extra['plugins'] ?? (isset($hook_extra['plugin']) ? [$hook_extra['plugin']] : []);
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
	 *  Helper functions
	 * =========================================================== */

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
				echo '<div class="tablenav bottom"><div class="tablenav-pages">';
				echo paginate_links([
					'base'    => add_query_arg('paged', '%#%', $pag_base),
					'format'  => '',
					'current' => $page_num,
					'total'   => $total_pages,
				]);
				echo '</div></div>';
			}
			?>
		</div>
<?php
	}
}

Update_Logger::init();
