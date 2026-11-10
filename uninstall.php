<?php
/**
 * Fired when the plugin is uninstalled.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
	exit;
}

function bank_transfer_uninstall() {
	global $wpdb;

	// Clear scheduled cron events (current and pre-1.0.2 hook names)
	wp_clear_scheduled_hook('rt_bank_transfer_cron_event');
	wp_clear_scheduled_hook('rt_bank_transfer_async_sync_order');
	wp_clear_scheduled_hook('bank_transfer_cron_event');

	// Delete ONLY this plugin's options
	$options_to_delete = [
		'woocommerce_rt_bank_transfer_settings',
		'woocommerce_bank_transfer_settings',
		'woocommerce_bank_transfer_payment_gateway_accounts',
		'bank_transfer_group_id',
		'bank_transfer_unique_id',
		'bank_transfer_active_account',
		'bank_transfer_schema_version',
		'bank_transfer_legacy_rows_migrated',
	];

	foreach ($options_to_delete as $option_name) {
		delete_option($option_name);
	}

	// This plugin's own payment-link table. The legacy shared table
	// ({prefix}order_payment_link) belongs to another plugin and is left alone.
	$table_name = esc_sql($wpdb->prefix . 'rt_bank_transfer_payment_link');
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query("DROP TABLE IF EXISTS {$table_name}");

	// Delete plugin transients safely using esc_like and prepare
	$wildcard          = '%' . $wpdb->esc_like('bank_transfer') . '%';
	$short_wildcard    = '%' . $wpdb->esc_like('bt_rl_') . '%';
	$transient_like    = $wpdb->esc_like('_transient_') . '%';
	$transient_to_like = $wpdb->esc_like('_transient_timeout_') . '%';

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE (option_name LIKE %s OR option_name LIKE %s) AND (option_name LIKE %s OR option_name LIKE %s)",
			$wildcard,
			$short_wildcard,
			$transient_like,
			$transient_to_like
		)
	);
}
bank_transfer_uninstall();
