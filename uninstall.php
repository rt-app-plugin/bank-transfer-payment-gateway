<?php
/**
 * Fired when the plugin is uninstalled.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
	exit;
}

function bank_transfer_uninstall() {
	// Clear scheduled cron events
	wp_clear_scheduled_hook('bank_transfer_cron_event');

	
	// Delete ONLY Bank Transfer plugin options (do not delete ByteNFT plugin options)
	$options_to_delete = [
		'woocommerce_bank_transfer_settings',
		'woocommerce_bank_transfer_payment_gateway_accounts',
		'bank_transfer_group_id',
		'bank_transfer_unique_id',
		'bank_transfer_schema_version',
		'bank_transfer_bytenft_migrated',
		'bank_transfer_bytenft_migration_notice',
	];

	foreach ($options_to_delete as $option_name) {
		delete_option($option_name);
	}

	// Delete plugin transients safely using esc_like and prepare
	global $wpdb;
	$wildcard          = '%' . $wpdb->esc_like('bank_transfer') . '%';
	$transient_like    = $wpdb->esc_like('_transient_') . '%';
	$transient_to_like = $wpdb->esc_like('_transient_timeout_') . '%';

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND (option_name LIKE %s OR option_name LIKE %s)",
			$wildcard,
			$transient_like,
			$transient_to_like
		)
	);
}
bank_transfer_uninstall();
