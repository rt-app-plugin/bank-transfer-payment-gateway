<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly
/**
 * Check the environment for compatibility issues.
 *
 * @return string|false
 */
function bank_transfer_check_system_requirements()
{
	if (version_compare(phpversion(), BANK_TRANSFER_PAYMENT_GATEWAY_MIN_PHP_VER, '<')) {
		return sprintf(
			// translators: %1$s is the minimum required PHP version, %2$s is the current PHP version
			__('The Bank Transfer Payment Gateway plugin requires PHP version %1$s or greater. You are running %2$s.', 'bank-transfer-payment-gateway'),
			BANK_TRANSFER_PAYMENT_GATEWAY_MIN_PHP_VER,
			phpversion()
		);
	}

	// Get WooCommerce versions
	$wc_db_version = get_option('woocommerce_db_version');
	$wc_plugin_version = defined('WC_VERSION') ? WC_VERSION : null;

	// Check if the WooCommerce database version is outdated
	if (!$wc_db_version || version_compare($wc_db_version, BANK_TRANSFER_PAYMENT_GATEWAY_MIN_WC_VER, '<')) {
		return sprintf(
			// translators: %1$s is the minimum required WooCommerce database version, %2$s is the current WooCommerce database version (or "undefined" if not available)
			__('The Bank Transfer Payment Gateway plugin requires WooCommerce database version %1$s or greater. You are running %2$s.', 'bank-transfer-payment-gateway'),
			BANK_TRANSFER_PAYMENT_GATEWAY_MIN_WC_VER,
			$wc_db_version ? $wc_db_version : __('undefined', 'bank-transfer-payment-gateway')
		);
	}

	// Check if WooCommerce plugin version is outdated
	if (!$wc_plugin_version || version_compare($wc_plugin_version, BANK_TRANSFER_PAYMENT_GATEWAY_MIN_WC_VER, '<')) {
		return sprintf(
			// translators: %1$s is the minimum required WooCommerce plugin version, %2$s is the current WooCommerce plugin version (or "undefined" if not available)
			__('The Bank Transfer Payment Gateway plugin requires WooCommerce plugin version %1$s or greater. You are running %2$s.', 'bank-transfer-payment-gateway'),
			BANK_TRANSFER_PAYMENT_GATEWAY_MIN_WC_VER,
			$wc_plugin_version ? $wc_plugin_version : __('undefined', 'bank-transfer-payment-gateway')
		);
	}

	return false;
}

if (!function_exists('bank_transfer_add_unique_order_note')) {

    function bank_transfer_add_unique_order_note($order, $key, $message)
    {
        if (!$order instanceof WC_Order) {
            return false;
        }

        if (empty($message)) {
            return false;
        }

        // Plugin identifier (IMPORTANT for tracking in WP admin)
        $plugin_prefix = '<strong>Bank Transfer Gateway</strong>';

        // Unique meta key per note type (scoped to plugin)
        $meta_key = '_bank_transfer_order_note_' . sanitize_key($key);

        // Check if already exists
        $existing = $order->get_meta($meta_key, true);

        if (!empty($existing)) {
            return false;
        }

        // Prepend plugin identifier to every note
        $final_message = $plugin_prefix . "\n\n" . wp_kses_post($message);

        // Add WooCommerce order note
        $order->add_order_note($final_message);

        // Store timestamp using WooCommerce timezone-aware time
        $order->update_meta_data($meta_key, current_time('timestamp'));

        $order->save();

        return true;
    }
}
