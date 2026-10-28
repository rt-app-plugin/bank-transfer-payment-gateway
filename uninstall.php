<?php
/**
 * Fired when the plugin is uninstalled.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
	exit;
}

// Clear scheduled cron events
wp_clear_scheduled_hook('bank_transfer_cron_event');

// Delete ONLY Bank Transfer plugin options (do not delete ByteNFT plugin options)
$options_to_delete = [
	'woocommerce_bank_transfer_settings',
	'woocommerce_bank_transfer_payment_gateway_accounts',
	'bank_transfer_group_id',
	'bank_transfer_unique_id',
	'bank_transfer_schema_version',
];

foreach ($options_to_delete as $option_name) {
	delete_option($option_name);
}

// Delete plugin transients safely using esc_like and prepare
global $wpdb;
$wildcard = '%' . $wpdb->esc_like('bank_transfer') . '%';
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND (option_name LIKE '_transient_%' OR option_name LIKE '_transient_timeout_%')",
		$wildcard
	)
);
