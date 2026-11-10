<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

require_once plugin_dir_path(__FILE__) . 'config.php';
require_once plugin_dir_path(__FILE__) . 'bank-transfer-payment-gateway-utils.php';

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

/**
 * WooCommerce Blocks integration.
 *
 * BK-08: the block checkout goes through WooCommerce's Store API like every
 * other gateway. Place Order is not intercepted; the Store API validates the
 * checkout (stock, terms, captcha / anti-fraud hooks, this gateway's own
 * validators and consent checkbox) and calls process_payment(), whose
 * redirect sends the shopper to RT's payment page.
 */
class BANK_TRANSFER_Blocks_Gateway extends AbstractPaymentMethodType {

	protected $name = BANK_TRANSFER_GATEWAY_ID;
	protected $id   = BANK_TRANSFER_GATEWAY_ID;

	public function initialize() {
		$this->settings = bank_transfer_get_settings();
	}

	public function is_active() {
		if (
			!isset($this->settings['enabled']) ||
			$this->settings['enabled'] !== 'yes'
		) {
			return false;
		}

		$gateways = WC()->payment_gateways ? WC()->payment_gateways->payment_gateways() : [];
		$gateway  = $gateways[$this->name] ?? null;
		if ($gateway && method_exists($gateway, 'is_available')) {
			return $gateway->is_available();
		}

		return true;
	}

	public function get_payment_method_script_handles() {
		$blocks_js_path = plugin_dir_path(BANK_TRANSFER_PAYMENT_GATEWAY_FILE) . 'assets/js/bank-transfer-blocks.js';
		$blocks_js_ver  = file_exists($blocks_js_path) ? filemtime($blocks_js_path) : bank_transfer_get_plugin_version();

		wp_register_script(
			'rt-bank-transfer-blocks-js',
			plugin_dir_url(BANK_TRANSFER_PAYMENT_GATEWAY_FILE) . 'assets/js/bank-transfer-blocks.js',
			['wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities', 'wp-i18n'],
			$blocks_js_ver,
			true
		);

		return ['rt-bank-transfer-blocks-js'];
	}

	public function get_payment_method_data() {
		$title       = $this->settings['title'] ?? __('Bank transfer (ACH)', 'bank-transfer-payment-gateway');
		$description = $this->settings['description'] ?? '';

		if (WC()->cart) {
			$amount = (float) WC()->cart->get_total('edit');
			if ($amount < 0.01) {
				$totals = WC()->cart->get_totals();
				$amount = (float) ($totals['total'] ?? 0);
			}
			$gateways = WC()->payment_gateways ? WC()->payment_gateways->payment_gateways() : [];
			$gateway  = $gateways[$this->name] ?? null;
			if ($gateway && method_exists($gateway, 'get_checkout_info_for_amount')) {
				$info = $gateway->get_checkout_info_for_amount($amount);
				if (!empty($info['title']))    $title       = $info['title'];
				if (!empty($info['subtitle'])) $description = $info['subtitle'];
			}
		}

		$is_active = $this->is_active();
		return [
			'id'                    => $this->name,
			'title'                 => sanitize_text_field($title),
			'description'           => wp_kses_post($description),
			'supports'              => ['products'],
			'plugin_version'        => bank_transfer_get_plugin_version(),
			'isActive'              => $is_active,
			'can_pay'               => $is_active,
			'instructions'          => $this->settings['instructions'] ?? '',
			'show_consent_checkbox' => ($this->settings['show_consent_checkbox'] ?? 'no') === 'yes',
			'consent_label'         => __('I consent to the collection of my data to process this payment', 'bank-transfer-payment-gateway'),
			'consent_nonce'         => wp_create_nonce('bank_transfer_payment'),
			'redirect_notice'       => __('You will be redirected to RT\'s secure payment page to authorise the bank transfer.', 'bank-transfer-payment-gateway'),
		];
	}
}
