<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

// config.php
if (!defined('BANK_TRANSFER_PROTOCOL')) {
    define('BANK_TRANSFER_PROTOCOL', 'https://');
}

if (!defined('BANK_TRANSFER_HOST')) {
    define('BANK_TRANSFER_HOST', apply_filters('bank_transfer_api_host', 'pay.rt.app'));
}

if (!defined('BANK_TRANSFER_BASE_URL')) {
    define('BANK_TRANSFER_BASE_URL', BANK_TRANSFER_PROTOCOL . BANK_TRANSFER_HOST);
}

if (!defined('BANK_TRANSFER_API_BASE_URL')) {
	define('BANK_TRANSFER_API_BASE_URL', BANK_TRANSFER_BASE_URL . '/payment-api');
}

if (!defined('BANK_TRANSFER_PLUGIN_VERSION')) {
    define('BANK_TRANSFER_PLUGIN_VERSION', '1.0.2');
}

/*
 * BK-13: every identifier this plugin registers with WordPress / WooCommerce
 * carries the rt_ prefix so it cannot collide with another "bank transfer"
 * plugin. Orders placed before 1.0.2 still carry the legacy gateway id, so
 * both are recognised when reading an order (bank_transfer_is_gateway_order()).
 */
if (!defined('BANK_TRANSFER_GATEWAY_ID')) {
	define('BANK_TRANSFER_GATEWAY_ID', 'rt_bank_transfer');
}
if (!defined('BANK_TRANSFER_LEGACY_GATEWAY_ID')) {
	define('BANK_TRANSFER_LEGACY_GATEWAY_ID', 'bank_transfer');
}

/* REST namespace of the return / webhook endpoint. The legacy namespaces stay
 * registered as aliases for payment links issued before 1.0.2. */
if (!defined('BANK_TRANSFER_REST_NAMESPACE')) {
	define('BANK_TRANSFER_REST_NAMESPACE', 'rt-bank-transfer/v1');
}

/* BK-12: this plugin's own table (never shared with the NFT checkout plugin). */
if (!defined('BANK_TRANSFER_TABLE')) {
	define('BANK_TRANSFER_TABLE', 'rt_bank_transfer_payment_link');
}

/* Cron hook and interval names. */
if (!defined('BANK_TRANSFER_CRON_HOOK')) {
	define('BANK_TRANSFER_CRON_HOOK', 'rt_bank_transfer_cron_event');
}
if (!defined('BANK_TRANSFER_CRON_INTERVAL')) {
	define('BANK_TRANSFER_CRON_INTERVAL', 'rt_bank_transfer_every_two_hours');
}
if (!defined('BANK_TRANSFER_ASYNC_SYNC_HOOK')) {
	define('BANK_TRANSFER_ASYNC_SYNC_HOOK', 'rt_bank_transfer_async_sync_order');
}

/* BK-01: WooCommerce order status a paid order moves to when the bank returns
 * the ACH debit (without the wc- prefix, as WooCommerce uses it). */
if (!defined('BANK_TRANSFER_RETURNED_STATUS')) {
	define('BANK_TRANSFER_RETURNED_STATUS', 'rt-pay-returned');
}

/* Settings / option names. */
if (!defined('BANK_TRANSFER_SETTINGS_OPTION')) {
	define('BANK_TRANSFER_SETTINGS_OPTION', 'woocommerce_' . BANK_TRANSFER_GATEWAY_ID . '_settings');
}
if (!defined('BANK_TRANSFER_LEGACY_SETTINGS_OPTION')) {
	define('BANK_TRANSFER_LEGACY_SETTINGS_OPTION', 'woocommerce_' . BANK_TRANSFER_LEGACY_GATEWAY_ID . '_settings');
}
if (!defined('BANK_TRANSFER_ACCOUNTS_OPTION')) {
	define('BANK_TRANSFER_ACCOUNTS_OPTION', 'woocommerce_bank_transfer_payment_gateway_accounts');
}
