<?php

/**
 * Plugin Name: Bank Transfer Payment Gateway
 * Description: Pay securely with Bank Transfer or Card via popup flow.
 * Author: RT.app
 * Author URI: https://rt.app/
 * Text Domain: bank-transfer-payment-gateway
 * Plugin URI: https://pay.rt.app/
 * Version: 1.0.0
 * License: GPLv3 or later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Copyright (c) 2024 RT.app
 */


if (!defined('ABSPATH')) {
	exit;
}

define('BANK_TRANSFER_PAYMENT_GATEWAY_MIN_PHP_VER', '8.0');
define('BANK_TRANSFER_PAYMENT_GATEWAY_MIN_WC_VER', '6.5.4');
define('BANK_TRANSFER_PAYMENT_GATEWAY_FILE', __FILE__);
define('BANK_TRANSFER_PAYMENT_GATEWAY_PLUGIN_DIR', plugin_dir_path(__FILE__));

// Declare HPOS Compatibility
add_action('before_woocommerce_init', function () {
	if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', BANK_TRANSFER_PAYMENT_GATEWAY_FILE, true);
	}
});

// Include utility functions
require_once BANK_TRANSFER_PAYMENT_GATEWAY_PLUGIN_DIR . 'includes/bank-transfer-payment-gateway-utils.php';

// Migrations functions
include_once plugin_dir_path(__FILE__) . 'migration.php';

// Autoload classes
spl_autoload_register(function ($class) {
	if (strpos($class, 'BANK_TRANSFER_PAYMENT_GATEWAY') === 0) {
		$class_file = BANK_TRANSFER_PAYMENT_GATEWAY_PLUGIN_DIR . 'includes/class-' . str_replace('_', '-', strtolower($class)) . '.php';
		if (file_exists($class_file)) {
			require_once $class_file;
		}
	}
});

BANK_TRANSFER_PAYMENT_GATEWAY_Loader::get_instance();

// BT-42: Ensure tables and migrations run on plugin update
add_action('plugins_loaded', function () {
	$current_schema = get_option('bank_transfer_schema_version', '0');
	if (version_compare($current_schema, '1.2.0', '<')) {
		if (function_exists('bank_transfer_create_tables')) {
			bank_transfer_create_tables();
		}
		if (function_exists('bank_transfer_migrate_old_settings')) {
			bank_transfer_migrate_old_settings();
		}
		update_option('bank_transfer_schema_version', '1.2.0');
	}
});

add_action('woocommerce_order_status_cancelled', 'bank_transfer_cancel_unpaid_order_action');

/**
 * Cancels an unpaid order payment link on the remote API.
 *
 * @param int $order_id The ID of the order to cancel.
 */
function bank_transfer_cancel_unpaid_order_action($order_id)
{
	global $wpdb;

	if (empty($order_id) || !is_numeric($order_id) || $order_id <= 0) {
		return;
	}

	$order = wc_get_order($order_id);

	if (!$order || !is_a($order, 'WC_Order')) {
		return;
	}

	if ($order->get_payment_method() !== 'bank_transfer') {
		return;
	}

	$cache_key   = 'bank_transfer_payment_row_' . intval($order_id);
	$cache_group = 'bank_transfer_payment_gateway';

	wp_cache_delete('bank_transfer_payment_link_uuid_' . intval($order_id), $cache_group);
	wp_cache_delete($cache_key, $cache_group);

	$table_name = esc_sql($wpdb->prefix . 'order_payment_link');
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$payment_row = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT * FROM {$table_name} WHERE order_id = %d LIMIT 1",
			intval($order_id)
		),
		ARRAY_A
	);
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	$uuid = sanitize_text_field($payment_row['uuid'] ?? '');

	if (empty($uuid)) {
		return;
	}

	$accounts_data = get_option('woocommerce_bank_transfer_payment_gateway_accounts', []);
	if (is_string($accounts_data)) {
		$accounts_data = maybe_unserialize($accounts_data);
	}
	if (!is_array($accounts_data)) {
		$accounts_data = [];
	}

	$settings = get_option('woocommerce_bank_transfer_settings', []);
	$is_sandbox = isset($settings['sandbox']) && $settings['sandbox'] === 'yes';

	// BT-09: Look up the account whose public key created the payment
	$order_public_key = $order->get_meta('_bank_transfer_public_key');
	$matched_account  = null;

	if (!empty($order_public_key)) {
		foreach ($accounts_data as $acc) {
			if (!is_array($acc)) {
				continue;
			}
			$acc_pub = $is_sandbox ? ($acc['sandbox_public_key'] ?? '') : ($acc['live_public_key'] ?? '');
			if ($acc_pub === $order_public_key) {
				$matched_account = $acc;
				break;
			}
		}
	}

	if (!is_array($matched_account)) {
		Bank_Transfer_Payment_Gateway_Logger::warning("Cancel skipped: No matching account found for Order ID: {$order_id}", [
			'source'   => 'bank-transfer-payment-gateway',
			'order_id' => $order_id,
		]);
		return;
	}

	$public_key = $is_sandbox ? ($matched_account['sandbox_public_key'] ?? '') : ($matched_account['live_public_key'] ?? '');
	$secret_key = $is_sandbox ? ($matched_account['sandbox_secret_key'] ?? '') : ($matched_account['live_secret_key'] ?? '');

	if (empty($secret_key)) {
		Bank_Transfer_Payment_Gateway_Logger::warning("Cancel skipped: No secret key available to sign for Order ID: {$order_id}", [
			'source'   => 'bank-transfer-payment-gateway',
			'order_id' => $order_id,
		]);
		return;
	}

	$api_base = defined('BANK_TRANSFER_API_BASE_URL') ? BANK_TRANSFER_API_BASE_URL : (BANK_TRANSFER_BASE_URL . '/payment-api');
	$url      = $api_base . '/cancel-order-link';
	$cleanUrl = esc_url_raw(preg_replace('#(?<!:)//+#', '/', $url));

	$request_payload = [
		'order_id'   => intval($order_id),
		'order_uuid' => $uuid,
		'status'     => 'canceled',
	];

	$raw_body  = wp_json_encode($request_payload);
	$timestamp = time();
	$signature = !empty($secret_key) ? hash_hmac('sha256', $timestamp . '.' . $raw_body, $secret_key) : '';

	$headers = [
		'Content-Type'  => 'application/json',
		'Authorization' => 'Bearer ' . sanitize_text_field($public_key),
		'X-Signature'   => $signature,
		'X-Timestamp'   => (string) $timestamp,
	];

	$response = wp_remote_post($cleanUrl, [
		'method'    => 'POST',
		'timeout'   => 15,
		'body'      => $raw_body,
		'headers'   => $headers,
		'sslverify' => true,
	]);

	if (is_wp_error($response)) {
		Bank_Transfer_Payment_Gateway_Logger::error("Cancel API call failed for Order ID: {$order_id}", [
			'source'  => 'bank-transfer-payment-gateway',
			'order_id' => $order_id,
		]);
	} else {
		$response_code = wp_remote_retrieve_response_code($response);
		if ($response_code >= 200 && $response_code < 300) {
			Bank_Transfer_Payment_Gateway_Logger::info("Cancel API successful for Order ID: {$order_id}.", [
				'source'   => 'bank-transfer-payment-gateway',
				'order_id' => $order_id,
			]);
		} else {
			Bank_Transfer_Payment_Gateway_Logger::warning("Cancel API returned non-200 code for Order ID: {$order_id}.", [
				'source'        => 'bank-transfer-payment-gateway',
				'order_id'      => $order_id,
				'response_code' => $response_code,
			]);
		}
	}
}
