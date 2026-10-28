<?php
if (!defined('ABSPATH')) {
	exit();
}

require_once plugin_dir_path(__FILE__) . 'config.php';
require_once plugin_dir_path(__FILE__) . 'class-bank-transfer-payment-logger.php';

class BANK_TRANSFER_PAYMENT_GATEWAY extends WC_Payment_Gateway_CC
{
	const ID = 'bank_transfer';

	protected $sandbox;
	private $base_url;
	private $public_key;
	private $secret_key;
	private $sandbox_secret_key;
	private $sandbox_public_key;

	private $admin_notices;
	private $accounts = [];
	private $current_account_index = 0;
	private $used_accounts = [];

	private static $log_once_flags = [];


	/**
	 * Account selected during the availability filter for dynamic title/subtitle.
	 *
	 * @var array|null
	 */
	private $selected_account_for_display = null;

	/**
	 * Constructor for the gateway.
	 */
	public function __construct() {
		if (!class_exists('WC_Payment_Gateway_CC')) {
			add_action('admin_notices', [$this, 'bank_transfer_woocommerce_not_active_notice']);
			return;
		}

		$this->admin_notices = new BANK_TRANSFER_PAYMENT_GATEWAY_Admin_Notices();
		$this->base_url      = BANK_TRANSFER_BASE_URL;

		$this->id                 = self::ID;
		$this->icon               = '';
		$this->method_title       = __('Bank Transfer Payment Gateway', 'bank-transfer-payment-gateway');
		$this->method_description = __('This plugin allows you to accept payments in USD through a secure payment gateway integration.', 'bank-transfer-payment-gateway');

		$this->bank_transfer_init_form_fields();
		$this->init_settings();
		$this->settings['group_id'] = get_option('bank_transfer_group_id') ? get_option('bank_transfer_group_id') : $this->bank_transfer_get_group_id();
		$this->load_gateway_settings();

		$this->register_hooks();
	}

	/**
	 * Load gateway settings.
	 * Called once in constructor AND can be re-called to refresh in AJAX context.
	 */
	public function load_gateway_settings() {
		$this->title       = sanitize_text_field($this->get_option('title'));
		$this->description = !empty($this->get_option('description'))
			? sanitize_textarea_field($this->get_option('description'))
			: ($this->get_option('show_consent_checkbox') === 'yes' ? 1 : 0);

		$this->enabled    = sanitize_text_field($this->get_option('enabled'));
		$this->sandbox    = 'yes' === sanitize_text_field($this->get_option('sandbox'));
		$this->public_key = sanitize_text_field($this->get_option($this->sandbox ? 'sandbox_public_key' : 'public_key'));
		$this->secret_key = sanitize_text_field($this->get_option($this->sandbox ? 'sandbox_secret_key' : 'secret_key'));
		$this->current_account_index = 0;
	}

	/**
	 * Register hooks for the gateway.
	 */
	private function register_hooks() {
		add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'bank_transfer_process_admin_options']);
		add_action('wp_enqueue_scripts', [$this, 'bank_transfer_enqueue_styles_and_scripts']);
		add_action('admin_enqueue_scripts', [$this, 'bank_transfer_admin_scripts']);

		add_action('woocommerce_admin_order_data_after_order_details', [$this, 'bank_transfer_display_test_order_tag']);
		add_filter('woocommerce_admin_order_preview_line_items', [$this, 'bank_transfer_add_custom_label_to_order_row'], 10, 2);
		add_filter('woocommerce_available_payment_gateways', [$this, 'bank_transfer_hide_custom_payment_gateway_conditionally']);

		add_action('woocommerce_after_checkout_validation', [$this, 'bank_transfer_validate_checkout_fields'], 10, 2);
		add_action(
			'woocommerce_store_api_checkout_update_order_from_request',
			[$this, 'bank_transfer_validate_blocks_checkout'],
			10,
			2
		);
	}

	/**
	 * Strict validation for Phone and Zip Code.
	 * Runs before process_payment to ensure only clean data reaches your API.
	 */
	public function bank_transfer_validate_checkout_fields($data, $errors)
	{
		$selected_gateway = wc_clean(
			wp_unslash($_POST['payment_method'] ?? '')
		);

		if (empty($selected_gateway)) {
			return;
		}

		if ($selected_gateway !== $this->id) {
			return;
		}

		/*
		|--------------------------------------------------------------------------
		| PHONE VALIDATION
		|--------------------------------------------------------------------------
		*/
		$phone   = trim($data['billing_phone'] ?? '');
		$country = strtoupper($data['billing_country'] ?? '');

		if (!empty($phone)) {

			$country_calling_code = WC()->countries->get_country_calling_code($country);

			if (is_array($country_calling_code)) {
				$country_calling_code = reset($country_calling_code);
			}

			$normalized = $this->bank_transfer_normalize_phone(
				$phone,
				$country_calling_code
			);

			if (empty($normalized['is_valid'])) {

				Bank_Transfer_Payment_Gateway_Logger::warning(
					'Classic checkout validation failed: invalid phone number',
					[
						'phone'      => $phone,
						'country'    => $country,
						'normalized' => $normalized,
						'error'      => $normalized['error'] ?? null,
					]
				);

				$errors->add(
					'bank_transfer_phone_error',
					$normalized['error'] ?? __('Invalid phone number.', 'bank-transfer-payment-gateway')
				);

				return;
			}

			Bank_Transfer_Payment_Gateway_Logger::info(
				'Classic checkout phone validation passed',
				[
					'phone'      => $phone,
					'country'    => $country,
					'normalized' => $normalized,
				]
			);
		}

		/*
		|--------------------------------------------------------------------------
		| PO BOX VALIDATION
		|--------------------------------------------------------------------------
		*/
		$billing_address_1 = trim($data['billing_address_1'] ?? '');

		if (!empty($billing_address_1) && $this->is_po_box($billing_address_1)) {

			Bank_Transfer_Payment_Gateway_Logger::warning(
				'Classic checkout validation failed: PO Box detected',
				[
					'country' => $country,
				]
			);

			$errors->add(
				'bank_transfer_po_box_error',
				__('PO Box addresses are not accepted. Please enter a physical street address.', 'bank-transfer-payment-gateway')
			);

			return;
		}

		/*
		|--------------------------------------------------------------------------
		| ZIP / POSTCODE VALIDATION
		|--------------------------------------------------------------------------
		*/
		$postcode = trim($data['billing_postcode'] ?? '');

		if (!empty($postcode)) {

			$clean = strtoupper(preg_replace('/\s+/', '', $postcode));

			$valid = false;

			switch ($country) {

				case 'US':
					$valid = preg_match('/^\d{5}(-\d{4})?$/', $postcode);
					break;

				case 'CA':
					$valid = preg_match('/^[A-Z]\d[A-Z]\d[A-Z]\d$/', $clean);
					break;

				case 'GB':
					$valid = preg_match('/^[A-Z]{1,2}\d[A-Z\d]?\d[A-Z]{2}$/', $clean);
					break;

				default:

					$clean_postcode = preg_replace('/[^A-Z0-9]/', '', strtoupper($postcode));

					// Reject purely numeric long values
					if (preg_match('/^\d{8,}$/', $clean_postcode)) {
						$valid = false;
					} else {

						$valid = preg_match(
							'/^(?=.*[A-Z0-9])[A-Z0-9\- ]{3,12}$/i',
							$postcode
						);
					}

					break;
			}

			if (!$valid) {

				Bank_Transfer_Payment_Gateway_Logger::warning(
					'Classic checkout validation failed: invalid postcode',
					[
						'postcode' => $postcode,
						'country'  => $country
					]
				);

				$errors->add(
					'bank_transfer_postcode_error',
					__('Invalid ZIP / postal code.', 'bank-transfer-payment-gateway')
				);

				return;
			}

			Bank_Transfer_Payment_Gateway_Logger::info(
				'Classic checkout postcode validation passed',
				[
					'postcode' => $postcode,
					'country'  => $country
				]
			);
		}
	}


	public function bank_transfer_validate_blocks_checkout($order, $request)
	{
		$payment_method = $request['payment_method'] ?? '';

		if (empty($payment_method)) {
			return;
		}

		if ($payment_method !== $this->id) {
			return;
		}

		/*
		|--------------------------------------------------------------------------
		| PHONE VALIDATION
		|--------------------------------------------------------------------------
		*/
		$phone   = trim($request['billing_address']['phone'] ?? '');
		$country = strtoupper($request['billing_address']['country'] ?? '');

		if (!empty($phone)) {

			$country_calling_code = WC()->countries->get_country_calling_code($country);

			if (is_array($country_calling_code)) {
				$country_calling_code = reset($country_calling_code);
			}

			$normalized = $this->bank_transfer_normalize_phone(
				$phone,
				$country_calling_code
			);

			if (empty($normalized['is_valid'])) {

				Bank_Transfer_Payment_Gateway_Logger::warning(
					'Blocks checkout validation failed: invalid phone number',
					[
						'phone'      => $phone,
						'country'    => $country,
						'normalized' => $normalized,
						'order_id'   => $order->get_id() ?? null,
						'error'      => $normalized['error'] ?? null,
					]
				);

				throw new Exception(
					$normalized['error'] ?? 'Invalid phone number.'
				);
			}

			// Sync latest validated value to order
			$order->set_billing_phone($phone);

			Bank_Transfer_Payment_Gateway_Logger::info(
				'Blocks checkout phone validation passed',
				[
					'phone'      => $phone,
					'country'    => $country,
					'normalized' => $normalized,
					'order_id'   => $order->get_id() ?? null,
				]
			);
		}

		/*
		|--------------------------------------------------------------------------
		| PO BOX VALIDATION
		|--------------------------------------------------------------------------
		*/
		$billing_address_1 = trim($request['billing_address']['address_1'] ?? '');

		if (!empty($billing_address_1) && $this->is_po_box($billing_address_1)) {

			Bank_Transfer_Payment_Gateway_Logger::warning(
				'Blocks checkout validation failed: PO Box detected',
				[
					'country'  => $country,
					'order_id' => $order->get_id() ?? null,
				]
			);

			throw new Exception(
				'PO Box addresses are not accepted. Please enter a physical street address.'
			);
		}

		/*
		|--------------------------------------------------------------------------
		| ZIP / POSTCODE VALIDATION
		|--------------------------------------------------------------------------
		*/
		$postcode = trim($request['billing_address']['postcode'] ?? '');

		if (!empty($postcode)) {

			$clean = strtoupper(preg_replace('/\s+/', '', $postcode));

			$valid = false;

			switch ($country) {

				case 'US':
					$valid = preg_match('/^\d{5}(-\d{4})?$/', $postcode);
					break;

				case 'CA':
					$valid = preg_match('/^[A-Z]\d[A-Z]\d[A-Z]\d$/', $clean);
					break;

				case 'GB':
					$valid = preg_match('/^[A-Z]{1,2}\d[A-Z\d]?\d[A-Z]{2}$/', $clean);
					break;

				default:

					$clean_postcode = preg_replace('/[^A-Z0-9]/', '', strtoupper($postcode));

					// Reject purely numeric long values
					if (preg_match('/^\d{8,}$/', $clean_postcode)) {
						$valid = false;
					} else {

						$valid = preg_match(
							'/^(?=.*[A-Z0-9])[A-Z0-9\- ]{3,12}$/i',
							$postcode
						);
					}

					break;
			}

			if (!$valid) {

				Bank_Transfer_Payment_Gateway_Logger::warning(
					'Blocks checkout validation failed: invalid postcode',
					[
						'postcode' => $postcode,
						'country'  => $country,
						'order_id' => $order->get_id() ?? null
					]
				);

				throw new Exception(
					'Invalid ZIP / postal code.'
				);
			}

			$order->set_billing_postcode($postcode);

			Bank_Transfer_Payment_Gateway_Logger::info(
				'Blocks checkout postcode validation passed',
				[
					'postcode' => $postcode,
					'country'  => $country,
					'order_id' => $order->get_id() ?? null
				]
			);
		}

		return $order;
	}

	private function get_api_url($endpoint) {
		return $this->base_url . $endpoint;
	}

	public function bank_transfer_process_admin_options() {
		// BT-12: Check capabilities and nonce BEFORE processing options
		if (!current_user_can('manage_woocommerce')) {
			wp_die(esc_html__('You do not have permission to access this page.', 'bank-transfer-payment-gateway'));
		}

		if (!isset($_POST['bank_transfer_accounts_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['bank_transfer_accounts_nonce'])), 'bank_transfer_accounts_nonce_action')) {
			Bank_Transfer_Payment_Gateway_Logger::info('CSRF check failed during admin options update.');
			wp_die(esc_html__('Security check failed!', 'bank-transfer-payment-gateway'));
		}

		$enabled     = isset($_POST['woocommerce_' . $this->id . '_enabled']) ? 'yes' : 'no';
		$accounts    = isset($_POST['accounts']) && is_array($_POST['accounts']) ? wp_unslash($_POST['accounts']) : [];
		$keys_entered = false;

		if (!empty($accounts)) {
			foreach ($accounts as $account) {
				if (
					!empty($account['live_public_key']) ||
					!empty($account['live_secret_key']) ||
					!empty($account['sandbox_public_key']) ||
					!empty($account['sandbox_secret_key'])
				) {
					$keys_entered = true;
					break;
				}
			}
		}

		parent::process_admin_options();

		$errors             = [];
		$valid_accounts     = [];
		$unique_live_keys   = [];
		$unique_sandbox_keys = [];
		$normalized_index   = 0;
		$raw_accounts       = [];

		if (isset($_POST['accounts']) && is_array($_POST['accounts'])) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
			$unslashed_accounts = wp_unslash($_POST['accounts']);
			$raw_accounts = array_map(
				static function ($account) {
					return is_array($account)
						? array_map('sanitize_text_field', $account)
						: sanitize_text_field($account);
				},
				$unslashed_accounts
			);
		}

		if (!is_array($raw_accounts) || empty($raw_accounts)) {
			$errors[] = __('You cannot delete all accounts. At least one valid payment account must be configured.', 'bank-transfer-payment-gateway');
			Bank_Transfer_Payment_Gateway_Logger::info('No accounts submitted in admin options.');
		}

		foreach ((array) $raw_accounts as $account) {
			if (!is_array($account)) continue;

			$account = array_map('sanitize_text_field', $account);

			$account_title      = $account['title'] ?? '';
			$priority           = intval($account['priority'] ?? 1);
			$live_public_key    = $account['live_public_key'] ?? '';
			$live_secret_key    = $account['live_secret_key'] ?? '';
			$sandbox_public_key = $account['sandbox_public_key'] ?? '';
			$sandbox_secret_key = $account['sandbox_secret_key'] ?? '';
			$has_sandbox         = isset($account['has_sandbox']) && $account['has_sandbox'] === 'on';
			$live_status        = $account['live_status'] ?? 'Active';
			$sandbox_status     = $has_sandbox ? ($account['sandbox_status'] ?? 'Active') : '';
			$unique_id          = $account['unique_id'] ?? '';
			$checkout_title      = $account['checkout_title'] ?? '';
	        $checkout_subtitle   = $account['checkout_subtitle'] ?? '';

			if (empty($account_title) && empty($live_public_key) && empty($live_secret_key) && empty($sandbox_public_key) && empty($sandbox_secret_key)) {
				continue;
			}

			if (empty($account_title) || empty($live_public_key) || empty($live_secret_key)) {
				$errors[] = sprintf(__('Account "%s": Title, Live Public Key, and Live Secret Key are required.', 'bank-transfer-payment-gateway'), $account_title);
				Bank_Transfer_Payment_Gateway_Logger::info("Validation failed: missing required fields for account '{$account_title}'");
				continue;
			}

			$live_combined = $live_public_key . '|' . $live_secret_key;
			if (in_array($live_combined, $unique_live_keys, true)) {
				$errors[] = sprintf(__('Account "%s": Live Public Key and Live Secret Key must be unique.', 'bank-transfer-payment-gateway'), $account_title);
				Bank_Transfer_Payment_Gateway_Logger::info("Validation failed: duplicate live keys for account '{$account_title}'");
				continue;
			}

			if ($live_public_key === $live_secret_key) {
				$errors[] = sprintf(__('Account "%s": Live Public Key and Live Secret Key must be different.', 'bank-transfer-payment-gateway'), $account_title);
				Bank_Transfer_Payment_Gateway_Logger::info("Validation warning: live keys are identical for account '{$account_title}'");
			}

			$unique_live_keys[] = $live_combined;

			if ($has_sandbox && !empty($sandbox_public_key) && !empty($sandbox_secret_key)) {
				$sandbox_combined = $sandbox_public_key . '|' . $sandbox_secret_key;
				if (in_array($sandbox_combined, $unique_sandbox_keys, true)) {
					$errors[] = sprintf(__('Account "%s": Sandbox Public Key and Sandbox Secret Key must be unique.', 'bank-transfer-payment-gateway'), $account_title);
					Bank_Transfer_Payment_Gateway_Logger::info("Validation failed: duplicate sandbox keys for account '{$account_title}'");
					continue;
				}
				if ($sandbox_public_key === $sandbox_secret_key) {
					$errors[] = sprintf(__('Account "%s": Sandbox Public Key and Sandbox Secret Key must be different.', 'bank-transfer-payment-gateway'), $account_title);
					Bank_Transfer_Payment_Gateway_Logger::info("Validation warning: sandbox keys are identical for account '{$account_title}'");
				}
				$unique_sandbox_keys[] = $sandbox_combined;
			}

			$valid_accounts[$normalized_index++] = [
				'title'              => $account_title,
				'priority'           => $priority,
				'live_public_key'    => $live_public_key,
				'live_secret_key'    => $live_secret_key,
				'sandbox_public_key' => $sandbox_public_key,
				'sandbox_secret_key' => $sandbox_secret_key,
				'has_sandbox'        => $has_sandbox ? 'on' : 'off',
				'sandbox_status'     => $sandbox_status,
				'live_status'        => $live_status,
				'unique_id'          => $unique_id,
				'checkout_title'     => $checkout_title,
	            'checkout_subtitle'  => $checkout_subtitle,
			];

			Bank_Transfer_Payment_Gateway_Logger::info("Validated and added account '{$account_title}' to saved list.");
		}

		if (empty($valid_accounts) && empty($errors)) {
			$errors[] = __('You cannot delete all accounts. At least one valid payment account must be configured.', 'bank-transfer-payment-gateway');
			Bank_Transfer_Payment_Gateway_Logger::info('All submitted accounts failed validation. No accounts will be saved.');
		}

		if (empty($errors)) {
			update_option('woocommerce_bank_transfer_payment_gateway_accounts', $valid_accounts);

			$first_account = !empty($valid_accounts) ? reset($valid_accounts) : null;
			$public_key    = $first_account ? ($this->sandbox ? ($first_account['sandbox_public_key'] ?? '') : ($first_account['live_public_key'] ?? '')) : '';
			$api_url       = esc_url($this->base_url . '/api/plugin/check/plugin');
			$plugin_version = defined('BANK_TRANSFER_PLUGIN_VERSION') ? BANK_TRANSFER_PLUGIN_VERSION : '0.1.0';

			$sanitized_accounts = array_map(function($acc) {
				unset($acc['live_secret_key'], $acc['sandbox_secret_key']);
				return $acc;
			}, $valid_accounts);

			$body = [
				'valid_accounts' => $sanitized_accounts,
				'plugin_status'  => $enabled === 'yes' ? 1 : 0,
				'plugin_version' => $plugin_version,
				'gateway_loaded' => 0,
				'group_id'       => get_option('bank_transfer_group_id'),
				'domain_name'    => parse_url(home_url(), PHP_URL_HOST),
				'wordpress_version'     => get_bloginfo('version'),
				'woocommerce_version'   => get_option('woocommerce_version') ?: '',
				'woocommerce_db_version'=> get_option('woocommerce_db_version'),
			];

			wp_remote_post($api_url, [
				'method'    => 'POST',
				'timeout'   => 30,
				'body'      => wp_json_encode($body),
				'headers'   => [
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . sanitize_text_field($public_key),
				],
				'sslverify' => true,
			]);

			Bank_Transfer_Payment_Gateway_Logger::info('Account settings updated successfully.', ['count' => count($valid_accounts)]);

			if (class_exists('BANK_TRANSFER_PAYMENT_GATEWAY_Loader')) {
				$loader = BANK_TRANSFER_PAYMENT_GATEWAY_Loader::get_instance();
				if (method_exists($loader, 'handle_cron_event')) {
					$loader->handle_cron_event();
					Bank_Transfer_Payment_Gateway_Logger::info('Triggered BANK_TRANSFER_PAYMENT_GATEWAY_Loader::handle_cron_event() after settings save.');
				}
			}
		} else {
			foreach ($errors as $error) {
				$this->admin_notices->bank_transfer_add_notice('settings_error', 'notice notice-error', $error);
				Bank_Transfer_Payment_Gateway_Logger::info("Admin settings error: {$error}");
			}
		}

		add_action('admin_notices', [$this->admin_notices, 'display_notices']);
	}

	public function bank_transfer_init_form_fields() {
		$this->form_fields = $this->bank_transfer_get_form_fields();
	}

	function bank_transfer_get_group_id() {
		$group_id = get_option('bank_transfer_group_id');
		if (empty($group_id)) {
			$group_id = 'grp_' . wp_generate_uuid4();
			update_option('bank_transfer_group_id', $group_id);
		}
		return $group_id;
	}

	function bank_transfer_get_unique_id() {
		return 'acc_' . wp_generate_uuid4();
	}

	function update_accounts_uniqueID($accounts) {
		if (empty($accounts) || !is_array($accounts)) return $accounts;
		$updated = false;
		foreach ($accounts as $index => &$account) {
			if (!is_array($account)) continue;
			if (empty($account['unique_id'])) {
				$account['unique_id'] = $this->bank_transfer_get_unique_id();
				$updated = true;
			}
		}
		unset($account);
		if ($updated) {
			update_option('woocommerce_bank_transfer_payment_gateway_accounts', $accounts);
		}
		return $accounts;
	}

	public function bank_transfer_get_form_fields() {
		$dev_instructions_link = sprintf(
			'<strong><a class="bank_transfer-instructions-url" href="%s" target="_blank">%s</a></strong><br>',
			esc_url($this->base_url . '/developers'),
			__('click here to access your developer account', 'bank-transfer-payment-gateway')
		);

		return apply_filters('bank_transfer_woocommerce_gateway_settings_fields_' . $this->id, [

			'enabled' => [
				'title'   => __('Enable/Disable', 'bank-transfer-payment-gateway'),
				'label'   => __('Enable Bank Transfer Payment Gateway', 'bank-transfer-payment-gateway'),
				'type'    => 'checkbox',
				'default' => 'no',
			],

			'title' => [
				'title'       => __('Title', 'bank-transfer-payment-gateway'),
				'type'        => 'text',
				'description' => __('This controls the title which the user sees during checkout.', 'bank-transfer-payment-gateway'),
				'default'     => __('Buy with USDC Using Your Credit/Debit Card, Apple Pay or Google Pay — Secure, Modern Checkout 🔐', 'bank-transfer-payment-gateway'),
				'desc_tip'    => true,
			],

			'description' => [
				'title'       => __('Description', 'bank-transfer-payment-gateway'),
				'type'        => 'textarea',
				'description' => __('Provide a brief description of the payment option.', 'bank-transfer-payment-gateway'),
				'default'     => __(
					'<p style="margin:0 0 6px; font-size:13px;">Use a Credit Card, Debit Card or Google Pay, Apple Pay to complete your purchase via USDC.</p>
					<p style="margin:0 0 6px; font-size:13px;">The transaction will appear on your bank or card statement as *Bank Transfer</p>',
					'bank-transfer-payment-gateway'
				),
				'desc_tip'    => true,
			],

			'instructions' => [
				'title'       => __('Instructions', 'bank-transfer-payment-gateway'),
				'type'        => 'title',
				'description' => sprintf(__('To configure this gateway, %1$sGet your API keys from your merchant account: Developer Settings > API Keys.%2$s', 'bank-transfer-payment-gateway'), $dev_instructions_link, ''),
				'desc_tip'    => true,
			],

			'sandbox' => [
				'title'       => __('Sandbox', 'bank-transfer-payment-gateway'),
				'label'       => __('Enable Sandbox Mode', 'bank-transfer-payment-gateway'),
				'type'        => 'checkbox',
				'description' => __('Use sandbox API keys (real payments will not be taken).', 'bank-transfer-payment-gateway'),
				'default'     => 'no',
			],

			'group_id' => [
				'type' => 'hidden',
			],

			'accounts' => [
				'title'       => __('Payment Accounts', 'bank-transfer-payment-gateway'),
				'type'        => 'accounts_repeater',
				'description' => __('Add multiple payment accounts dynamically.', 'bank-transfer-payment-gateway'),
			],

			'order_status' => [
				'title'       => __('Order Status', 'bank-transfer-payment-gateway'),
				'type'        => 'select',
				'description' => __('Order status after successful payment.', 'bank-transfer-payment-gateway'),
				'default'     => '',
				'id'          => 'order_status_select',
				'desc_tip'    => true,
				'options'     => [
					'processing' => __('Processing', 'bank-transfer-payment-gateway'),
					'completed'  => __('Completed', 'bank-transfer-payment-gateway'),
				],
			],

			'show_consent_checkbox' => [
				'title'       => __('Show Consent Checkbox', 'bank-transfer-payment-gateway'),
				'label'       => __('Enable consent checkbox on checkout page', 'bank-transfer-payment-gateway'),
				'type'        => 'checkbox',
				'description' => __('Show a checkbox for user consent during checkout.', 'bank-transfer-payment-gateway'),
				'default'     => 'no',
			],

			'debug' => [
				'title'       => __('Debug Log', 'bank-transfer-payment-gateway'),
				'label'       => __('Enable debug logging', 'bank-transfer-payment-gateway'),
				'type'        => 'checkbox',
				'description' => __('Log plugin events, such as API requests, inside WooCommerce > Status > Logs.', 'bank-transfer-payment-gateway'),
				'default'     => 'no',
				'desc_tip'    => true,
			],

		], $this);
	}

	public function generate_accounts_repeater_html($key, $data) {
		$option_value    = get_option('woocommerce_bank_transfer_payment_gateway_accounts', []);
		$option_value    = maybe_unserialize($option_value);
		$active_account  = get_option('bank_transfer_active_account', 0);
		$global_settings = get_option('woocommerce_bank_transfer_settings', []);
		$global_settings = maybe_unserialize($global_settings);
		$sandbox_enabled = !empty($global_settings['sandbox']) && $global_settings['sandbox'] === 'yes';

		$updated = false;
		if (!empty($option_value)) {
			foreach ($option_value as $index => &$account) {
				if (empty($account['unique_id'])) {
					$account['unique_id'] = $this->bank_transfer_get_unique_id();
					$updated = true;
				}
				// Ensure all fields are present for new/empty accounts
				if (!isset($account['checkout_title'])) {
					$account['checkout_title'] = '';
				}
				if (!isset($account['checkout_subtitle'])) {
					$account['checkout_subtitle'] = '';
				}
			}
		}
		unset($account);

		if ($updated) {
			update_option('woocommerce_bank_transfer_payment_gateway_accounts', $option_value);
		}

		ob_start();
		?>
		<tr valign="top">
			<th scope="row" class="titledesc">
				<label><?php echo esc_html($data['title']); ?></label>
			</th>
			<td class="forminp">
				<div id="global-error" class="error-message" style="color: red; margin-bottom: 10px;"></div>
				<div class="bank_transfer-accounts-container bank-transfer-accounts-container">
					<?php if (!empty($option_value)): ?>
						<div class="bank_transfer-sync-account bank-transfer-sync-account">
							<span id="bank_transfer-sync-status"></span>
							<button class="button" id="bank_transfer-sync-accounts"><span><i class="fa fa-refresh" aria-hidden="true"></i></span> <?php esc_html_e('Sync Accounts', 'bank-transfer-payment-gateway'); ?></button>
						</div>
					<?php endif; ?>

					<?php if (empty($option_value)): ?>
						<div class="empty-account"><?php esc_html_e('No accounts available. Please add one to continue.', 'bank-transfer-payment-gateway'); ?></div>
					<?php else: ?>
						<?php foreach (array_values($option_value) as $index => $account): ?>
							<?php
							$live_status    = (!empty($account['live_status'])) ? $account['live_status'] : '';
							$sandbox_status = (!empty($account['sandbox_status'])) ? $account['sandbox_status'] : 'unknown';
							$unique_id      = (!empty($account['unique_id'])) ? $account['unique_id'] : '';
							?>
							<div class="bank_transfer-account bank-transfer-account" data-index="<?php echo esc_attr($index); ?>">
								<input type="hidden" name="accounts[<?php echo esc_attr($index); ?>][live_status]" value="<?php echo esc_attr($account['live_status'] ?? ''); ?>">
								<input type="hidden" name="accounts[<?php echo esc_attr($index); ?>][sandbox_status]" value="<?php echo esc_attr($account['sandbox_status'] ?? ''); ?>">
								<div class="title-blog">
									<h4>
										<span class="account-name-display bank_transfer-name-display bank-transfer-name-display">
											<?php echo !empty($account['title']) ? esc_html($account['title']) : esc_html__('Untitled Account', 'bank-transfer-payment-gateway'); ?>
										</span>
										&nbsp;<i class="fa fa-caret-down <?php echo esc_attr($this->id); ?>-toggle-btn bank_transfer-toggle-btn bank-transfer-toggle-btn account-toggle-btn" aria-hidden="true"></i>
									</h4>
									<div class="action-button">
										<div class="account-status-block">
											<span class="account-status-label <?php echo esc_attr($sandbox_enabled ? 'sandbox-status' : 'live-status'); ?> <?php echo esc_attr(strtolower($sandbox_enabled ? ($sandbox_status ?? '') : ($live_status ?? ''))); ?>">
												<?php
												if ($sandbox_enabled) {
													echo esc_html__('Sandbox Account Status: ', 'bank-transfer-payment-gateway') . esc_html(ucfirst($sandbox_status));
												} else {
													echo esc_html__('Live Account Status: ', 'bank-transfer-payment-gateway') . esc_html(ucfirst($live_status));
												}
												?>
											</span>
										</div>
										<button type="button" class="delete-account-btn">
											<i class="fa fa-trash" aria-hidden="true"></i>
										</button>
									</div>
								</div>

								<div class="<?php echo esc_attr($this->id); ?>-info bank_transfer-info bank-transfer-info account-info">
									<div class="add-blog title-priority">
										<div class="account-input account-name">
											<label><?php esc_html_e('Account Name', 'bank-transfer-payment-gateway'); ?></label>
											<input type="text" class="account-title <?php echo esc_attr($this->id); ?>-title" name="accounts[<?php echo esc_attr($index); ?>][title]" placeholder="<?php esc_attr_e('Account Title', 'bank-transfer-payment-gateway'); ?>" value="<?php echo esc_attr($account['title'] ?? ''); ?>">
										</div>
										<div>
											<input type="hidden" class="<?php echo esc_attr($this->id); ?>-title unique-id" name="accounts[<?php echo esc_attr($index); ?>][unique_id]" value="<?php echo esc_attr($unique_id); ?>" readonly>
										</div>
										<div class="account-input priority-name">
											<label><?php esc_html_e('Priority', 'bank-transfer-payment-gateway'); ?></label>
											<input type="number" class="account-priority" name="accounts[<?php echo esc_attr($index); ?>][priority]" placeholder="<?php esc_attr_e('Priority', 'bank-transfer-payment-gateway'); ?>" value="<?php echo esc_attr($account['priority'] ?? '1'); ?>" min="1">
										</div>

									</div>

									<div class="add-blog">
										<div class="account-input">
											<label><?php esc_html_e('Checkout Title', 'bank-transfer-payment-gateway'); ?></label>
											<input type="text"
												name="accounts[<?php echo esc_attr($index); ?>][checkout_title]"
												placeholder="<?php esc_attr_e('Title shown to customers at checkout', 'bank-transfer-payment-gateway'); ?>"
												value="<?php echo esc_attr($account['checkout_title'] ?? ''); ?>">
										</div>
									</div>

									<div class="add-blog">
										<div class="account-input">
											<label><?php esc_html_e('Checkout Subtitle', 'bank-transfer-payment-gateway'); ?></label>
											<textarea
												class="checkout-subtitle"
												name="accounts[<?php echo esc_attr($index); ?>][checkout_subtitle]"
												placeholder="<?php esc_attr_e('Subtitle/description shown below the title at checkout', 'bank-transfer-payment-gateway'); ?>"
												rows="2"><?php echo esc_textarea($account['checkout_subtitle'] ?? ''); ?></textarea>
										</div>
									</div>

									<div class="add-blog <?php echo esc_attr($this->id); ?>-production-keys">
										<div class="account-input">
											<label><?php esc_html_e('Live Keys', 'bank-transfer-payment-gateway'); ?></label>
											<input type="text" class="live-public-key" name="accounts[<?php echo esc_attr($index); ?>][live_public_key]" placeholder="<?php esc_attr_e('Public Key', 'bank-transfer-payment-gateway'); ?>" value="<?php echo esc_attr($account['live_public_key'] ?? ''); ?>">
										</div>
										<div class="account-input">
											<input type="text" class="live-secret-key" name="accounts[<?php echo esc_attr($index); ?>][live_secret_key]" placeholder="<?php esc_attr_e('Secret Key', 'bank-transfer-payment-gateway'); ?>" value="<?php echo esc_attr($account['live_secret_key'] ?? ''); ?>">
										</div>
									</div>

									<div class="account-checkbox">
										<?php
										$checkbox_id    = $this->id . '-sandbox-checkbox-' . $index;
										$checkbox_class = $this->id . '-sandbox-checkbox';
										?>
										<input type="checkbox" class="<?php echo esc_attr($checkbox_class); ?>" id="<?php echo esc_attr($checkbox_id); ?>" name="accounts[<?php echo esc_attr($index); ?>][has_sandbox]" <?php checked($account['has_sandbox'] == 'on'); ?>>
										<label for="<?php echo esc_attr($checkbox_id); ?>"><?php esc_html_e('Do you have the sandbox keys?', 'bank-transfer-payment-gateway'); ?></label>
									</div>

									<?php
									$sandbox_container_id    = $this->id . '-sandbox-keys-' . $index;
									$sandbox_container_class = $this->id . '-sandbox-keys';
									$sandbox_display_style   = ($account['has_sandbox'] ?? '') == 'off' ? 'display: none;' : '';
									?>
									<div id="<?php echo esc_attr($sandbox_container_id); ?>" class="<?php echo esc_attr($sandbox_container_class); ?>" style="<?php echo esc_attr($sandbox_display_style); ?>">
										<div class="add-blog">
											<div class="account-input">
												<label><?php esc_html_e('Sandbox Keys', 'bank-transfer-payment-gateway'); ?></label>
												<input type="text" class="sandbox-public-key" name="accounts[<?php echo esc_attr($index); ?>][sandbox_public_key]" placeholder="<?php esc_attr_e('Public Key', 'bank-transfer-payment-gateway'); ?>" value="<?php echo esc_attr($account['sandbox_public_key'] ?? ''); ?>">
											</div>
											<div class="account-input">
												<input type="text" class="sandbox-secret-key" name="accounts[<?php echo esc_attr($index); ?>][sandbox_secret_key]" placeholder="<?php esc_attr_e('Secret Key', 'bank-transfer-payment-gateway'); ?>" value="<?php echo esc_attr($account['sandbox_secret_key'] ?? ''); ?>">
											</div>
										</div>
									</div>
								</div>
							</div>
						<?php endforeach; ?>
					<?php endif; ?>
					<?php wp_nonce_field('bank_transfer_accounts_nonce_action', 'bank_transfer_accounts_nonce'); ?>
					<div class="add-account-btn">
						<button type="button" class="button bank_transfer-add-account bank-transfer-add-account">
							<span>+</span> <?php esc_html_e('Add Account', 'bank-transfer-payment-gateway'); ?>
						</button>
					</div>
				</div>
			</td>
		</tr>
		<?php
		return ob_get_clean();
	}

	public function process_payment($order_id, $used_accounts = [])
	{
		global $wpdb;

		$lock_name = '';

		$log_prefix = "[Order #{$order_id}]";

		$start_time = microtime(true);

		Bank_Transfer_Payment_Gateway_Logger::info(
			$log_prefix . ' Payment process started',
			[
				'order_id' => $order_id
			]
		);

		wc_clear_notices();

		// -------------------------------------------------
		// 1. ORDER VALIDATION
		// -------------------------------------------------
		$order = wc_get_order($order_id);

		if (!$order) {


			if (is_checkout()) {
				wc_add_notice(__('Invalid order.', 'bank-transfer-payment-gateway'), 'error');
			}

			return $this->build_response(
				'fail',
				'Invalid order.',
				[],
				400,
				$order_id
			);
		}

		Bank_Transfer_Payment_Gateway_Logger::info(
			"Payment initiated",
			[
				'order_id' => $order_id,
				'status'   =>  $order->get_status()
			]
		);

		$lock_name = 'bank_transfer_order_' . $order_id;

		// Try lock
		$lock_result = $wpdb->get_var(
			$wpdb->prepare("SELECT GET_LOCK(%s, 3)", $lock_name)
		);

		if ((string)$lock_result !== '1') {
			return $this->build_response(
				'fail',
				'Payment already in progress. Please wait a few seconds and try again.',
				[],
				409,
				$order_id
			);
		}

		try {

			// -------------------------------------------------
			// 4. RATE LIMITING (UNCHANGED)
			// -------------------------------------------------
			$ip_address  = filter_var($_SERVER['REMOTE_ADDR'] ?? '', FILTER_VALIDATE_IP) ?: 'invalid';
			$window_size = 10;
			$max_requests = 5;

			$timestamp_key = "rate_limit_{$ip_address}_timestamps";
			$timestamps    = get_transient($timestamp_key) ?: [];
			$current_time  = time();

			$timestamps = array_filter($timestamps, fn($ts) => $current_time - $ts <= $window_size);

			if (count($timestamps) >= $max_requests) {

				Bank_Transfer_Payment_Gateway_Logger::warning(
					$log_prefix . ' Rate limit exceeded',
					[
						'ip_address' => $ip_address,
					]
				);

				if (is_checkout()) {
					wc_add_notice(__('Too many requests. Please try again later.', 'bank-transfer-payment-gateway'), 'error');
				}

				return $this->build_response(
					'fail',
					'Too many requests. Please try again later.',
					[],
					429,
					$order_id
				);
			}

			$timestamps[] = $current_time;
			set_transient($timestamp_key, $timestamps, $window_size);

			// -------------------------------------------------
			// 5. ORDER STATUS PROTECTION
			// -------------------------------------------------
			$status = $order->get_status();

			if ($status === 'completed' || $status === 'processing') {

				if (WC()->cart) {
					WC()->cart->empty_cart();
				}

				$redirect = $status === 'completed'
				? $order->get_checkout_order_received_url()
				: $order->get_cancel_order_url();

				return $this->build_response(
					'success',
					'Order already processed',
					[],
					200,
					$order->get_id()
				);
			}

			// -------------------------------------------------
			// 6. SANDBOX FLAG (UNCHANGED)
			// -------------------------------------------------
			if ($this->sandbox) {
				if (!$order->get_meta('_is_test_order')) {
					$order->update_meta_data('_is_test_order', true);
					bank_transfer_add_unique_order_note(
						$order,
						'sandbox_mode',
						__('This is a test order processed in sandbox mode.', 'bank-transfer-payment-gateway')
					);
				}
			}

			// -------------------------------------------------
			// 7. PAYMENT ACCOUNT LOOP (UNCHANGED LOGIC)
			// -------------------------------------------------

		$accounts = $this->get_all_available_accounts();

		if (empty($accounts)) {
			return $this->build_response(
				'fail',
				'No eligible payment provider available.',
				[],
				400,
				$order_id
			);
		}

		$selected_account = null;
		$payment_data     = null;
		$last_error_data  = null;
		$failed_accounts  = [];

		foreach ($accounts as $account) {

			$public_key = $this->sandbox
				? $account['sandbox_public_key']
				: $account['live_public_key'];

			$secret_key = $this->sandbox
				? $account['sandbox_secret_key']
				: $account['live_secret_key'];

			// Skip already used accounts
			if (in_array($public_key, $used_accounts, true)) {

				Bank_Transfer_Payment_Gateway_Logger::info(
					$log_prefix . ' Account skipped (already used)',
					[
						'account_title' => $account['title'] ?? null,
					]
				);

				continue;
			}

			// Prepare payment data
			$data = $this->bank_transfer_prepare_payment_data($order, $public_key, $secret_key);

			if (is_array($data) && ($data['result'] ?? '') === 'fail') {

				Bank_Transfer_Payment_Gateway_Logger::warning(
					$log_prefix . ' Account preparation failed',
					[
						'account_title' => $account['title'] ?? null,
						'error'         => $data['error'] ?? ($data['message'] ?? null),
					]
				);

				$used_accounts[] = $public_key;
				$failed_accounts[] = [
					'account' => $account['title'] ?? null,
					'reason'  => 'prepare_failed',
				];

				continue;
			}

			$limit_url = $this->get_api_url('/api/dailylimit');
			$limit_payload = [
				'is_sandbox'     => $this->sandbox,
				'amount'         => (float) $order->get_total(),
				'api_public_key' => $public_key,
			];

			$limit_resp = wp_remote_post($limit_url, [
				'method'    => 'POST',
				'timeout'   => 5,
				'body'      => wp_json_encode($limit_payload),
				'headers'   => [
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . sanitize_text_field($public_key),
				],
				'sslverify' => true,
			]);

			if (is_wp_error($limit_resp)) {

				Bank_Transfer_Payment_Gateway_Logger::warning(
					$log_prefix . ' Daily limit API WP error',
					[
						'account_title' => $account['title'] ?? null,
						'error'         => $limit_resp->get_error_message(),
					]
				);

				$used_accounts[] = $public_key;
				$failed_accounts[] = [
					'account' => $account['title'] ?? null,
					'reason'  => 'wp_error',
				];

				continue;
			}

			$limit_data = json_decode(wp_remote_retrieve_body($limit_resp), true);

			if (($limit_data['status'] ?? '') === 'error') {

				Bank_Transfer_Payment_Gateway_Logger::warning(
					$log_prefix . ' Account rejected by daily limit API',
					[
						'account_title' => $account['title'] ?? null,
						'response'      => $limit_data,
					]
				);

				$last_error_data = $limit_data;

				$used_accounts[] = $public_key;
				$failed_accounts[] = [
					'account' => $account['title'] ?? null,
					'reason'  => 'limit_error',
					'response'=> $limit_data,
				];

				continue;
			}

			// ✅ SUCCESS
			Bank_Transfer_Payment_Gateway_Logger::info(
				$log_prefix . ' Account selected',
				[
					'account_title' => $account['title'] ?? null,
				]
			);

			$selected_account = $account;
			$payment_data     = $data;

			break;
		}

			if (!$selected_account) {

				if ($last_error_data) {

					if (!empty($last_error_data['max_limit_reached'])) {

						return $this->build_response(
							'fail',
							'The transaction amount exceeds the maximum allowed limit.',
							[],
							400,
							$order_id
						);
					}

					$order->update_meta_data('_bank_transfer_limit_exceeded', true);
					$order->save();

					return $this->build_response(
						'fail',
						sanitize_text_field($last_error_data['message'] ?? 'Payment limit error.'),
						[],
						400,
						$order_id
					);
				}

				Bank_Transfer_Payment_Gateway_Logger::error(
					'No eligible payment provider available for this order.',
					[
						'order_id' => $order_id ?? null
					]
				);

				return $this->build_response(
					'fail',
					'No eligible payment provider available for this order',
					[],
					400,
					$order_id
				);
				}

				// -------------------------------------------------
				// 8. PAYMENT REQUEST
				// -------------------------------------------------
				$account    = $selected_account;
				$data       = $payment_data;

				$public_key = $this->sandbox ? $account['sandbox_public_key'] : $account['live_public_key'];
				$secret_key = $this->sandbox ? $account['sandbox_secret_key'] : $account['live_secret_key'];

				$api_url = esc_url($this->base_url . '/api/request-payment');
				$timestamp = time();
				$raw_body  = is_array($data) ? http_build_query($data) : (string) $data;
				$signature = hash_hmac('sha256', $timestamp . '.' . $raw_body, $secret_key);

				$response = wp_remote_post($api_url, [
					'method'    => 'POST',
					'timeout'   => 30,
					'body'      => $data,
					'headers'   => [
						'Content-Type'  => 'application/x-www-form-urlencoded',
						'Authorization' => 'Bearer ' . sanitize_text_field($public_key),
						'X-Signature'   => $signature,
						'X-Timestamp'   => (string) $timestamp,
					],
					'sslverify' => true,
				]);

				if (is_wp_error($response)) {

					return $this->build_response(
						'fail',
						'Payment error: Unable to process.',
						[],
						500,
						$order_id
					);
				}

				$resp_data = json_decode(wp_remote_retrieve_body($response), true);

				Bank_Transfer_Payment_Gateway_Logger::info(
					'Payment API response received',
					[
						'order_id' => $order_id,
						'status'   => $resp_data['status'] ?? null,
						'pay_id'   => $resp_data['data']['pay_id'] ?? null,
					]
				);

				if (($resp_data['status'] ?? '') === 'error') {

					$error_msg = sanitize_text_field(
						$resp_data['message'] ?? $resp_data['context']['message'] ?? 'Payment failed.'
					);
					if (!$this->is_block_checkout_request() && is_checkout()) {
						wc_add_notice($error_msg, 'error');
					}

					return $this->build_response(
						'fail',
						$error_msg,
						[],
						400,
						$order_id
					);
				}

				// -------------------------------------------------
				// 9. DATABASE (UNCHANGED - KEPT EXACTLY SAME)
				// -------------------------------------------------
				$table_name = $wpdb->prefix . 'order_payment_link';

				$pay_id = $resp_data['data']['pay_id'] ?? '';

				if (!empty($resp_data['data']['payment_link'])) {

					$existing = $wpdb->get_var($wpdb->prepare(
						"SELECT id FROM $table_name WHERE order_id = %d",
						$order_id
					));

					if ($existing) {

						$wpdb->update(
							$table_name,
							[
								'uuid'           => sanitize_text_field($pay_id),
								'payment_link'   => esc_url_raw($resp_data['data']['payment_link']),
								'customer_email' => sanitize_email($resp_data['data']['customer_email']),
								'amount'         => number_format((float)($resp_data['data']['amount'] ?? 0), 2, '.', ''),
								'created_at'     => current_time('mysql', 1),
							],
							['order_id' => $order_id]
						);
					} else {

						$wpdb->insert(
							$table_name,
							[
								'order_id'       => $order_id,
								'uuid'           => sanitize_text_field($pay_id),
								'payment_link'   => esc_url_raw($resp_data['data']['payment_link']),
								'customer_email' => sanitize_email($resp_data['data']['customer_email']),
								'amount'         => number_format((float)($resp_data['data']['amount'] ?? 0), 2, '.', ''),
								'created_at'     => current_time('mysql', 1),
							]
						);
					}

					// BT-22: Invalidate cached payment row and uuid
					wp_cache_delete('bank_transfer_payment_row_' . intval($order_id), 'bank_transfer_payment_gateway');
					wp_cache_delete('bank_transfer_payment_link_uuid_' . intval($order_id), 'bank_transfer_payment_gateway');
				}

				// -------------------------------------------------
				// 10. PAY ID UPDATE (UNCHANGED)
				// -------------------------------------------------
				if (!empty($pay_id)) {

					$order->update_meta_data('_bank_transfer_pay_id', $pay_id);
					$order->update_meta_data('_bank_transfer_pay_id_updated_at', time());

					$order->update_meta_data('_bank_transfer_active_pay_id', $pay_id);
					$order->update_meta_data('_bank_transfer_payment_finalized', false);
					$order->update_meta_data('_bank_transfer_public_key', sanitize_text_field($public_key));
					$order->save();
				}

				// -------------------------------------------------
				// 11. SUCCESS RESPONSE
				// -------------------------------------------------

				$order->update_status('pending', __('Payment pending.', 'bank-transfer-payment-gateway'));

				bank_transfer_add_unique_order_note(
					$order,
					'payment_initiated',
					sprintf(
						__('Payment initiated via Bank Transfer (%s)', 'bank-transfer-payment-gateway'),
						$account['title']
					)
				);

				$payment_link = $resp_data['data']['payment_link'] ?? null;

				if (empty($payment_link)) {

					Bank_Transfer_Payment_Gateway_Logger::error(
						'Missing payment link in response',
						[
							'order_id' => $order_id,
							'pay_id'   => $pay_id ?? null,
						]
					);

					return $this->build_response(
						'fail',
						'Payment could not be initiated. Please try again in a moment.',
						[],
						500,
						$order_id
					);
				}

				Bank_Transfer_Payment_Gateway_Logger::info(
					$log_prefix . ' Payment initiated successfully',
					[
						'order_id'    => $order_id,
						'pay_id'      => $pay_id,
						'payment_link'=> $payment_link,
					]
				);

				return $this->build_response(
					'success',
					'Payment initiated',
					[
						'payment_status' => $resp_data['data']['payment_status'] ?? 'pending',
						'redirect'       => esc_url($payment_link),
						'order_key'      => $order->get_order_key(),
						'bank_transfer_nonce'  => wp_create_nonce('bank_transfer_payment'),
					],
					200,
					$order_id
				);

			} catch (\Exception $e) {

				Bank_Transfer_Payment_Gateway_Logger::error(
					"Payment processing exception: " . $e->getMessage(),
					[
						'order_id' => $order_id ?? null,
						'file'     => $e->getFile(),
						'line'     => $e->getLine(),
						'trace'    => $e->getTraceAsString()
					]
				);

				return $this->build_response('fail', 'An internal error occurred.', [], 500, $order_id);

			} finally {

				$wpdb->query($wpdb->prepare("SELECT RELEASE_LOCK(%s)", $lock_name));
			}
	}

	private function build_response(
		string $result,
		string $message = '',
		array $data = [],
		int $code = 200,
		?int $order_id = null
	) {
		return [
			'result'   => $result, // success | fail
			'message'  => $message,
			'data'     => $data,
			'order_id' => $order_id,
			'code'     => $code,
			'success'  => $result === 'success',
		];
	}

	private function is_block_checkout_request() {
		$action = isset($_REQUEST['action']) ? sanitize_text_field(wp_unslash($_REQUEST['action'])) : '';
		return wp_doing_ajax() && 'bank_transfer_block_gateway_process' === $action;
	}

	public function bank_transfer_display_test_order_tag($order) {
		if ($order instanceof WC_Order && $order->get_meta('_is_test_order')) {
			echo '<p><strong>' . esc_html__('Test Order', 'bank-transfer-payment-gateway') . '</strong></p>';
		}
	}

	private function bank_transfer_get_return_url_base() {
		return rest_url('/bank-transfer/v1/data');
	}

	private function is_po_box($address) {
		if (empty($address)) return false;

		$clean = strtolower(preg_replace('/[^a-z0-9]/i', '', $address));

		return preg_match('/pob|postoffice/', $clean) === 1;
	}

	private function bank_transfer_prepare_payment_data($order, $api_public_key, $api_secret) {
		$order_id    = $order->get_id();
		$is_sandbox  = $this->get_option('sandbox') === 'yes';
		$request_for = sanitize_email($order->get_billing_email() ?: $order->get_billing_phone());
		$first_name  = sanitize_text_field($order->get_billing_first_name());
		$last_name   = sanitize_text_field($order->get_billing_last_name());
		$amount      = number_format($order->get_total(), 2, '.', '');
		$email       = sanitize_text_field($order->get_billing_email());
		$original_phone = $order->get_billing_phone();
		$phone       = sanitize_text_field($original_phone);
		$country     = $order->get_billing_country();
		$country_code = WC()->countries->get_country_calling_code($country);
		
		$billing_address_1 = sanitize_text_field($order->get_billing_address_1());
		$billing_address_2 = sanitize_text_field($order->get_billing_address_2());
		$billing_city      = sanitize_text_field($order->get_billing_city());
		$billing_postcode  = sanitize_text_field($order->get_billing_postcode());
		$billing_country   = sanitize_text_field($order->get_billing_country());
		$billing_state     = sanitize_text_field($order->get_billing_state());

		$redirect_url = esc_url_raw(add_query_arg([
			'order_id' => $order_id,
			'key'      => $order->get_order_key(),
			'mode'     => 'wp',
		], $this->bank_transfer_get_return_url_base()));

		$ip_address = sanitize_text_field($this->bank_transfer_get_client_ip());

		if (empty($order_id)) {
			Bank_Transfer_Payment_Gateway_Logger::error(
				'Order ID is missing or invalid.',
				[]
			);
			return ['result' => 'fail','error'=>'Order ID is missing or invalid.'];
		}

		$meta_data_array = array_map('sanitize_text_field', [
			'order_id' => $order_id,
			'amount'   => $amount,
			'source'   => 'woocommerce',
		]);

		$user_id = $order->get_customer_id();
		$is_guest = empty($user_id) ? 1 : 0;
		if ($is_guest === 1 && !empty($email)) {
			$existing_user = get_user_by('email', $email);
			if ($existing_user) {
				$is_guest = 0;
				$user_id = $existing_user->ID;
			}
		}

		return [
			'api_secret'       => $api_secret,
			'api_public_key'   => $api_public_key,
			'first_name'       => $first_name,
			'last_name'        => $last_name,
			'request_for'      => $request_for,
			'amount'           => $amount,
			'redirect_url'     => $redirect_url,
			'redirect_time'    => 3,
			'ip_address'       => $ip_address,
			'source'           => 'wordpress',
			'meta_data'        => $meta_data_array,
			'remarks'          => 'Order ' . $order->get_order_number(),
			'email'            => $email,
			'phone_number'     => $phone,
			'country_code'     => $country_code,
			'billing_address_1'=> $billing_address_1,
			'billing_address_2'=> $billing_address_2,
			'billing_city'     => $billing_city,
			'billing_postcode' => $billing_postcode,
			'billing_country'  => $billing_country,
			'billing_state'    => $billing_state,
			'is_sandbox'       => $is_sandbox,
			'curr_code'        => sanitize_text_field($order->get_currency()),
			'plugin_source'    => 'bank_transfer',
			'is_guest'         => $is_guest,
			'customer_user_id' => $user_id ? $user_id : 0,
		];
	}

	private function bank_transfer_normalize_phone($phone, $country_code) {
		$cleanedPhone  = preg_replace('/[()\s-]/', '', $phone ?? '');
		$countryCode   = preg_replace('/[^0-9]/', '', $country_code ?? '');
		$phoneNumber   = preg_replace('/[^\d]/', '', $cleanedPhone);

		if (!empty($countryCode) && strlen($phoneNumber) > strlen($countryCode) && strpos($phoneNumber, $countryCode) === 0) {
			$normalizedPhone = substr($phoneNumber, strlen($countryCode));
		} else {
			$normalizedPhone = $phoneNumber;
		}

		$normalizedPhone = ltrim($normalizedPhone, '0');

		if (empty($phoneNumber)) {
			return ['phone' => $normalizedPhone, 'country_code' => '+' . $countryCode, 'is_valid' => true, 'error' => null];
		}

		$localLength   = strlen($normalizedPhone);
		$totalLength   = strlen($countryCode . $normalizedPhone);
		$requires10Digits = in_array($countryCode, ['1']);
		$europeCodes   = ['33','34','39','31','44','46','47','48','49','41','45','358'];

		if ($requires10Digits) {
			if ($localLength !== 10) {
				return ['phone' => $normalizedPhone, 'country_code' => '+' . $countryCode, 'is_valid' => false, 'error' => 'Phone number must be exactly 10 digits.'];
			}
		} elseif (in_array($countryCode, $europeCodes)) {
			$min = ($countryCode === '49' || $countryCode === '358') ? 5 : 8;
			$max = ($countryCode === '49' || $countryCode === '358') ? 11 : 10;
			if ($localLength < $min || $localLength > $max) {
				return ['phone' => $normalizedPhone, 'country_code' => '+' . $countryCode, 'is_valid' => false, 'error' => "European number invalid: should be $min-$max digits"];
			}
		} else {
			// Default international validation
			if ($localLength < 10 || $localLength > 15) {

				return [
					'phone'        => $normalizedPhone,
					'country_code' => '+' . $countryCode,
					'is_valid'     => false,
					'error'        => 'Phone number must be between 10 and 15 digits.'
				];
			}
		}

		if ($totalLength > 15) {
			return ['phone' => $normalizedPhone, 'country_code' => '+' . $countryCode, 'is_valid' => false, 'error' => sprintf('Phone number is too long. Maximum allowed length is 15 digits (including country code). Your phone number has %d digits.', $totalLength)];
		}

		return ['phone' => $normalizedPhone, 'country_code' => '+' . $countryCode, 'is_valid' => true, 'error' => null];
	}

	private function bank_transfer_get_client_ip() {
		if (class_exists('WC_Geolocation')) {
			return WC_Geolocation::get_ip_address();
		}
		$ip = sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'));
		return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
	}

	public function bank_transfer_add_custom_label_to_order_row($line_items, $order) {
		$order_origin = $order->get_meta('_order_origin');
		if (!empty($order_origin)) {
			$line_items[0]['name'] .= ' <span style="background-color: #ffeb3b; color: #000; padding: 3px 5px; border-radius: 3px; font-size: 12px;">' . esc_html($order_origin) . '</span>';
		}
		return $line_items;
	}

	public function bank_transfer_woocommerce_not_active_notice() {
		echo '<div class="error"><p>' . esc_html__('Bank Transfer Payment Gateway requires WooCommerce to be installed and active.', 'bank-transfer-payment-gateway') . '</p></div>';
	}

	public function payment_fields() {
		$description = $this->get_option('description');
		if (is_array($this->selected_account_for_display) && !empty($this->selected_account_for_display['checkout_subtitle'])) {
			$description = $this->selected_account_for_display['checkout_subtitle'];
		} elseif (WC()->cart) {
			
			$accounts = $this->get_all_accounts();
			$sorted   = $this->get_routing_sorted_accounts($accounts);
			if (!empty($sorted) && !empty($sorted[0]['checkout_subtitle'])) {
				$description = $sorted[0]['checkout_subtitle'];
			}
		}

		if ($description) {
			echo wp_kses_post(wpautop(wptexturize(trim($description))));
		}
		if ('yes' === $this->get_option('show_consent_checkbox')) {
			echo '<p class="form-row form-row-wide">
                <label for="bank_transfer_consent">
                    <input type="checkbox" id="bank_transfer_consent" name="bank_transfer_consent" /> ' .
				esc_html__('I consent to the collection of my data to process this payment', 'bank-transfer-payment-gateway') .
				'</label></p>';
			wp_nonce_field('bank_transfer_payment', 'bank_transfer_nonce');
		}
	}

	public function validate_fields() {

		if ($this->get_option('show_consent_checkbox') === 'yes') {
			$nonce = isset($_POST['bank_transfer_nonce']) ? sanitize_text_field(wp_unslash($_POST['bank_transfer_nonce'])) : '';
			if (empty($nonce) || !wp_verify_nonce($nonce, 'bank_transfer_payment')) {
				wc_add_notice(__('Nonce verification failed. Please try again.', 'bank-transfer-payment-gateway'), 'error');
				return false;
			}
			$consent = isset($_POST['bank_transfer_consent']) ? sanitize_text_field(wp_unslash($_POST['bank_transfer_consent'])) : '';
			if ($consent !== 'on') {
				wc_add_notice(__('You must consent to the collection of your data to process this payment.', 'bank-transfer-payment-gateway'), 'error');
				return false;
			}
		}
		return true;
	}

	public function bank_transfer_enqueue_styles_and_scripts() {
		if (is_checkout()) {
			$image_url = plugin_dir_url(dirname(__FILE__)) . 'assets/images/loader.gif';
			wp_enqueue_style('bank-transfer-payment-loader-styles', plugins_url('../assets/css/frontend.css', __FILE__), [], '1.0', 'all');
			wp_enqueue_script('bank-transfer-js', plugins_url('../assets/js/bank-transfer.js', __FILE__), ['jquery'], '1.0', true);
			wp_localize_script('bank-transfer-js', 'bank_transfer_params', [
				'ajax_url'       => admin_url('admin-ajax.php'),
				'checkout_url'   => wc_get_checkout_url(),
				'bank_transfer_loader' => $image_url,
				'bank_transfer_nonce'  => wp_create_nonce('bank_transfer_payment'),
				'payment_method' => $this->id,
			]);
		}
	}

	function bank_transfer_admin_scripts($hook) {
		if (
			'woocommerce_page_wc-settings' !== $hook ||
			(sanitize_text_field(wp_unslash($_GET['section'] ?? '')) !== $this->id) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		) {
			return;
		}
		wp_enqueue_style('bank-transfer-font-awesome', plugins_url('../assets/css/font-awesome.css', __FILE__), [], filemtime(plugin_dir_path(__FILE__) . '../assets/css/font-awesome.css'), 'all');
		wp_enqueue_style('bank-transfer-admin-css', plugins_url('../assets/css/admin.css', __FILE__), [], filemtime(plugin_dir_path(__FILE__) . '../assets/css/admin.css'), 'all');
		wp_enqueue_script('bank-transfer-admin-script', plugins_url('../assets/js/bank-transfer-admin.js', __FILE__), ['jquery'], filemtime(plugin_dir_path(__FILE__) . '../assets/js/bank-transfer-admin.js'), true);
		wp_localize_script('bank-transfer-admin-script', 'bank_transfer_admin_data', [
			'ajax_url'   => admin_url('admin-ajax.php'),
			'nonce'      => wp_create_nonce('bank_transfer_sync_nonce'),
			'gateway_id' => $this->id,
		]);
	}

	public function bank_transfer_hide_custom_payment_gateway_conditionally($available_gateways)
	{
		$gateway_id = $this->id;
		$this->selected_account_for_display = null;

		// =====================================================
		// STEP 1: SAFE CART CHECK
		// =====================================================
		if (!WC()->cart) {
			return $available_gateways;
		}

		// =====================================================
		// STEP 2: CHECKOUT CONTEXT (STRICT)
		// =====================================================
		$is_ajax = function_exists('wp_doing_ajax') && wp_doing_ajax();
		$is_blocks = defined('REST_REQUEST') && REST_REQUEST && !is_admin();
		$is_checkout_page = function_exists('is_checkout') && is_checkout();

		if (!$is_checkout_page && !$is_ajax && !$is_blocks) {
			return $available_gateways;
		}

		// =====================================================
		// STEP 3: FLOW LABEL
		// =====================================================
		$flow = 'Checkout (Classic)';

		if ($is_blocks) {
			$flow = 'Checkout (Blocks)';
		} elseif ($is_ajax) {
			$flow = 'Checkout (AJAX)';
		}

		// =====================================================
		// STEP 4: CART INFO
		// =====================================================
		$amount = (float) WC()->cart->get_total('raw');
		if ($amount < 0.01) {
			$amount = (float) (WC()->cart->get_totals()['total'] ?? 0);
		}

		$items = count(WC()->cart->get_cart());

		// =====================================================
		// STEP 5: REQUEST FINGERPRINT (REAL FIX)
		// =====================================================
		static $executed = false;

		$fingerprint = md5(json_encode([
			'flow'   => $flow,
			'items'  => $items,
			'total'  => $amount,
			'ajax'   => $is_ajax,
			'blocks' => $is_blocks
		]));

		if ($executed === $fingerprint) {
			return $available_gateways;
		}

		$executed = $fingerprint;

		// =====================================================
		// STEP 6: LOAD ACCOUNTS
		// =====================================================
		if (!method_exists($this, 'get_all_accounts')) {
			return $available_gateways;
		}

		$accounts = $this->get_all_accounts();

		// =====================================================
		// STEP 7: NO ACCOUNTS
		// =====================================================
		if (empty($accounts)) {

			Bank_Transfer_Payment_Gateway_Logger::info(
				"Bank Transfer Gateway Decision",
				[
					'result' => 'HIDDEN',
					'reason' => 'No merchant accounts configured',
					'items'  => $items,
					'total'  => $amount,
					'flow'   => $flow
				]
			);

			return $this->hide_gateway($available_gateways, $gateway_id);
		}

		// =====================================================
		// STEP 8: SORT
		// =====================================================
		usort($accounts, fn($a, $b) =>
			($a['priority'] ?? 1) <=> ($b['priority'] ?? 1)
		);

		// =====================================================
		// STEP 9: EVALUATION
		// =====================================================
		$selected = null;
		$reason   = 'No eligible merchant account';

		foreach ($accounts as $account) {
			$public = $this->sandbox
				? ($account['sandbox_public_key'] ?? '')
				: ($account['live_public_key'] ?? '');

			$secret = $this->sandbox
				? ($account['sandbox_secret_key'] ?? '')
				: ($account['live_secret_key'] ?? '');

			if (empty($public) || empty($secret)) {
				continue;
			}

			$selected = $account;
			$reason   = 'Valid merchant account found';
			break;
		}

		// =====================================================
		// STEP 10: SINGLE FINAL LOG ONLY
		// =====================================================
		Bank_Transfer_Payment_Gateway_Logger::info(
			"Bank Transfer Gateway Decision",
			[
				'result' => $selected ? 'SHOWN' : 'HIDDEN',
				'reason' => $reason,
				'items'  => $items,
				'total'  => $amount,
				'flow'   => $flow,
				'account'=> $selected['title'] ?? null
			]
		);

		// =====================================================
		// STEP 11: RETURN RESULT
		// =====================================================
		$this->selected_account_for_display = $selected;

		if (!$selected) {
			return $this->hide_gateway($available_gateways, $gateway_id);
		}

		return $available_gateways;
	}
	private function send_plugin_logs($accounts, $public_key, $secret_key, $amount, $gateway_loaded, $pluginLogApiUrl, $force_refresh = false)
	{
		$plugin_version = defined('BANK_TRANSFER_PLUGIN_VERSION') ? BANK_TRANSFER_PLUGIN_VERSION : '0.1.0';
		$accounts       = $this->update_accounts_uniqueID($accounts);
		$group_id       = get_option('bank_transfer_group_id');
		$cache_base     = 'bank_transfer_daily_limit_' . md5($public_key . $amount);

		$sanitized_accounts = array_map(function($acc) {
			unset($acc['live_secret_key'], $acc['sandbox_secret_key']);
			return $acc;
		}, (array) $accounts);

		$plugin_logs_data = [
			'valid_accounts'         => $sanitized_accounts,
			'gateway_loaded'         => $gateway_loaded,
			'plugin_status'          => $gateway_loaded,
			'plugin_version'         => $plugin_version,
			'api_public_key'         => $public_key,
			'signature'              => hash_hmac('sha256', $public_key . '|' . $gateway_loaded, $secret_key),
			'is_sandbox'             => $this->sandbox,
			'group_id'               => $group_id ? $group_id : $this->bank_transfer_get_group_id(),
			'domain_name'            => parse_url(home_url(), PHP_URL_HOST),
			'wordpress_version'      => get_bloginfo('version'),
			'woocommerce_version'    => get_option('woocommerce_version') ?: '',
			'woocommerce_db_version' => get_option('woocommerce_db_version'),
		];

		$this->get_cached_api_response(
			$pluginLogApiUrl,
			$plugin_logs_data,
			$cache_base . '_pluginlogs',
			300,
			$force_refresh
		);
	}

	private function gateway_visibility_label($reason) {

		return match ($reason) {

			'no_accounts' => 'No payment accounts configured',
			'merchant_inactive' => 'Payment provider unavailable',
			'daily_limit_exceeded' => 'Daily limit reached for this account',
			'no_eligible_accounts' => 'No valid payment account found',
			'non_checkout_page' => 'Not on checkout page',

			default => 'Payment validation step executed'
		};
	}

	private function hide_gateway($available_gateways, $gateway_id) {
		unset($available_gateways["bank_transfer"]);
		$GLOBALS['bank_transfer_gateway_visibility_' . $this->id] = $available_gateways;
		return $available_gateways;
	}

	private function log_info_once_per_session($key, $message, $context = [])
	{
		if (!function_exists('WC') || !WC()) {
			return;
		}

		if (!WC()->session) {
			return;
		}

		// -----------------------------
		// FIXED FLOW DETECTION (REAL WOOCOMMERCE SAFE)
		// -----------------------------
		$flow = 'background';

		$is_ajax = defined('DOING_AJAX') && DOING_AJAX;
		$is_rest = defined('REST_REQUEST') && REST_REQUEST;

		$wc_ajax = isset($_REQUEST['wc-ajax']) ? sanitize_text_field(wp_unslash($_REQUEST['wc-ajax'])) : '';

		if (is_checkout()) {
			$flow = 'checkout_page';
		} elseif ($is_rest) {
			$flow = 'checkout_block';
		} elseif ($is_ajax && $wc_ajax === 'update_order_review') {
			$flow = 'checkout_refresh';
		}

		// -----------------------------
		// SAFE CONTEXT
		// -----------------------------
		$clean_context = [
			'Gateway' => $this->id,
			'Flow'    => $flow,
		];

		if (WC()->cart) {
			$clean_context['Items'] = count(WC()->cart->get_cart());
			$clean_context['Total'] = (float) WC()->cart->get_total('raw');
		}

		if (isset($context['reason'])) {
			$clean_context['Reason'] = $this->gateway_visibility_label($context['reason']);
		}

		if (isset($context['account'])) {
			$clean_context['Account'] = $context['account'];
		}

		// -----------------------------
		// STABLE SESSION KEY
		// -----------------------------
		$session_key = 'bank_transfer_log_' . md5($key . $this->id);

		if (WC()->session->get($session_key)) {
			return;
		}

		WC()->session->set($session_key, true);

		Bank_Transfer_Payment_Gateway_Logger::info($message, $clean_context);
	}

	private function get_cached_api_response($url, $data, $cache_key, $ttl = 300, $force_refresh = false) {
		if (!$force_refresh && isset($_GET['refresh_accounts']) && sanitize_text_field(wp_unslash($_GET['refresh_accounts'])) === '1' && isset($_GET['_wpnonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'refresh_accounts_nonce')) {
			$force_refresh = true;
		}
		if (!$force_refresh) {
			$cached = get_transient($cache_key);
			if ($cached !== false) return $cached;
		} else {
			delete_transient($cache_key);
		}
		$response = wp_remote_post($url, [
			'method'    => 'POST',
			'timeout'   => 5,
			'headers'   => [
				'Content-Type'  => 'application/json',
				'Authorization' => 'Bearer ' . sanitize_text_field($data['api_public_key'] ?? ''),
			],
			'body'      => wp_json_encode($data),
			'sslverify' => true,
		]);
		if (is_wp_error($response)) {
			return null;
		}
		$body = json_decode(wp_remote_retrieve_body($response), true);
		if (!empty($body)) {
			set_transient($cache_key, $body, $ttl);
		}
		return $body;
	}

	private function get_all_accounts() {
		$accounts = get_option('woocommerce_bank_transfer_payment_gateway_accounts', []);
		if (is_string($accounts)) {
			$unserialized = maybe_unserialize($accounts);
			$accounts = is_array($unserialized) ? $unserialized : [];
		}
		$valid_accounts = [];
		foreach ($accounts as $i => $account) {
			if ($this->sandbox) {
				$status   = strtolower($account['sandbox_status'] ?? '');
				$has_keys = !empty($account['sandbox_public_key']) && !empty($account['sandbox_secret_key']);
				if ($status === 'active' && $has_keys) $valid_accounts[] = $account;
			} else {
				$status   = strtolower($account['live_status'] ?? '');
				$has_keys = !empty($account['live_public_key']) && !empty($account['live_secret_key']);
				if ($status === 'active' && $has_keys) $valid_accounts[] = $account;
			}
		}
		$this->accounts = $valid_accounts;
		return $valid_accounts;
	}

	private function get_routing_sorted_accounts(array $accounts): array {
		usort($accounts, function ($a, $b) {
			return ($a['priority'] ?? 1) <=> ($b['priority'] ?? 1);
		});
		return array_values($accounts);
	}

	/**
	 * Get checkout display info (title + subtitle) for a given cart amount.
	 * BT-03 & BT-17: Never sends secret keys; reads stored status and caches daily limit for 5 min.
	 *
	 * @param float $amount Order/cart total.
	 * @return array ['title' => string, 'subtitle' => string]
	 */
	public function get_checkout_info_for_amount(float $amount): array {
		$accounts = $this->get_all_accounts();
		if (empty($accounts)) {
			return [
				'title'    => $this->title,
				'subtitle' => $this->description,
			];
		}

		$sorted_accounts = $this->get_routing_sorted_accounts($accounts);
		$transactionLimitApiUrl = $this->get_api_url('/api/dailylimit');

		$force_refresh = (
			isset($_GET['refresh_accounts'], $_GET['_wpnonce']) &&
			sanitize_text_field(wp_unslash($_GET['refresh_accounts'])) === '1' &&
			wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'refresh_accounts_nonce')
		);

		$selected_account = null;
		foreach ($sorted_accounts as $account) {
			$public_key = $this->sandbox ? ($account['sandbox_public_key'] ?? '') : ($account['live_public_key'] ?? '');
			$secret_key = $this->sandbox ? ($account['sandbox_secret_key'] ?? '') : ($account['live_secret_key'] ?? '');
			if (empty($public_key) || empty($secret_key)) {
				continue;
			}

			// BT-17: Check stored account status synced by cron instead of making remote calls on every checkout render
			$status = strtolower($this->sandbox ? ($account['sandbox_status'] ?? 'active') : ($account['live_status'] ?? 'active'));
			if ($status !== 'active') {
				continue;
			}

			// BT-03 & BT-17: Only send public key and amount (NO secret keys), cache for 5 minutes with 5s timeout
			$data = [
				'is_sandbox'     => $this->sandbox,
				'amount'         => $amount,
				'api_public_key' => $public_key,
			];
			$cache_base  = 'bank_transfer_daily_limit_' . md5($public_key . '_' . $amount);
			$limit_data  = $this->get_cached_api_response($transactionLimitApiUrl, $data, $cache_base . '_limit', 300, $force_refresh);

			if (!empty($limit_data) && ($limit_data['status'] ?? '') === 'success') {
				$selected_account = $account;
				break;
			}
		}

		if (!$selected_account && !empty($sorted_accounts)) {
			$selected_account = $sorted_accounts[0];
		}

		$title    = !empty($selected_account['checkout_title']) ? $selected_account['checkout_title'] : $this->title;
		$subtitle = !empty($selected_account['checkout_subtitle']) ? $selected_account['checkout_subtitle'] : $this->description;

		return [
			'title'    => $title,
			'subtitle' => $subtitle,
		];
	}
}
