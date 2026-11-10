<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

function bank_transfer_create_tables()
{
	global $wpdb;
	$table_name = $wpdb->prefix . 'rt_bank_transfer_payment_link';
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

	// BK-12: One-time migration from legacy shared table
	$old_table = $wpdb->prefix . 'order_payment_link';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
	if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $old_table)) === $old_table) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$has_new_data = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table_name}");
		if ($has_new_data === 0) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->query("INSERT IGNORE INTO {$table_name} (order_id, uuid, payment_link, customer_email, amount, created_at) SELECT order_id, uuid, payment_link, customer_email, amount, created_at FROM {$old_table}");
		}
	}

	update_option('bank_transfer_schema_version', '1.3.0');
}

function bank_transfer_migrate_old_settings()
{
	$accounts = get_option('woocommerce_bank_transfer_payment_gateway_accounts');

	if ($accounts) {
		$accounts = maybe_unserialize($accounts);

		if (is_array($accounts) && !empty($accounts)) {
			$enhanced_accounts = array_map(function ($account) {
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

			update_option('woocommerce_bank_transfer_payment_gateway_accounts', $enhanced_accounts);
			bank_transfer_trigger_sync();
			return;
		}
	}

	$bank_transfer_settings = get_option('woocommerce_bank_transfer_settings');
	if (is_array($bank_transfer_settings)) {
		unset(
			$bank_transfer_settings['public_key'],
			$bank_transfer_settings['secret_key'],
			$bank_transfer_settings['sandbox_public_key'],
			$bank_transfer_settings['sandbox_secret_key'],
			$bank_transfer_settings['accounts']
		);
		update_option('woocommerce_bank_transfer_settings', $bank_transfer_settings);
	}

	bank_transfer_trigger_sync();
}

function bank_transfer_trigger_sync()
{
	if (get_transient('bank_transfer_sync_lock')) {
		return;
	}
	set_transient('bank_transfer_sync_lock', true, 5 * MINUTE_IN_SECONDS);

	// Never perform blocking remote HTTP inside plugins_loaded / front-end requests.
	// Defer the account sync to a single WP-Cron event; 'bank_transfer_cron_event' is handled by
	// BANK_TRANSFER_PAYMENT_GATEWAY_Loader::handle_cron_event(). WP de-duplicates identical events
	// already due within 10 minutes, and the transient above stops repeat scheduling.
	wp_schedule_single_event(time() + 30, 'bank_transfer_cron_event');
}

function bank_transfer_on_plugin_activate() {
	bank_transfer_create_tables();

	if (function_exists('bank_transfer_migrate_old_settings')) {
		bank_transfer_migrate_old_settings();
	}

	if (class_exists('BANK_TRANSFER_PAYMENT_GATEWAY_Loader')) {
		BANK_TRANSFER_PAYMENT_GATEWAY_Loader::get_instance()->activate_cron_job();
		BANK_TRANSFER_PAYMENT_GATEWAY_Loader::get_instance()->bank_transfer_send_plugin_status(1, 0);
	}
}

function bank_transfer_on_plugin_deactivate() {
	if (class_exists('BANK_TRANSFER_PAYMENT_GATEWAY_Loader')) {
		BANK_TRANSFER_PAYMENT_GATEWAY_Loader::get_instance()->deactivate_cron_job();
		BANK_TRANSFER_PAYMENT_GATEWAY_Loader::get_instance()->bank_transfer_send_plugin_status(0, 0);
	}
}

register_activation_hook(BANK_TRANSFER_PAYMENT_GATEWAY_FILE, 'bank_transfer_on_plugin_activate');
register_deactivation_hook(BANK_TRANSFER_PAYMENT_GATEWAY_FILE, 'bank_transfer_on_plugin_deactivate');