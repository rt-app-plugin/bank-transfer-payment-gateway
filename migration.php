<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

/**
 * Schema version written once every step below has run. Bump it whenever a
 * step is added; the main plugin file runs the steps when the stored version
 * is older.
 */
define('BANK_TRANSFER_SCHEMA_VERSION', '1.3.0');

/**
 * BK-12: this plugin's own payment-link table. The pre-1.0.2 plugin shared
 * {prefix}order_payment_link with the NFT checkout plugin, each defining the
 * columns differently, so whichever ran dbDelta last rewrote the other's
 * columns. That table is left untouched for the other plugin.
 */
function bank_transfer_create_tables()
{
	global $wpdb;
	$table_name = bank_transfer_table_name();
	$charset_collate = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE $table_name (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		order_id bigint(20) unsigned NOT NULL,
		uuid varchar(255) NOT NULL DEFAULT '',
		payment_link text DEFAULT '',
		customer_email varchar(100) DEFAULT '',
		amount varchar(50) DEFAULT '0.00',
		created_at datetime DEFAULT CURRENT_TIMESTAMP,
		PRIMARY KEY  (id),
		KEY order_id (order_id),
		KEY uuid (uuid(191))
	) $charset_collate;";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta($sql);
}

/**
 * Copy this plugin's rows out of the shared legacy table (orders paid through
 * this gateway only), once. Rows already present in the new table are kept.
 */
function bank_transfer_migrate_legacy_rows()
{
	global $wpdb;

	if (get_option('bank_transfer_legacy_rows_migrated')) {
		return;
	}

	$legacy_table = esc_sql($wpdb->prefix . 'order_payment_link');
	$new_table    = esc_sql(bank_transfer_table_name());

	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $legacy_table));
	if ($exists === $legacy_table) {
		$rows = $wpdb->get_results("SELECT order_id, uuid, payment_link, customer_email, amount, created_at FROM {$legacy_table}", ARRAY_A);
		foreach ((array) $rows as $row) {
			$order = wc_get_order((int) ($row['order_id'] ?? 0));
			if (!bank_transfer_is_gateway_order($order)) {
				continue;
			}
			$already = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$new_table} WHERE order_id = %d LIMIT 1", (int) $row['order_id']));
			if ($already) {
				continue;
			}
			$wpdb->insert($new_table, [
				'order_id'       => (int) $row['order_id'],
				'uuid'           => sanitize_text_field((string) ($row['uuid'] ?? '')),
				'payment_link'   => esc_url_raw((string) ($row['payment_link'] ?? '')),
				'customer_email' => sanitize_email((string) ($row['customer_email'] ?? '')),
				'amount'         => sanitize_text_field((string) ($row['amount'] ?? '0.00')),
				'created_at'     => !empty($row['created_at']) ? $row['created_at'] : current_time('mysql', 1),
			]);
		}
	}
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	update_option('bank_transfer_legacy_rows_migrated', '1');
}

/**
 * Normalise this plugin's own settings. BK-09: nothing is imported from the
 * ByteNFT / Unified plugin options any more; a merchant who used that plugin
 * enters their RT keys here themselves.
 */
function bank_transfer_migrate_old_settings()
{
	// BK-13: the gateway id changed in 1.0.2, so WooCommerce now reads the
	// settings from a new option. Carry the saved settings over once.
	$settings = get_option(BANK_TRANSFER_SETTINGS_OPTION, []);
	if (empty($settings)) {
		$legacy_settings = get_option(BANK_TRANSFER_LEGACY_SETTINGS_OPTION, []);
		if (is_string($legacy_settings)) {
			$legacy_settings = maybe_unserialize($legacy_settings);
		}
		if (is_array($legacy_settings) && !empty($legacy_settings)) {
			$settings = $legacy_settings;
		}
	}
	if (is_array($settings)) {
		// Keys live in the accounts option only.
		unset(
			$settings['public_key'],
			$settings['secret_key'],
			$settings['sandbox_public_key'],
			$settings['sandbox_secret_key'],
			$settings['accounts']
		);
		if (!empty($settings)) {
			update_option(BANK_TRANSFER_SETTINGS_OPTION, $settings);
		}
	}

	$accounts = get_option(BANK_TRANSFER_ACCOUNTS_OPTION);
	if ($accounts) {
		$accounts = maybe_unserialize($accounts);

		if (is_array($accounts) && !empty($accounts)) {
			$enhanced_accounts = array_map(function ($account) {
				if (!is_array($account)) {
					return $account;
				}
				$account['live_status'] = $account['live_status'] ?? 'active';
				$account['sandbox_status'] = $account['sandbox_status'] ?? 'active';
				$account['has_sandbox'] = (!empty($account['sandbox_public_key']) && !empty($account['sandbox_secret_key'])) ? 'on' : 'off';
				$account['priority'] = $account['priority'] ?? 1;
				$account['title'] = $account['title'] ?? 'Default Account';
				if (empty($account['unique_id'])) {
					$account['unique_id'] = 'acc_' . wp_generate_uuid4();
				}
				return $account;
			}, $accounts);

			update_option(BANK_TRANSFER_ACCOUNTS_OPTION, $enhanced_accounts);
		}
	}

	bank_transfer_trigger_sync();
}

function bank_transfer_trigger_sync()
{
	// BK-17: nothing is sent to RT before the merchant has entered keys.
	if (!bank_transfer_is_configured()) {
		return;
	}

	if (get_transient('bank_transfer_sync_lock')) {
		return;
	}
	set_transient('bank_transfer_sync_lock', true, 5 * MINUTE_IN_SECONDS);

	// Never perform blocking remote HTTP inside plugins_loaded / front-end requests.
	// Defer the account sync to a single WP-Cron event handled by
	// BANK_TRANSFER_PAYMENT_GATEWAY_Loader::handle_cron_event(). WP de-duplicates identical events
	// already due within 10 minutes, and the transient above stops repeat scheduling.
	wp_schedule_single_event(time() + 30, BANK_TRANSFER_CRON_HOOK);
}

/**
 * Run every schema step the stored version has not seen yet.
 */
function bank_transfer_maybe_upgrade()
{
	$current_schema = get_option('bank_transfer_schema_version', '0');
	if (version_compare($current_schema, BANK_TRANSFER_SCHEMA_VERSION, '>=')) {
		return;
	}

	bank_transfer_create_tables();
	bank_transfer_migrate_legacy_rows();
	bank_transfer_migrate_old_settings();

	// BK-13: the cron hook was renamed; drop the old schedule and start the new one.
	wp_clear_scheduled_hook('bank_transfer_cron_event');
	if (class_exists('BANK_TRANSFER_PAYMENT_GATEWAY_Loader')) {
		BANK_TRANSFER_PAYMENT_GATEWAY_Loader::get_instance()->activate_cron_job();
	}

	update_option('bank_transfer_schema_version', BANK_TRANSFER_SCHEMA_VERSION);
}

function bank_transfer_on_plugin_activate() {
	bank_transfer_create_tables();
	bank_transfer_migrate_legacy_rows();
	bank_transfer_migrate_old_settings();
	update_option('bank_transfer_schema_version', BANK_TRANSFER_SCHEMA_VERSION);

	if (class_exists('BANK_TRANSFER_PAYMENT_GATEWAY_Loader')) {
		BANK_TRANSFER_PAYMENT_GATEWAY_Loader::get_instance()->activate_cron_job();
		if (bank_transfer_is_configured()) {
			BANK_TRANSFER_PAYMENT_GATEWAY_Loader::get_instance()->bank_transfer_send_plugin_status(1, 0);
		}
	}
}

function bank_transfer_on_plugin_deactivate() {
	if (class_exists('BANK_TRANSFER_PAYMENT_GATEWAY_Loader')) {
		BANK_TRANSFER_PAYMENT_GATEWAY_Loader::get_instance()->deactivate_cron_job();
		if (bank_transfer_is_configured()) {
			BANK_TRANSFER_PAYMENT_GATEWAY_Loader::get_instance()->bank_transfer_send_plugin_status(0, 0);
		}
	}
}

register_activation_hook(BANK_TRANSFER_PAYMENT_GATEWAY_FILE, 'bank_transfer_on_plugin_activate');
register_deactivation_hook(BANK_TRANSFER_PAYMENT_GATEWAY_FILE, 'bank_transfer_on_plugin_deactivate');
