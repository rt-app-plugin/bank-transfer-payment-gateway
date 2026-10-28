<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

class BANK_TRANSFER_Blocks_Gateway extends AbstractPaymentMethodType {

	protected $name = 'bank_transfer';
	protected $id   = 'bank_transfer';

	public function initialize() {
		$this->settings = get_option('woocommerce_' . $this->name . '_settings', []);
	}

	public function is_active() {
		return (
			isset($this->settings['enabled']) &&
			$this->settings['enabled'] === 'yes'
		);
	}

	public function get_payment_method_script_handles() {
		$js_path = plugin_dir_path(BANK_TRANSFER_PAYMENT_GATEWAY_FILE) . 'assets/js/bank-transfer.js';
		$js_ver  = file_exists($js_path) ? filemtime($js_path) : '0.0.1';

		wp_register_script(
			'bank-transfer-js',
			plugin_dir_url(BANK_TRANSFER_PAYMENT_GATEWAY_FILE) . 'assets/js/bank-transfer.js',
			['jquery'],
			$js_ver,
			true
		);

		wp_localize_script('bank-transfer-js', 'bank_transfer_params', [
			'ajax_url'             => admin_url('admin-ajax.php'),
			'checkout_url'         => wc_get_checkout_url(),
			'bank_transfer_loader' => plugin_dir_url(BANK_TRANSFER_PAYMENT_GATEWAY_FILE) . 'assets/images/loader.gif',
			'bank_transfer_nonce'  => wp_create_nonce('bank_transfer_payment'),
			'payment_method'       => $this->name,
		]);

		$blocks_js_path = plugin_dir_path(BANK_TRANSFER_PAYMENT_GATEWAY_FILE) . 'assets/js/bank-transfer-blocks.js';
		$blocks_js_ver  = file_exists($blocks_js_path) ? filemtime($blocks_js_path) : '0.0.1';

		wp_register_script(
			'bank-transfer-blocks-js',
			plugin_dir_url(BANK_TRANSFER_PAYMENT_GATEWAY_FILE) . 'assets/js/bank-transfer-blocks.js',
			['wc-blocks-registry', 'wc-settings', 'wp-element', 'bank-transfer-js'],
			$blocks_js_ver,
			true
		);

		return ['bank-transfer-js', 'bank-transfer-blocks-js'];
	}

	public function get_payment_method_data() {
		$title       = $this->settings['title'] ?? 'Bank Transfer';
		$description = $this->settings['description'] ?? '';

		if (WC()->cart) {
			$amount = (float) WC()->cart->get_total('edit');
			if ($amount < 0.01) {
				$totals = WC()->cart->get_totals();
				$amount = (float) ($totals['total'] ?? 0);
			}
			$gateways = WC()->payment_gateways ? WC()->payment_gateways->payment_gateways() : [];
			$gateway  = $gateways['bank_transfer'] ?? null;
			if ($gateway && method_exists($gateway, 'get_checkout_info_for_amount')) {
				$info = $gateway->get_checkout_info_for_amount($amount);
				if (!empty($info['title']))    $title       = $info['title'];
				if (!empty($info['subtitle'])) $description = $info['subtitle'];
			}
		}

		return [
			'id'          => $this->name,
			'title'       => sanitize_text_field($title),
			'description' => wp_kses_post($description),
			'supports'    => ['products'],
			'isActive'    => $this->is_active(),
			'instructions'=> $this->settings['instructions'] ?? '',
		];
	}
}
