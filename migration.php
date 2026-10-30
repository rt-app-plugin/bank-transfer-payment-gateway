<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

function bank_transfer_create_tables()
{
	global $wpdb;
	$table_name = $wpdb->prefix . 'order_payment_link';
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
	update_option('bank_transfer_schema_version', '1.2.0');
}

/**
 * Check if the legacy ByteNFT plugin is active.
 *
 * @return bool
 */
function bank_transfer_is_bytenft_active()
{
	if (!function_exists('is_plugin_active')) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	$active_plugins = (array) get_option('active_plugins', []);
	if (is_multisite()) {
		$network_active = (array) get_site_option('active_sitewide_plugins', []);
		$active_plugins = array_merge($active_plugins, array_keys($network_active));
	}
	foreach ($active_plugins as $plugin) {
		if (stripos($plugin, 'bytenft') !== false || stripos($plugin, 'byte-nft') !== false) {
			return true;
		}
	}
	return false;
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
				return $account;
			}, $accounts);

			update_option('woocommerce_bank_transfer_payment_gateway_accounts', $enhanced_accounts);
			bank_transfer_trigger_sync();
			return;
		}
	}

	// BT-46: Only import from ByteNFT once, only if ByteNFT plugin is deactivated, and never import from Unified gateway
	$migrated = get_option('bank_transfer_bytenft_migrated');
	if (!$migrated) {
		update_option('bank_transfer_bytenft_migrated', 'yes');

		if (!bank_transfer_is_bytenft_active()) {
			$old_accounts = get_option('woocommerce_bytenft_payment_gateway_accounts');
			$imported = false;
			if ($old_accounts) {
				$old_accounts = maybe_unserialize($old_accounts);
				if (is_array($old_accounts) && !empty($old_accounts)) {
					update_option('woocommerce_bank_transfer_payment_gateway_accounts', $old_accounts);
					$imported = true;
				}
			}

			$bank_transfer_settings = get_option('woocommerce_bank_transfer_settings');
			if (empty($bank_transfer_settings)) {
				$old_settings = get_option('woocommerce_bytenft_settings', []);
				if (is_array($old_settings) && !empty($old_settings)) {
					unset(
						$old_settings['public_key'],
						$old_settings['secret_key'],
						$old_settings['sandbox_public_key'],
						$old_settings['sandbox_secret_key'],
						$old_settings['accounts']
					);
					update_option('woocommerce_bank_transfer_settings', $old_settings);
					$imported = true;
				}
			}

			$group_id = get_option('bank_transfer_group_id');
			if (empty($group_id)) {
				$old_group = get_option('bytenft_group_id');
				if ($old_group) {
					update_option('bank_transfer_group_id', $old_group);
				}
			}

			if ($imported) {
				update_option('bank_transfer_bytenft_migration_notice', 'yes');
			}
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

add_action('admin_notices', function () {
	if (get_option('bank_transfer_bytenft_migration_notice') === 'yes') {
		if (isset($_GET['bank_transfer_dismiss_migration_notice']) && check_admin_referer('bank_transfer_dismiss_migration')) {
			delete_option('bank_transfer_bytenft_migration_notice');
			return;
		}
		$dismiss_url = wp_nonce_url(add_query_arg('bank_transfer_dismiss_migration_notice', '1'), 'bank_transfer_dismiss_migration');
		echo '<div class="notice notice-info is-dismissible"><p>' .
			esc_html__('Bank Transfer Payment Gateway: Settings and accounts have been successfully imported from the deactivated ByteNFT plugin.', 'bank-transfer-payment-gateway') .
			' <a href="' . esc_url($dismiss_url) . '">' . esc_html__('Dismiss', 'bank-transfer-payment-gateway') . '</a></p></div>';
	}
});

function bank_transfer_trigger_sync()
{
	if (get_transient('bank_transfer_sync_lock')) {
		return;
	}
	set_transient('bank_transfer_sync_lock', true, 5 * MINUTE_IN_SECONDS);

	if (class_exists('BANK_TRANSFER_PAYMENT_GATEWAY_Loader')) {
		$loader = BANK_TRANSFER_PAYMENT_GATEWAY_Loader::get_instance();
		if (method_exists($loader, 'handle_cron_event')) {
			$loader->handle_cron_event();
		}
	}
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
