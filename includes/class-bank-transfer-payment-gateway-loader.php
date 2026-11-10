<?php
if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly.
}

// Include the configuration file
require_once plugin_dir_path(__FILE__) . 'config.php';
require_once plugin_dir_path(__FILE__) . 'bank-transfer-payment-gateway-utils.php';
require_once plugin_dir_path(__FILE__) . 'class-bank-transfer-payment-state-engine.php';
require_once plugin_dir_path(__FILE__) . 'class-bank-transfer-payment-logger.php';
/**
 * Class BANK_TRANSFER_PAYMENT_GATEWAY_Loader
 * Handles the loading and initialization of the Bank Transfer Payment Gateway plugin.
 */
class BANK_TRANSFER_PAYMENT_GATEWAY_Loader
{
	private static $instance = null;
	private $admin_notices;

	private $base_url;

	/**
	 * Get the singleton instance of this class.
	 * @return BANK_TRANSFER_PAYMENT_GATEWAY_Loader
	 */
	public static function get_instance()
	{
		if (null === self::$instance) {
			self::$instance = new self();
		}
		return self::$instance;
	}


	/**
	 * Constructor. Sets up actions and hooks.
	 */
	private function __construct()
	{

		$this->base_url = BANK_TRANSFER_BASE_URL;
		
		$this->admin_notices = new BANK_TRANSFER_PAYMENT_GATEWAY_Admin_Notices();

		add_action('admin_init', [$this, 'bank_transfer_handle_environment_check']);
		add_action('admin_notices', [$this->admin_notices, 'display_notices']);
		add_action('plugins_loaded', [$this, 'bank_transfer_init'], 10);

		// Register the AJAX action callback for checking payment status
		add_action('wp_ajax_bank_transfer_check_payment_status', array($this, 'bank_transfer_handle_check_payment_status_request'));
		add_action('wp_ajax_nopriv_bank_transfer_check_payment_status', array($this, 'bank_transfer_handle_check_payment_status_request'));

		add_action('wp_ajax_bank_transfer_popup_closed_event', array($this, 'handle_popup_close'));
		add_action('wp_ajax_nopriv_bank_transfer_popup_closed_event', array($this, 'handle_popup_close'));

		add_action('wp_ajax_bank_transfer_manual_sync', [$this, 'bank_transfer_manual_sync_callback']);
		add_filter('cron_schedules', [$this, 'bank_transfer_add_cron_interval']);
		add_action('bank_transfer_cron_event', [$this, 'handle_cron_event']);
		add_action('wp_ajax_bank_transfer_block_gateway_process', [$this,'handle_bank_transfer_gateway_ajax']);
		add_action('wp_ajax_nopriv_bank_transfer_block_gateway_process', [$this,'handle_bank_transfer_gateway_ajax']); 
		// Order status sync: cron, admin screens, customer return (mirrors the fix shipped in the NFT Checkout gateway)
		add_action('bank_transfer_async_sync_order', [$this, 'sync_single_order_status'], 10, 1);
		add_action('woocommerce_admin_order_data_after_order_details', [$this, 'bank_transfer_admin_order_sync_on_view'], 5);
		add_action('current_screen', [$this, 'bank_transfer_admin_orders_screen_sync']);
		add_action('load-woocommerce_page_wc-orders', [$this, 'bank_transfer_admin_orders_screen_sync']);
		add_action('load-edit.php', [$this, 'bank_transfer_admin_orders_screen_sync']);
		add_action('load-post.php', [$this, 'bank_transfer_admin_orders_screen_sync']);
		add_filter('woocommerce_shop_order_list_table_order_css_classes', [$this, 'bank_transfer_admin_order_row_filter'], 10, 2);
		add_filter('post_class', [$this, 'bank_transfer_admin_post_class_filter'], 10, 3);
		add_filter('woocommerce_order_actions', [$this, 'bank_transfer_add_order_actions'], 10, 2);
		add_action('woocommerce_order_action_bank_transfer_check_status', [$this, 'bank_transfer_process_order_action_check_status']);
		add_action('woocommerce_before_thankyou', [$this, 'bank_transfer_customer_order_sync']);
		add_action('woocommerce_view_order', [$this, 'bank_transfer_customer_order_sync']);

		add_action('woocommerce_before_checkout_form', [$this, 'bank_transfer_show_checkout_error']);

		// BT-33: Prevent order reuse on standard checkout for this gateway if identity changes
		add_action('woocommerce_before_checkout_process', function() {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$payment_method = isset($_POST['payment_method']) ? sanitize_text_field(wp_unslash($_POST['payment_method'])) : '';
			if ('bank_transfer' === $payment_method) {
				if (function_exists('WC') && WC()->session) {
					$awaiting_order_id = WC()->session->get('order_awaiting_payment');
					if ($awaiting_order_id) {
						$awaiting_order = wc_get_order($awaiting_order_id);
						// phpcs:ignore WordPress.Security.NonceVerification.Missing
						$posted_email   = isset($_POST['billing_email']) ? sanitize_email(wp_unslash($_POST['billing_email'])) : '';
						// phpcs:ignore WordPress.Security.NonceVerification.Missing
						$posted_phone   = isset($_POST['billing_phone']) ? sanitize_text_field(wp_unslash($_POST['billing_phone'])) : '';
						
						if ($awaiting_order && ($awaiting_order->get_billing_email() !== $posted_email || $awaiting_order->get_billing_phone() !== $posted_phone)) {
							WC()->session->set('order_awaiting_payment', null);
						}
					}
				}
			}
		});
	}

	/**
	 * ── FIXED ──────────────────────────────────────────────────────────────────
	 * Handle the block checkout AJAX payment request.
	 *
	 * Root cause of "No available payment accounts":
	 * `new BANK_TRANSFER_PAYMENT_GATEWAY()` creates a cold instance. In an AJAX
	 * context WooCommerce has not called init_settings() on it, so
	 * $this->sandbox defaults to false and get_option() returns empty values.
	 * get_next_available_account() then finds no matching keys → returns false.
	 *
	 * Fix: pull the already-booted instance from WC()->payment_gateways().
	 * That instance was fully initialised during the normal WC boot cycle so
	 * sandbox mode and account keys are correct.
	 * ───────────────────────────────────────────────────────────────────────────
	 */
	function handle_bank_transfer_gateway_ajax(){
		// Nonce verification
		$nonce = isset($_POST['nonce'])
			? sanitize_text_field(wp_unslash($_POST['nonce']))
			: '';

		if (empty($nonce) || !wp_verify_nonce($nonce, 'bank_transfer_payment')) {
			wp_send_json(['result' => 'fail', 'error' => 'Security check failed.']);
			die;
		}

		// Force payment method to bank_transfer (never trust posted value)
		$_POST['payment_method'] = 'bank_transfer';
		$_REQUEST['payment_method'] = 'bank_transfer';
		if (empty($_POST['bank_transfer_nonce'])) {
			$_POST['bank_transfer_nonce'] = $nonce;
		}

		$this->check_rate_limit('block_ajax', 20, 60);

		// Pull the already-initialised gateway from the WC registry.
		// Never use `new BANK_TRANSFER_PAYMENT_GATEWAY()` here — see note above.
		$gateways       = WC()->payment_gateways()->payment_gateways();
		$bank_transferPayment = $gateways['bank_transfer'] ?? null;

		if (!$bank_transferPayment) {
			// Fallback: manually instantiate and force-load settings from DB.
			// Should never happen in normal operation.
			$bank_transferPayment = new BANK_TRANSFER_PAYMENT_GATEWAY();
			$bank_transferPayment->init_settings();
			$bank_transferPayment->load_gateway_settings();

			Bank_Transfer_Payment_Gateway_Logger::warning(
				'Bank Transfer: gateway not found in WC registry during AJAX — fell back to manual instantiation.',
				['source' => 'bank-transfer-payment-gateway']
			);
		}

		// Route Block Checkout AJAX requests through WooCommerce's standard checkout validation pipeline using WC()->checkout()->process_checkout() wherever compatible
		$wc_nonce = isset($_REQUEST['woocommerce-process-checkout-nonce']) ? $_REQUEST['woocommerce-process-checkout-nonce'] : (isset($_REQUEST['_wpnonce']) ? $_REQUEST['_wpnonce'] : '');
		if (!empty($wc_nonce) && wp_verify_nonce($wc_nonce, 'woocommerce-process_checkout')) {
			WC()->checkout()->process_checkout();
			wp_die();
		}

		if ( empty( WC()->cart ) || WC()->cart->is_empty() ) {
			wp_send_json(['result' => 'failure', 'error' => __('Your cart is currently empty.', 'woocommerce')]);
			die;
		}

		$orderID = $this->bank_transfer_create_block_order($bank_transferPayment);

		$status = [];
		if($orderID){
			$status = $bank_transferPayment->process_payment($orderID);
		}else{
			wc_add_notice(__('Invalid order.', 'bank-transfer-payment-gateway'), 'error');
			$status = ['result' => 'failure','error' => 'Invalid order.'];
		}
		
		wp_send_json($status);
		die;
	}

	/**
	 * Create the WooCommerce order for a block checkout payment.
	 *
	 * Executes WooCommerce checkout validation pipeline (billing, shipping,
	 * terms, CAPTCHA/anti-fraud, after_checkout_validation, gateway validate_fields)
	 * and creates the order.
	 *
	 * @param WC_Payment_Gateway|null $bank_transferPayment
	 * @return int Order ID, or 0 on failure.
	 */
	private function bank_transfer_create_block_order($bank_transferPayment = null) {

		$checkout = WC()->checkout();
		$data     = $checkout->get_posted_data();
		$customer = WC()->customer;

		if ( $customer ) {

			$fields = [
				'first_name',
				'last_name',
				'company',
				'address_1',
				'address_2',
				'city',
				'state',
				'postcode',
				'country',
				'phone',
				'email',
			];

			foreach ( $fields as $field ) {

				$key = 'billing_' . $field;

				if ( ! empty( $data[ $key ] ) ) {
					continue;
				}

				$getter = 'get_' . $key;

				if ( is_callable( [ $customer, $getter ] ) ) {
					$data[ $key ] = $customer->$getter();
				}
			}
		}

		// Force payment method to bank_transfer (never trust posted value)
		$data['payment_method'] = 'bank_transfer';

		// ---------------------------------------------------------------------
		// 1. BILLING AND SHIPPING FIELD VALIDATION
		// ---------------------------------------------------------------------
		$errors          = new WP_Error();
		$checkout_fields = $checkout->get_checkout_fields();

		if ( ! empty( $checkout_fields['billing'] ) ) {
			foreach ( $checkout_fields['billing'] as $key => $field ) {
				$val = $data[ $key ] ?? '';
				if ( ! empty( $field['required'] ) && '' === trim( (string) $val ) ) {
					$label = $field['label'] ?? $key;
					$errors->add( $key . '_required', sprintf( __( '%s is a required field.', 'woocommerce' ), '<strong>' . esc_html( $label ) . '</strong>' ) );
				}
				if ( ! empty( $field['validate'] ) && in_array( 'email', (array) $field['validate'], true ) && '' !== $val ) {
					if ( ! is_email( $val ) ) {
						$label = $field['label'] ?? $key;
						$errors->add( $key . '_validation', sprintf( __( '%s is not a valid email address.', 'woocommerce' ), '<strong>' . esc_html( $label ) . '</strong>' ) );
					}
				}
			}
		}

		if ( WC()->cart && WC()->cart->needs_shipping() ) {
			$ship_to_different = ! empty( $data['ship_to_different_address'] );
			$shipping_country  = $ship_to_different
				? ( $data['shipping_country'] ?? ( $customer ? $customer->get_shipping_country() : '' ) )
				: ( $data['billing_country'] ?? ( $customer ? $customer->get_billing_country() : '' ) );

			if ( empty( $shipping_country ) ) {
				$errors->add( 'shipping', __( 'Please enter an address to continue.', 'woocommerce' ) );
			} elseif ( ! in_array( $shipping_country, array_keys( WC()->countries->get_shipping_countries() ), true ) ) {
				$errors->add( 'shipping', __( 'Unfortunately, we do not ship to your location. Please enter an alternative shipping address.', 'woocommerce' ) );
			}

			if ( $ship_to_different && ! empty( $checkout_fields['shipping'] ) ) {
				foreach ( $checkout_fields['shipping'] as $key => $field ) {
					$val = $data[ $key ] ?? ( $customer && is_callable( [ $customer, 'get_' . $key ] ) ? $customer->{'get_' . $key}() : '' );
					if ( ! empty( $field['required'] ) && '' === trim( (string) $val ) ) {
						$label = $field['label'] ?? $key;
						$errors->add( $key . '_required', sprintf( __( 'Shipping %s is a required field.', 'woocommerce' ), '<strong>' . esc_html( $label ) . '</strong>' ) );
					}
				}
			}
		}

		// ---------------------------------------------------------------------
		// 2. TERMS AND CONDITIONS VALIDATION
		// ---------------------------------------------------------------------
		$terms_page_id = function_exists('wc_terms_and_conditions_page_id') ? wc_terms_and_conditions_page_id() : 0;
		if ( $terms_page_id > 0 && function_exists('wc_terms_and_conditions_checkbox_enabled') && wc_terms_and_conditions_checkbox_enabled() ) {
			$terms_accepted = ! empty( $_POST['terms'] ) || ! empty( $data['terms'] );
			if ( ! $terms_accepted ) {
				$errors->add( 'terms', __( 'Please read and accept the terms and conditions to proceed with your order.', 'woocommerce' ) );
			}
		}

		// ---------------------------------------------------------------------
		// 3. CHECKOUT PROCESS HOOKS (CAPTCHA & ANTI-FRAUD)
		// ---------------------------------------------------------------------
		do_action( 'woocommerce_before_checkout_process' );
		do_action( 'woocommerce_checkout_process' );

		// ---------------------------------------------------------------------
		// 4. AFTER CHECKOUT VALIDATION HOOKS (GATEWAY PHONE/ZIP & ANTI-FRAUD)
		// ---------------------------------------------------------------------
		do_action( 'woocommerce_after_checkout_validation', $data, $errors );

		if ( $errors->has_errors() || wc_notice_count( 'error' ) > 0 ) {
			$err_msg = $errors->has_errors() ? $errors->get_error_message() : '';
			if ( empty( $err_msg ) && wc_notice_count( 'error' ) > 0 ) {
				$notices = wc_get_notices( 'error' );
				$first   = reset( $notices );
				$err_msg = is_array( $first ) ? ( $first['notice'] ?? '' ) : (string) $first;
			}
			wc_clear_notices();
			wp_send_json( [ 'result' => 'failure', 'error' => wp_strip_all_tags( $err_msg ?: 'Validation failed.' ) ] );
			die;
		}

		// ---------------------------------------------------------------------
		// 5. GATEWAY FIELD VALIDATION (CONSENT CHECKBOX, ETC.)
		// ---------------------------------------------------------------------
		if ( $bank_transferPayment && ! $bank_transferPayment->validate_fields() ) {
			$notices = wc_get_notices( 'error' );
			$err_msg = 'Validation failed.';
			if ( ! empty( $notices ) ) {
				$first   = reset( $notices );
				$err_msg = is_array( $first ) ? ( $first['notice'] ?? $err_msg ) : (string) $first;
			}
			wc_clear_notices();
			wp_send_json( [ 'result' => 'failure', 'error' => wp_strip_all_tags( $err_msg ) ] );
			die;
		}

		// Prevent reusing an order if the email address OR phone number doesn't match
		if ( WC()->session ) {
			$awaiting_order_id = WC()->session->get('order_awaiting_payment') ?: WC()->session->get('store_api_draft_order');
			if ( $awaiting_order_id ) {
				$awaiting_order = wc_get_order( $awaiting_order_id );
				$posted_email   = sanitize_email( $data['billing_email'] ?? '' );
				$posted_phone   = sanitize_text_field( $data['billing_phone'] ?? '' );
				
				if ( $awaiting_order && ( $awaiting_order->get_billing_email() !== $posted_email || $awaiting_order->get_billing_phone() !== $posted_phone ) ) {
					WC()->session->set( 'order_awaiting_payment', null );
					WC()->session->set( 'store_api_draft_order', null );
				}
			}
		}

		$order_id = $checkout->create_order( $data );

		if ( is_wp_error( $order_id ) ) {

			Bank_Transfer_Payment_Gateway_Logger::error(
				'Could not create order for block checkout',
				[
					'error' => $order_id->get_error_message(),
				]
			);

			return 0;
		}

		if ( WC()->session ) {
			WC()->session->set( 'order_awaiting_payment', $order_id );
		}

		$created = wc_get_order( $order_id );
		if ( $created ) {
			$version = bank_transfer_get_plugin_version();
			if ( ! empty( $version ) ) {
				$created->update_meta_data( '_bank_transfer_plugin_version', sanitize_text_field( $version ) );
				$created->update_meta_data( 'bank_transfer_plugin_version', sanitize_text_field( $version ) );
				$created->save();
			}
		}

		Bank_Transfer_Payment_Gateway_Logger::info(
			'Created order for block checkout',
			[
				'order_id' => $order_id,
				'total'    => $created ? $created->get_total() : null,
				'email'    => $created ? $created->get_billing_email() : null,
			]
		);

		return $order_id;
	}

	/**
	 * Initializes the plugin.
	 * This method is hooked into 'plugins_loaded' action.
	 */
	public function bank_transfer_init()
	{
		// Check if the environment is compatible
		$environment_warning = bank_transfer_check_system_requirements();
		if ($environment_warning) {
			return;
		}

		// Initialize gateways
		$this->bank_transfer_init_gateways();

		// Register blocks gateway
		$this->bank_transfer_init_blocks();
		
		add_action( 'enqueue_block_assets', [ $this, 'register_blocks_assets' ] );

		// Initialize REST API
		$rest_api = BANK_TRANSFER_PAYMENT_GATEWAY_REST_API::get_instance();
		$rest_api->bank_transfer_register_routes();

		// Add plugin action links
		add_filter('plugin_action_links_' . plugin_basename(BANK_TRANSFER_PAYMENT_GATEWAY_FILE), [$this, 'bank_transfer_plugin_action_links']);

		// Add plugin row meta
		add_filter('plugin_row_meta', [$this, 'bank_transfer_plugin_row_meta'], 10, 2);
	}

	public function bank_transfer_show_checkout_error()
	{
		if (!function_exists('WC')) return;

		$error = WC()->session->get('bank_transfer_error');
		if (!$error) return;

		$messages = [
			'failed'    => 'Payment failed. Please try again.',
			'cancelled' => 'Payment was cancelled.',
			'expired'   => 'Payment session expired. Please try again.'
		];

		// Clear error immediately
		WC()->session->__unset('bank_transfer_error');

		if (isset($messages[$error])) {
			wc_add_notice($messages[$error], 'error');
		}
	}

	/**
	 * Initialize gateways.
	 */
	private function bank_transfer_init_gateways()
	{
		if (!class_exists('WC_Payment_Gateway')) {
			return;
		}

		include_once BANK_TRANSFER_PAYMENT_GATEWAY_PLUGIN_DIR . 'includes/class-bank-transfer-payment-gateway.php';

		add_filter('woocommerce_payment_gateways', function ($methods) {
			$methods[] = 'BANK_TRANSFER_PAYMENT_GATEWAY';			
			return $methods;
		});
	}

	private function bank_transfer_init_blocks() {
		
			if ( class_exists( '\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {

				require_once BANK_TRANSFER_PAYMENT_GATEWAY_PLUGIN_DIR . 'includes/class-bank-transfer-blocks-gateway.php';

				add_action( 'woocommerce_blocks_payment_method_type_registration', function( $registry ) {
					$registry->register( new BANK_TRANSFER_Blocks_Gateway() );
				});
			}
	
	}
	
	public function register_blocks_assets() {
		
		if ( function_exists('is_checkout') && is_checkout() ) {
			$image_url = plugin_dir_url( dirname( __FILE__ ) ) . 'assets/images/loader.gif';
			wp_register_script(
				'bank-transfer-blocks-js',
				plugin_dir_url( BANK_TRANSFER_PAYMENT_GATEWAY_FILE ) . 'assets/js/bank-transfer-blocks.js',
				[ 'wc-blocks-registry', 'wc-settings', 'wp-element' ],
				'1.0.0',
				true
			);

			wp_localize_script(
				'bank-transfer-blocks-js',
				'bank_transfer_params',
				[
					'ajax_url'             => admin_url('admin-ajax.php'),
					'bank_transfer_loader' => $image_url,
					'bank_transfer_nonce'  => wp_create_nonce('bank_transfer_payment'), 
					'checkout_url'         => wc_get_checkout_url(),
					'payment_method'       => 'bank_transfer',
					'plugin_version'       => bank_transfer_get_plugin_version(),
				]
			);
	
		}
	}

	/**
	 * Retrieve merchant credentials associated with an order or active gateway settings.
	 * Never exposes secret keys in returned order objects or logs.
	 *
	 * @param WC_Order|null $order
	 * @return array
	 */
	private function get_order_account_credentials($order = null)
	{
		$settings   = get_option('woocommerce_bank_transfer_settings', []);
		$is_sandbox = isset($settings['sandbox']) && $settings['sandbox'] === 'yes';

		$accounts = get_option('woocommerce_bank_transfer_payment_gateway_accounts', []);
		if (empty($accounts)) {
			$accounts = isset($settings['accounts']) ? maybe_unserialize($settings['accounts']) : [];
		}
		if (is_string($accounts)) {
			$accounts = maybe_unserialize($accounts);
		}
		if (!is_array($accounts)) {
			$accounts = [];
		}
		if (isset($accounts['live_secret_key']) || isset($accounts['sandbox_secret_key'])) {
			$accounts = [$accounts];
		}

		$order_public_key = ($order instanceof WC_Order) ? $order->get_meta('_bank_transfer_public_key') : '';

		// If public key is saved on the order, locate matching account
		if (!empty($order_public_key) && !empty($accounts)) {
			foreach ($accounts as $account) {
				if (!is_array($account)) {
					continue;
				}
				if (!empty($account['sandbox_public_key']) && hash_equals($account['sandbox_public_key'], $order_public_key)) {
					return [
						'public_key' => $account['sandbox_public_key'],
						'secret_key' => $account['sandbox_secret_key'] ?? '',
						'is_sandbox' => 1,
					];
				}
				if (!empty($account['live_public_key']) && hash_equals($account['live_public_key'], $order_public_key)) {
					return [
						'public_key' => $account['live_public_key'],
						'secret_key' => $account['live_secret_key'] ?? '',
						'is_sandbox' => 0,
					];
				}
			}
		}

		// Fallback to first available account
		if (!empty($accounts)) {
			$first_account = reset($accounts);
			if (is_array($first_account)) {
				return [
					'public_key' => $is_sandbox ? ($first_account['sandbox_public_key'] ?? '') : ($first_account['live_public_key'] ?? ''),
					'secret_key' => $is_sandbox ? ($first_account['sandbox_secret_key'] ?? '') : ($first_account['live_secret_key'] ?? ''),
					'is_sandbox' => $is_sandbox ? 1 : 0,
				];
			}
		}

		return [
			'public_key' => '',
			'secret_key' => '',
			'is_sandbox' => $is_sandbox ? 1 : 0,
		];
	}

	/**
	 * BT-15: Rate limiting helper for public AJAX actions
	 */
	public function check_rate_limit($action, $max_requests = 15, $period = 60)
	{
		$ip = sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
		$transient_key = 'bt_rl_' . md5($action . '_' . $ip);
		$count = (int) get_transient($transient_key);

		if ($count >= $max_requests) {
			wp_send_json_error([
				'message'      => 'Too many requests. Please slow down.',
				'stop_polling' => true,
			], 429);
			wp_die();
		}

		set_transient($transient_key, $count + 1, $period);
	}

	private function get_api_url($endpoint)
	{
		$endpoint = '/' . ltrim($endpoint, '/');
		if (strpos($endpoint, '/payment-api/') === 0) {
			return BANK_TRANSFER_BASE_URL . $endpoint;
		}
		if (strpos($endpoint, '/api/') === 0) {
			$endpoint = substr($endpoint, 4);
		}
		$api_base = defined('BANK_TRANSFER_API_BASE_URL') ? BANK_TRANSFER_API_BASE_URL : ($this->base_url . '/payment-api');
		return $api_base . $endpoint;
	}

	/**
	 * Add action links to the plugin page.
	 * @param array $links
	 * @return array
	 */
	public function bank_transfer_plugin_action_links($links)
	{
		$plugin_links = [
			'<a href="' . esc_url(admin_url('admin.php?page=wc-settings&tab=checkout&section=bank_transfer')) . '">' . esc_html__('Settings', 'bank-transfer-payment-gateway') . '</a>',
		];

		return array_merge($plugin_links, $links);
	}

	/**
	 * Add row meta to the plugin page.
	 * @param array $links
	 * @param string $file
	 * @return array
	 */
	public function bank_transfer_plugin_row_meta($links, $file)
	{
		if (plugin_basename(BANK_TRANSFER_PAYMENT_GATEWAY_FILE) === $file) {
			$row_meta = [
				'docs'    => '<a href="' . esc_url(apply_filters('bank_transfer_docs_url', 'https://qa.rt.app/docs')) . '" target="_blank">' . esc_html__('Documentation', 'bank-transfer-payment-gateway') . '</a>',
				'support' => '<a href="' . esc_url(apply_filters('bank_transfer_support_url', 'https://qa.rt.app/contact-us')) . '" target="_blank">' . esc_html__('Support', 'bank-transfer-payment-gateway') . '</a>',
			];

			$links = array_merge($links, $row_meta);
		}

		return $links;
	}

	/**
	 * Check the environment and display notices if necessary.
	 */
	public function bank_transfer_handle_environment_check()
	{
		$environment_warning = bank_transfer_check_system_requirements();
		if ($environment_warning) {
			// Sanitize the environment warning before displaying it
			$this->admin_notices->bank_transfer_add_notice('error', 'error', sanitize_text_field($environment_warning));
		}
	}

	/**
	 * Handle the AJAX request for checking payment status.
	 * @param $request
	 */
	public function bank_transfer_handle_check_payment_status_request($request)
	{
		check_ajax_referer('bank_transfer_payment', 'security');
		$this->check_rate_limit('status_check', 30, 60);

		// Sanitize and validate the order ID from $_POST
		$order_id = isset($_POST['order_id']) ? intval(sanitize_text_field(wp_unslash($_POST['order_id']))) : null;
		if (!$order_id || $order_id <= 0) {
			wp_send_json_error(array('message' => esc_html__('Invalid order ID', 'bank-transfer-payment-gateway')), 400);
		}

		// Call the function to check payment status with the validated order ID
		return $this->bank_transfer_check_payment_status($order_id);
	}

	/**
	 * Check the payment status for an order.
	 * @param int $order_id
	 * @return WP_REST_Response
	 */
	public function bank_transfer_check_payment_status($order_id)
	{
		$order = wc_get_order($order_id);
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$order_key = isset($_POST['order_key'])
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			? sanitize_text_field(wp_unslash($_POST['order_key']))
			: '';
		$log_prefix = "[Order #{$order_id}]";

		// BT-06: Strict authorization check - order must exist, belong to bank_transfer, and key must match via hash_equals
		if (
			!$order ||
			$order->get_payment_method() !== 'bank_transfer' ||
			empty($order_key) ||
			!hash_equals($order->get_order_key(), $order_key)
		) {
			Bank_Transfer_Payment_Gateway_Logger::info(
				$log_prefix . ' CheckStatus | Security check failed (invalid key or non-bank-transfer order)'
			);

			wp_send_json_error([
				'message'      => 'Security check failed.',
				'stop_polling' => true
			], 403);
			wp_die();
		}

		// -------------------------
		// API CALL
		// -------------------------
		$payment_token = $this->bank_transfer_resolve_order_pay_id($order);
		$creds         = $this->get_order_account_credentials($order);
		$public_key    = $creds['public_key'];
		$secret_key    = $creds['secret_key'];

		if (empty($public_key) || empty($secret_key)) {
			Bank_Transfer_Payment_Gateway_Logger::warning(
				$log_prefix . ' CheckStatus | Missing API credentials for merchant account'
			);

			wp_send_json_error([
				'message'      => 'Missing merchant account credentials.',
				'stop_polling' => true,
			], 400);

			wp_die();
		}

		$lookup   = $this->bank_transfer_fetch_remote_payment_status($order, $payment_token, $creds);
		$response = $lookup['response'];

		if (is_wp_error($response)) {
			Bank_Transfer_Payment_Gateway_Logger::warning(
				$log_prefix . ' CheckStatus | Network error: ' . $response->get_error_message()
			);

			wp_send_json_error([
				'message'      => 'API connection failed.',
				'stop_polling' => false,
			], 500);

			wp_die();
		}

		$http_code = (int) wp_remote_retrieve_response_code($response);

		if ($http_code === 401) {
			Bank_Transfer_Payment_Gateway_Logger::warning(
				$log_prefix . ' CheckStatus | API authentication error (HTTP 401)'
			);

			wp_send_json_error([
				'message'      => 'API authentication failed. Please verify merchant credentials.',
				'stop_polling' => true,
			], 401);

			wp_die();
		}

		if ($http_code === 429) {
			Bank_Transfer_Payment_Gateway_Logger::warning(
				$log_prefix . ' CheckStatus | Upstream rate limit reached (HTTP 429)'
			);

			wp_send_json_error([
				'message'      => 'Rate limit exceeded. Please wait a moment.',
				'stop_polling' => false,
			], 429);

			wp_die();
		}

		if ($http_code < 200 || $http_code >= 300) {
			Bank_Transfer_Payment_Gateway_Logger::warning(
				$log_prefix . " CheckStatus | Upstream returned non-2xx status (HTTP {$http_code})"
			);

			wp_send_json_error([
				'message'      => 'Unable to check transaction status at this time.',
				'stop_polling' => false,
			], $http_code >= 400 && $http_code < 600 ? $http_code : 500);

			wp_die();
		}

		$response_data = is_array($lookup['data']) ? $lookup['data'] : json_decode(wp_remote_retrieve_body($response), true);

		if (!is_array($response_data)) {
			Bank_Transfer_Payment_Gateway_Logger::warning(
				$log_prefix . ' CheckStatus | Invalid API response'
			);

			wp_send_json_error([
				'message'      => 'Invalid API response.',
				'stop_polling' => false,
			], 500);

			wp_die();
		}

		$payment_status = $this->bank_transfer_normalize_remote_status($response_data);

		// BK-05: Strict validation before accepting remote success
		if (in_array($payment_status, ['success', 'paid', 'completed', 'settled', 'succeeded'], true)) {
			if (!$this->bank_transfer_validate_payment_verification_data($order, $response_data, $payment_token)) {
				Bank_Transfer_Payment_Gateway_Logger::warning($log_prefix . ' CheckStatus | Validation failed: Amount, currency, or pay_id mismatch');
				$payment_status = null;
			}
		}

		// -------------------------
		// ENGINE CALL
		// -------------------------
		if ($payment_status) {
			Bank_Transfer_Payment_Gateway_Logger::info(
				$log_prefix . " CheckStatus | Engine trigger ({$payment_status})"
			);

			$result = BANK_TRANSFER_PAYMENT_ENGINE::handle_event(
				$order_id,
				'redirect_check',
				[
					'status'        => $payment_status,
					'payment_token' => $payment_token,
				]
			);

			Bank_Transfer_Payment_Gateway_Logger::info(
				$log_prefix . " CheckStatus | Engine result: " . wp_json_encode($result)
			);
		}

		// -------------------------
		// REFRESH ORDER
		// -------------------------
		$order = wc_get_order($order_id);

		$wc_status = $order->get_status();

		$state = BANK_TRANSFER_PAYMENT_ENGINE::resolve_final_state(
			$order,
			$payment_status
		);

		/**
		 * SUCCESS ALWAYS WINS
		 */
		if ($order->has_status(['processing', 'completed'])) {
			$state = 'success';
		}

		// -------------------------
		// REDIRECT
		// -------------------------
		$redirect = null;

		if ($order->has_status(['processing', 'completed'])) {
			$redirect = $order->get_checkout_order_received_url();
		} elseif (in_array($state, ['failed', 'cancelled'], true)) {
			$redirect = wc_get_checkout_url();
		}

		// -------------------------
		// RESPONSE
		// -------------------------
		wp_send_json_success([
			'status'        => $state,
			'payment_status'=> $payment_status,
			'order_status'  => $wc_status,
			'redirect_url'  => $redirect,
		]);

		wp_die();
	}

	private function bank_transfer_log($message, $context = [])
	{
		if (function_exists('wc_get_logger')) {
			Bank_Transfer_Payment_Gateway_Logger::info(
				$message,
				array_merge([
					'source' => 'bank-transfer-payment-gateway'
				], $context)
			);
		}
	}

	public function handle_popup_close()
	{
		check_ajax_referer('bank_transfer_payment', 'security');
		$this->check_rate_limit('popup_close', 30, 60);

		$order_id = isset($_POST['order_id'])
			? intval(sanitize_text_field(wp_unslash($_POST['order_id'])))
			: 0;

		$order_key = isset($_POST['order_key'])
			? sanitize_text_field(wp_unslash($_POST['order_key']))
			: '';

		$log_prefix = "[Order #{$order_id}]";

		// -------------------------
		// ORDER & SECURITY CHECK (BT-06)
		// -------------------------
		$order = ($order_id > 0) ? wc_get_order($order_id) : false;

		if (
			!$order ||
			$order->get_payment_method() !== 'bank_transfer' ||
			empty($order_key) ||
			!hash_equals($order->get_order_key(), $order_key)
		) {
			Bank_Transfer_Payment_Gateway_Logger::info(
				$log_prefix . ' PopupClose | Security check failed (invalid key or non-bank-transfer order)'
			);

			wp_send_json_error([
				'reload'       => false,
				'stop_polling' => true,
				'message'      => 'Security verification failed.'
			], 403);

			wp_die();
		}

		// -------------------------
		// API CALL
		// -------------------------
		$payment_token = $this->bank_transfer_resolve_order_pay_id($order);
		$creds         = $this->get_order_account_credentials($order);
		$public_key    = $creds['public_key'];
		$secret_key    = $creds['secret_key'];

		if (empty($public_key) || empty($secret_key)) {
			Bank_Transfer_Payment_Gateway_Logger::warning(
				$log_prefix . ' PopupClose | Missing API credentials for merchant account'
			);

			wp_send_json_error([
				'reload'       => false,
				'stop_polling' => true,
				'message'      => 'Missing merchant account credentials.',
			], 400);

			wp_die();
		}

		$lookup   = $this->bank_transfer_fetch_remote_payment_status($order, $payment_token, $creds);
		$response = $lookup['response'];

		if (is_wp_error($response)) {
			Bank_Transfer_Payment_Gateway_Logger::warning(
				$log_prefix . ' PopupClose | Network error: ' . $response->get_error_message()
			);

			wp_send_json_error([
				'reload'       => false,
				'stop_polling' => false,
				'message'      => 'API connection failed.',
			], 500);

			wp_die();
		}

		$http_code = (int) wp_remote_retrieve_response_code($response);

		if ($http_code === 401) {
			Bank_Transfer_Payment_Gateway_Logger::warning(
				$log_prefix . ' PopupClose | API authentication error (HTTP 401)'
			);

			wp_send_json_error([
				'reload'       => false,
				'stop_polling' => true,
				'message'      => 'Payment authentication error. Please verify merchant credentials.',
			], 401);

			wp_die();
		}

		if ($http_code === 429) {
			Bank_Transfer_Payment_Gateway_Logger::warning(
				$log_prefix . ' PopupClose | Upstream rate limit reached (HTTP 429)'
			);

			wp_send_json_error([
				'reload'       => false,
				'stop_polling' => false,
				'message'      => 'Rate limit exceeded. Please wait a moment.',
			], 429);

			wp_die();
		}

		if ($http_code < 200 || $http_code >= 300) {
			Bank_Transfer_Payment_Gateway_Logger::warning(
				$log_prefix . " PopupClose | Upstream returned non-2xx status (HTTP {$http_code})"
			);

			wp_send_json_error([
				'reload'       => false,
				'stop_polling' => false,
				'message'      => 'Unable to verify payment status at this time.',
			], $http_code >= 400 && $http_code < 600 ? $http_code : 500);

			wp_die();
		}

		$response_data = is_array($lookup['data']) ? $lookup['data'] : json_decode(wp_remote_retrieve_body($response), true);

		if (!is_array($response_data)) {
			Bank_Transfer_Payment_Gateway_Logger::warning(
				$log_prefix . ' PopupClose | Invalid API response'
			);

			wp_send_json_error([
				'reload'       => false,
				'stop_polling' => false,
				'message'      => 'Invalid API response.',
			], 500);

			wp_die();
		}

		$payment_status = $this->bank_transfer_normalize_remote_status($response_data);

		// BK-05: Strict validation before accepting remote success
		if (in_array($payment_status, ['success', 'paid', 'completed', 'settled', 'succeeded'], true)) {
			if (!$this->bank_transfer_validate_payment_verification_data($order, $response_data, $payment_token)) {
				Bank_Transfer_Payment_Gateway_Logger::warning($log_prefix . ' PopupClose | Validation failed: Amount, currency, or pay_id mismatch');
				$payment_status = null;
			}
		}

		// -------------------------
		// NO STATUS
		// -------------------------
		if (!$payment_status) {
			wp_send_json([
				'success' => false,
				'message' => 'Payment status is currently unavailable.',
				'data' => [
					'payment_status' => 'pending',
					'order_status'   => $order->get_status(),
					'state'          => 'pending',
					'redirect'       => null,
					'order_id'       => $order_id,
					'stop_polling'   => false,
				]
			]);

			wp_die();
		}

		// -------------------------
		// ENGINE CALL
		// -------------------------
		$result = BANK_TRANSFER_PAYMENT_ENGINE::handle_event(
			$order_id,
			'popup_close',
			[
				'status'        => $payment_status,
				'payment_token' => $payment_token,
			]
		);

		Bank_Transfer_Payment_Gateway_Logger::info(
			$log_prefix . " PopupClose | Engine result: " . wp_json_encode($result)
		);

		// -------------------------
		// ALWAYS RELOAD ORDER AFTER ENGINE
		// -------------------------
		$order = wc_get_order($order_id);

		// 🔥 PRIMARY STATE = ENGINE STORED STATE ONLY
		$state = BANK_TRANSFER_PAYMENT_ENGINE::resolve_final_state($order, $payment_status);

		// -------------------------
		// HARD OVERRIDE SAFETY (ONLY ONE SOURCE)
		// -------------------------
		if ($order->get_meta('_bank_transfer_state') === 'success' || $order->has_status(['processing', 'completed'])) {
			$state = 'success';
		}

		// -------------------------
		// FINAL SUCCESS CHECK
		// -------------------------
		$is_success = ($state === 'success');

		// -------------------------
		// MESSAGE
		// -------------------------
		$message = match ($state) {
			'success' =>
				'Your payment was completed successfully.',

			'failed' =>
				'Payment failed. Please try again or use another method.',

			'cancelled' =>
				'You cancelled the payment.',

			'processing' =>
				'Payment is being processed.',

			default =>
				'We couldn’t confirm your payment status yet. If needed, you can try placing the order again after checking your order status.'
		};

		// -------------------------
		// REDIRECT
		// -------------------------
		$redirect = null;

		// 🔥 ONLY ENGINE STATE DECIDES REDIRECT
		if ($state === 'success') {
			$redirect = $order->get_checkout_order_received_url();
		} elseif (in_array($state, ['failed', 'cancelled', 'expired'], true)) {
			$redirect = wc_get_checkout_url();
		} else {
			$redirect = null; // processing → no redirect
		}

		$stop_polling = in_array($state, ['success', 'failed', 'cancelled', 'expired'], true);

		// -------------------------
		// RESPONSE
		// -------------------------
		wp_send_json([
			'success' => $is_success,
			'message' => $message,
			'data' => [
				'payment_status' => $payment_status,
				'order_status'   => $order->get_status(),
				'state'          => $state,
				'redirect'       => $redirect,
				'order_id'       => $order_id,
				'stop_polling'   => $stop_polling,
			]
		]);

		wp_die();
	}

	/**
     * Add custom cron schedules.
     */
	public function bank_transfer_add_cron_interval($schedules)
	{
		$schedules['every_two_hours'] = array(
			'interval' => 2 * 60 * 60, // 2 hours in seconds = 7200
			'display'  => __('Every Two Hours', 'bank-transfer-payment-gateway')
		);
		return $schedules;
	}

	function activate_cron_job()
	{
		Bank_Transfer_Payment_Gateway_Logger::info('Automatic payment status checks have been enabled.', ['source' => 'bank-transfer-payment-gateway']);

		// Clear existing scheduled event if it exists
		$timestamp = wp_next_scheduled('bank_transfer_cron_event');
		if ($timestamp) {
			wp_unschedule_event($timestamp, 'bank_transfer_cron_event');
		}

		// Schedule with new interval
		wp_schedule_event(time(), 'every_two_hours', 'bank_transfer_cron_event');
	}

	function deactivate_cron_job()
	{
		Bank_Transfer_Payment_Gateway_Logger::info('Automatic payment status checks have been disabled.', ['source' => 'bank-transfer-payment-gateway']);
		wp_clear_scheduled_hook('bank_transfer_cron_event');
	}


	public function handle_cron_event()
	{
		$logger_context = ['source' => 'bank-transfer-payment-gateway'];

		$accounts = get_option('woocommerce_bank_transfer_payment_gateway_accounts');
		if (is_string($accounts)) {
			$unserialized = maybe_unserialize($accounts);
			$accounts = is_array($unserialized) ? $unserialized : [];
		}

		if (!$accounts || !is_array($accounts)) {
			Bank_Transfer_Payment_Gateway_Logger::warning('No payment accounts found or the account format is invalid. Sync aborted.', $logger_context);
			return [];
		}

		$accountsData = [];
		$first_public_key = '';
		$first_secret_key = '';

		foreach ($accounts as &$account) {
			$isSandboxEnabled = isset($account['has_sandbox']) && $account['has_sandbox'] === 'on';

			// Prepare both live and sandbox entries
			if (!empty($account['live_public_key'])) {
				$accountsData[] = [
					'account_name' => $account['title'] ?? '',
					'public_key'   => $account['live_public_key'],
					'secret_key'   => $account['live_secret_key'] ?? '',
					'mode'         => 'live',
				];
				if (empty($first_public_key)) {
					$first_public_key = $account['live_public_key'];
					$first_secret_key = $account['live_secret_key'] ?? '';
				}
			}

			if ($isSandboxEnabled) {
				if (!empty($account['sandbox_public_key']) && !empty($account['sandbox_secret_key'])) {
					$accountsData[] = [
						'account_name' => $account['title'] ?? '',
						'public_key'   => $account['sandbox_public_key'],
						'secret_key'   => $account['sandbox_secret_key'] ?? '',
						'mode'         => 'sandbox',
					];
					if (empty($first_public_key)) {
						$first_public_key = $account['sandbox_public_key'];
						$first_secret_key = $account['sandbox_secret_key'] ?? '';
					}
				} else {
					if (($account['sandbox_status'] ?? '') !== 'invalid') {
						$account['sandbox_status'] = 'invalid';
						$updated = true;
					}
				}
			} else {
				if (($account['sandbox_status'] ?? '') !== 'inactive') {
					$account['sandbox_status'] = 'inactive';
					$updated = true;
				}
			}
		}

		if (empty($accountsData)) {
			Bank_Transfer_Payment_Gateway_Logger::warning('No valid credentials found in any payment account. Sync skipped.', $logger_context);
			return [];
		}

		$url = esc_url($this->get_api_url('/sync-account-status'));
		$body = wp_json_encode(['accounts' => $accountsData]);
		$headers = [
			'Content-Type'  => 'application/json',
			'Authorization' => 'Bearer ' . sanitize_text_field($first_public_key),
		];

		if (!empty($first_secret_key)) {
			$timestamp = time();
			$signature = hash_hmac('sha256', $timestamp . '.' . $body, $first_secret_key);
			$headers['X-Signature'] = $signature;
			$headers['X-Timestamp'] = (string) $timestamp;
		}

		$response = wp_remote_post($url, [
			'headers'   => $headers,
			'body'      => $body,
			'timeout'   => 15,
			'sslverify' => true,
		]);

		if (is_wp_error($response)) {
			Bank_Transfer_Payment_Gateway_Logger::error('Unable to connect to the sync service. Please check the server connection or endpoint.', $logger_context);
			$this->sync_pending_orders();
			return [];
		}

		$response_code = wp_remote_retrieve_response_code($response);
		if ($response_code < 200 || $response_code >= 300) {
			Bank_Transfer_Payment_Gateway_Logger::warning(
				'Sync API returned a non-2xx HTTP status. Sync aborted.',
				array_merge($logger_context, [
					'context' => [
						'http_status' => $response_code,
						'url'         => $url,
					],
				])
			);
			$this->sync_pending_orders();
			return [];
		}

		$response_body = wp_remote_retrieve_body($response);
		$response_data = json_decode($response_body, true);

		$updated = false;
		$statusSummary = [];

		if (!empty($response_data['data'])) {
			foreach ($response_data['data'] as $statusData) {
				if (
					isset($statusData['mode'], $statusData['public_key'], $statusData['status']) &&
					!empty($statusData['status']) &&
					(empty($statusData['message']) || stripos($statusData['message'], 'missing required fields') === false)
				) {
					foreach ($accounts as &$account) {
						if (
							$statusData['mode'] === 'live' &&
							$account['live_public_key'] === $statusData['public_key']
						) {
							$account['live_status'] = $statusData['status'];
							$updated = true;
							$statusSummary[] = [
								'title'  => $account['title'] ?? 'N/A',
								'mode'   => $statusData['mode'],
								'status' => $statusData['status'],
							];
						}

						if (
							$statusData['mode'] === 'sandbox' &&
							$account['sandbox_public_key'] === $statusData['public_key']
						) {
							$account['sandbox_status'] = $statusData['status'];
							$updated = true;
							$statusSummary[] = [
								'title'  => $account['title'] ?? 'N/A',
								'mode'   => $statusData['mode'],
								'status' => $statusData['status'],
							];
						}
					}
				}
			}
		}

		if (!empty($statusSummary)) {
			if ($updated) {
				update_option('woocommerce_bank_transfer_payment_gateway_accounts', $accounts);

				Bank_Transfer_Payment_Gateway_Logger::info('Payment account statuses were successfully updated after syncing.', [
					'source'  => 'bank-transfer-payment-gateway',
					'context' => ['updated_accounts' => $statusSummary],
				]);
			} else {
				Bank_Transfer_Payment_Gateway_Logger::info('Payment accounts were checked, but no updates were necessary.', [
					'source'  => 'bank-transfer-payment-gateway',
					'context' => ['checked_accounts' => $statusSummary],
				]);
			}
		} else {
			Bank_Transfer_Payment_Gateway_Logger::info('Sync completed. No account status data was returned from the server.', $logger_context);
		}

		// Account statuses are refreshed; now reconcile any order still waiting for payment.
		$this->sync_pending_orders();

		$this->bank_transfer_send_plugin_status(1, 1);

		return $statusSummary;
	}


	function bank_transfer_manual_sync_callback()
	{
		$logger_context = ['source' => 'bank-transfer-payment-gateway'];
		// Verify nonce first
		if (!check_ajax_referer('bank_transfer_sync_nonce', 'nonce', false)) {
			Bank_Transfer_Payment_Gateway_Logger::error('Security validation failed during manual sync.', $logger_context);
			wp_send_json_error([
				'message' => __('Security check failed. Please refresh the page and try again.', 'bank-transfer-payment-gateway')
			], 400);
			wp_die();
		}

		// Check user capabilities
		if (!current_user_can('manage_woocommerce')) {
		Bank_Transfer_Payment_Gateway_Logger::error('Unauthorized manual sync attempt by user ID: ' . get_current_user_id(), $logger_context);
			wp_send_json_error([
				'message' => __('You do not have permission to perform this action.', 'bank-transfer-payment-gateway')
			], 403);
			wp_die();
		}

		Bank_Transfer_Payment_Gateway_Logger::info("Payment accounts sync initiated", $logger_context);

		try {
			ob_start();

			$statusSummary = $this->handle_cron_event();
			$output = ob_get_clean();

			if (!empty($output)) {
				Bank_Transfer_Payment_Gateway_Logger::warning('Unexpected output generated during sync: ' . $output, $logger_context);
			}

			Bank_Transfer_Payment_Gateway_Logger::info('Payment accounts sync completed successfully.', $logger_context);

			wp_send_json_success([
				'message'  => __('Payment accounts synchronized successfully.', 'bank-transfer-payment-gateway'),
				'timestamp' => current_time('mysql'),
				'statuses' => $statusSummary
			]);
		} catch (Exception $e) {
			Bank_Transfer_Payment_Gateway_Logger::error('Payment accounts sync failed: ' . $e->getMessage(), $logger_context);
			wp_send_json_error([
				'message' => __('Sync failed: ', 'bank-transfer-payment-gateway') . $e->getMessage(),
				'code'    => $e->getCode()
			], 500);
		}

		wp_die(); // Always include this
	}

	public function bank_transfer_send_plugin_status($plugin_status, $gateway_loaded)
	{
		$accounts = get_option('woocommerce_bank_transfer_payment_gateway_accounts', []);

		if (is_string($accounts)) {
			$unserialized = maybe_unserialize($accounts);
			$accounts = is_array($unserialized) ? $unserialized : [];
		}

		if (empty($accounts) || !is_array($accounts)) {
			return;
		}

		// Find first available public key
		$public_key = '';

		foreach ($accounts as $account) {
			if (!empty($account['live_public_key'])) {
				$public_key = $account['live_public_key'];
				break;
			}

			if (!empty($account['sandbox_public_key'])) {
				$public_key = $account['sandbox_public_key'];
				break;
			}
		}

		if (empty($public_key)) {
			return;
		}

		$sanitized_accounts = array_map(function($acc) {
			unset($acc['live_secret_key'], $acc['sandbox_secret_key']);
			return $acc;
		}, (array) $accounts);

		$body = [
			'valid_accounts'         => $sanitized_accounts,
			'plugin_status'          => (int) $plugin_status,
			'gateway_loaded'         => (int) $gateway_loaded,
			'plugin_version'         => bank_transfer_get_plugin_version(),
			'wordpress_version'      => get_bloginfo('version'),
			'woocommerce_version'    => get_option('woocommerce_version') ?: '',
			'woocommerce_db_version' => get_option('woocommerce_db_version'),
			'group_id'               => get_option('bank_transfer_group_id'),
			'domain_name'            => wp_parse_url(home_url(), PHP_URL_HOST),
		];

		$response = wp_remote_post(
			$this->get_api_url('/plugin/check/plugin'),
			[
				'method'    => 'POST',
				'timeout'   => 30,
				'sslverify' => true,
				'headers'   => [
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . sanitize_text_field($public_key),
				],
				'body'      => wp_json_encode($body),
			]
		);

		if (is_wp_error($response)) {
			Bank_Transfer_Payment_Gateway_Logger::error(
				'Plugin status API call failed.',
				[
					'source'  => 'bank-transfer-payment-gateway',
					'context' => [
						'error' => $response->get_error_message(),
					],
				]
			);
			return;
		}

		Bank_Transfer_Payment_Gateway_Logger::info(
			'Plugin status updated successfully.',
			[
				'source'  => 'bank-transfer-payment-gateway',
				'context' => [
					'plugin_status'  => $plugin_status,
					'gateway_loaded' => $gateway_loaded,
					'response_code'  => wp_remote_retrieve_response_code($response),
				],
			]
		);
	}

	/* =========================================================
	 * PAYMENT ID RESOLUTION
	 * ========================================================= */

	/**
	 * Resolve the Rethink Pay payment id (pay_id / UUID) for an order.
	 *
	 * Order meta is the primary source. When it is empty (older orders, a
	 * create-link response without pay_id, or meta lost on a retry) fall back to
	 * the active pay id and to the order_payment_link table, including the id
	 * embedded in the stored payment link. A resolved id is written back to the
	 * order so webhooks and later checks find it immediately.
	 *
	 * @param WC_Order $order
	 * @return string Empty string when nothing could be resolved.
	 */
	public function bank_transfer_resolve_order_pay_id($order)
	{
		if (!$order instanceof WC_Order) {
			return '';
		}

		$pay_id = sanitize_text_field((string) $order->get_meta('_bank_transfer_pay_id'));
		if ($pay_id !== '') {
			return $pay_id;
		}

		$pay_id = sanitize_text_field((string) $order->get_meta('_bank_transfer_active_pay_id'));

		if ($pay_id === '') {
			$link_row = $this->bank_transfer_get_order_payment_link_row($order->get_id());
			if (is_array($link_row)) {
				if (!empty($link_row['uuid'])) {
					$pay_id = sanitize_text_field((string) $link_row['uuid']);
				} elseif (!empty($link_row['payment_link'])) {
					$pay_id = (string) $this->bank_transfer_extract_pay_id_from_url((string) $link_row['payment_link']);
				}
			}
		}

		if ($pay_id !== '') {
			$order->update_meta_data('_bank_transfer_pay_id', $pay_id);
			$order->save();

			Bank_Transfer_Payment_Gateway_Logger::info(
				'[Order #' . $order->get_id() . '] Recovered missing payment id from stored payment link data.',
				['source' => 'bank-transfer-payment-gateway', 'pay_id' => $pay_id]
			);
		}

		return $pay_id;
	}

	/**
	 * Every payment id this order is known under (meta, active id, link table).
	 *
	 * @param WC_Order $order
	 * @return string[]
	 */
	public function bank_transfer_known_order_pay_ids($order)
	{
		if (!$order instanceof WC_Order) {
			return [];
		}

		$ids = [
			sanitize_text_field((string) $order->get_meta('_bank_transfer_pay_id')),
			sanitize_text_field((string) $order->get_meta('_bank_transfer_active_pay_id')),
		];

		// BK-04: Include full payment ID history for this order
		$history = $order->get_meta('_bank_transfer_pay_id_history');
		if (is_array($history)) {
			foreach ($history as $h_id) {
				if (!empty($h_id)) {
					$ids[] = sanitize_text_field((string) $h_id);
				}
			}
		}

		$link_row = $this->bank_transfer_get_order_payment_link_row($order->get_id());
		if (is_array($link_row)) {
			$ids[] = sanitize_text_field((string) ($link_row['uuid'] ?? ''));
			$ids[] = (string) $this->bank_transfer_extract_pay_id_from_url((string) ($link_row['payment_link'] ?? ''));
		}

		return array_values(array_unique(array_filter($ids, static function ($id) {
			return is_string($id) && $id !== '';
		})));
	}

	/**
	 * BK-05: Strict unified verification for remote payment status payloads.
	 * Enforces known pay_id, exact amount (<= 0.01 tolerance), and matching currency.
	 *
	 * @param WC_Order $order
	 * @param array $payload
	 * @param string $pay_id
	 * @return bool
	 */
	public function bank_transfer_validate_payment_verification_data($order, $payload, $pay_id = '')
	{
		if (!$order instanceof WC_Order || !is_array($payload)) {
			return false;
		}

		$order_id = $order->get_id();

		// 1. Order ID match if present in payload
		$raw_order_id = $payload['order_id'] ?? ($payload['meta_data']['order_id'] ?? null);
		if ($raw_order_id !== null && $raw_order_id !== '' && is_numeric($raw_order_id) && (int) $raw_order_id !== (int) $order_id) {
			Bank_Transfer_Payment_Gateway_Logger::warning("[Order #{$order_id}] Verification failed: Order ID mismatch", [
				'expected' => $order_id,
				'received' => $raw_order_id,
			]);
			return false;
		}

		// 2. Pay ID match
		$incoming_pay_id = !empty($pay_id) ? $pay_id : ($payload['pay_id'] ?? ($payload['uuid'] ?? ($payload['payment_token'] ?? '')));
		if (!empty($incoming_pay_id)) {
			$known_ids = $this->bank_transfer_known_order_pay_ids($order);
			$matched   = false;
			foreach ($known_ids as $kid) {
				if (hash_equals((string) $kid, (string) $incoming_pay_id)) {
					$matched = true;
					break;
				}
			}
			if (!$matched && !empty($known_ids)) {
				Bank_Transfer_Payment_Gateway_Logger::warning("[Order #{$order_id}] Verification failed: Pay ID mismatch", [
					'known'    => $known_ids,
					'received' => $incoming_pay_id,
				]);
				return false;
			}
		}

		// 3. Mandatory amount check (tolerance <= 0.01)
		$raw_amount = $payload['amount'] ?? ($payload['total_amount'] ?? null);
		if ($raw_amount === null || !is_numeric($raw_amount)) {
			Bank_Transfer_Payment_Gateway_Logger::warning("[Order #{$order_id}] Verification failed: Missing or non-numeric amount");
			return false;
		}
		$api_amount   = (float) $raw_amount;
		$order_amount = (float) $order->get_total();
		if (abs($api_amount - $order_amount) > 0.01) {
			Bank_Transfer_Payment_Gateway_Logger::warning("[Order #{$order_id}] Verification failed: Amount mismatch", [
				'order_amount' => $order_amount,
				'api_amount'   => $api_amount,
			]);
			return false;
		}

		// 4. Mandatory currency check
		$raw_currency = $payload['currency'] ?? ($payload['curr_code'] ?? null);
		if (empty($raw_currency) || !is_string($raw_currency)) {
			Bank_Transfer_Payment_Gateway_Logger::warning("[Order #{$order_id}] Verification failed: Missing currency");
			return false;
		}
		$api_currency   = strtoupper(trim(sanitize_text_field($raw_currency)));
		$order_currency = strtoupper(trim((string) $order->get_currency()));
		if ($api_currency !== $order_currency) {
			Bank_Transfer_Payment_Gateway_Logger::warning("[Order #{$order_id}] Verification failed: Currency mismatch", [
				'order_currency' => $order_currency,
				'api_currency'   => $api_currency,
			]);
			return false;
		}

		return true;
	}

	/**
	 * Read the order_payment_link row written by process_payment().
	 *
	 * @param int $order_id
	 * @return array|null
	 */
	public function bank_transfer_get_order_payment_link_row($order_id)
	{
		global $wpdb;

		$order_id = absint($order_id);
		if ($order_id <= 0) {
			return null;
		}

		$cache_key   = 'bank_transfer_payment_row_' . $order_id;
		$cache_group = 'bank_transfer_payment_gateway';
		$cached      = wp_cache_get($cache_key, $cache_group);
		if (is_array($cached)) {
			return $cached;
		}

		$table_name = esc_sql($wpdb->prefix . 'rt_bank_transfer_payment_link');

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$table_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table_name));
		if ($table_exists !== $table_name) {
			$table_name = esc_sql($wpdb->prefix . 'order_payment_link');
			$table_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table_name));
			if ($table_exists !== $table_name) {
				return null;
			}
		}

		$row = $wpdb->get_row(
			$wpdb->prepare("SELECT * FROM {$table_name} WHERE order_id = %d ORDER BY id DESC LIMIT 1", $order_id),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if (is_array($row)) {
			wp_cache_set($cache_key, $row, $cache_group, 60);
			return $row;
		}

		return null;
	}

	/**
	 * Extract a pay id from a Rethink Pay payment link
	 * (https://host/p/{id}, https://host/p/t/{id} or ?uuid= / ?pay_id=).
	 *
	 * @param string $url
	 * @return string|null
	 */
	public function bank_transfer_extract_pay_id_from_url($url)
	{
		if (empty($url) || !is_string($url)) {
			return null;
		}
		if (preg_match('#/p/(?:t/)?([a-zA-Z0-9_\-]+)#', $url, $m)) {
			return $m[1];
		}
		if (preg_match('#[?&](?:uuid|pay_id)=([a-zA-Z0-9_\-]+)#', $url, $m)) {
			return $m[1];
		}
		return null;
	}

	/* =========================================================
	 * REMOTE PAYMENT STATUS LOOKUP
	 * ========================================================= */

	/**
	 * Ask the Rethink Pay backend for the current status of a payment.
	 *
	 * 1. PRIMARY  GET  /payment-requests/public/{pay_id}/status
	 *             Authorization: Bearer {public_key}
	 *             X-Signature = HMAC-SHA256(secret_key, X-Timestamp . '.' . pay_id)
	 * 2. FALLBACK POST /update-txn-status (order_id + payment_token)
	 *             X-Signature = HMAC-SHA256(secret_key, X-Timestamp . '.' . raw_body)
	 *
	 * The secret key is only ever used to sign; it is never sent in a body.
	 *
	 * @param WC_Order $order
	 * @param string   $pay_id
	 * @param array    $creds  ['public_key' => , 'secret_key' => , 'is_sandbox' => ]
	 * @return array{response: array|WP_Error|null, data: array|null, http_code: int, endpoint: string}
	 *               'data' is the decoded payload when a usable status came back, else null.
	 *               'response' / 'http_code' describe the response the caller should report.
	 */
	public function bank_transfer_fetch_remote_payment_status($order, $pay_id, $creds)
	{
		$public_key = (string) ($creds['public_key'] ?? '');
		$secret_key = (string) ($creds['secret_key'] ?? '');
		$is_sandbox = (int) ($creds['is_sandbox'] ?? 0);
		$order_id   = $order instanceof WC_Order ? $order->get_id() : 0;
		$pay_id     = sanitize_text_field((string) $pay_id);

		$result = ['response' => null, 'data' => null, 'http_code' => 0, 'endpoint' => ''];

		if ($public_key === '' || $secret_key === '') {
			$result['response'] = new WP_Error('bank_transfer_missing_credentials', 'Missing merchant account credentials.');
			return $result;
		}

		// -------------------------
		// 1. PRIMARY: signed payment-request status
		// -------------------------
		if ($pay_id !== '') {
			$timestamp = (string) time();
			$response  = wp_remote_get(
				esc_url_raw($this->get_api_url('/payment-requests/public/' . rawurlencode($pay_id) . '/status')),
				[
					'headers'   => [
						'Accept'        => 'application/json',
						'Authorization' => 'Bearer ' . sanitize_text_field($public_key),
						'X-Timestamp'   => $timestamp,
						'X-Signature'   => hash_hmac('sha256', $timestamp . '.' . $pay_id, $secret_key),
					],
					'timeout'   => 10,
					'sslverify' => true,
				]
			);

			$result['response']  = $response;
			$result['endpoint']  = 'payment-requests/status';
			$result['http_code'] = is_wp_error($response) ? 0 : (int) wp_remote_retrieve_response_code($response);

			if (!is_wp_error($response) && $result['http_code'] >= 200 && $result['http_code'] < 300) {
				$decoded = $this->bank_transfer_unwrap_status_payload(json_decode(wp_remote_retrieve_body($response), true));
				if (is_array($decoded) && $this->bank_transfer_payload_has_status($decoded)) {
					$result['data'] = $decoded;
					return $result;
				}
			}

			Bank_Transfer_Payment_Gateway_Logger::info(
				"[Order #{$order_id}] Status lookup | payment-requests/status gave no usable status (" .
				(is_wp_error($response) ? $response->get_error_message() : 'HTTP ' . $result['http_code']) .
				'); trying update-txn-status',
				['source' => 'bank-transfer-payment-gateway']
			);
		}

		// -------------------------
		// 2. FALLBACK: update-txn-status
		// -------------------------
		if ($pay_id === '') {
			Bank_Transfer_Payment_Gateway_Logger::info(
				"[Order #{$order_id}] Fallback update-txn-status skipped: pay_id is required.",
				['source' => 'bank-transfer-payment-gateway']
			);
			return $result;
		}

		$body      = wp_json_encode([
			'order_id'       => $order_id,
			'payment_token'  => $pay_id,
			'is_sandbox'     => $is_sandbox,
			'api_public_key' => $public_key,
		]);
		$timestamp = (string) time();
		$response  = wp_remote_post(
			$this->get_api_url('/api/update-txn-status'),
			[
				'method'    => 'POST',
				'body'      => $body,
				'headers'   => [
					'Content-Type'  => 'application/json',
					'Accept'        => 'application/json',
					'Authorization' => 'Bearer ' . sanitize_text_field($public_key),
					'X-Timestamp'   => $timestamp,
					'X-Signature'   => hash_hmac('sha256', $timestamp . '.' . $body, $secret_key),
				],
				'timeout'   => 15,
				'sslverify' => true,
			]
		);

		$fallback_code = is_wp_error($response) ? 0 : (int) wp_remote_retrieve_response_code($response);

		if (!is_wp_error($response) && $fallback_code >= 200 && $fallback_code < 300) {
			$decoded = $this->bank_transfer_unwrap_status_payload(json_decode(wp_remote_retrieve_body($response), true));
			if (is_array($decoded) && $this->bank_transfer_payload_has_status($decoded)) {
				$result['response']  = $response;
				$result['http_code'] = $fallback_code;
				$result['endpoint']  = 'update-txn-status';
				$result['data']      = $decoded;
				return $result;
			}
		}

		// Neither endpoint produced a usable status. Report the fallback response,
		// unless the primary endpoint already answered with an auth / rate-limit
		// error, which is the more useful thing to surface.
		if (!in_array($result['http_code'], [401, 403, 429], true)) {
			$result['response']  = $response;
			$result['http_code'] = $fallback_code;
			$result['endpoint']  = 'update-txn-status';
		}

		return $result;
	}

	/**
	 * Some endpoints wrap the payload in a "data" envelope; flatten that so the
	 * status fields are always available at the top level.
	 *
	 * @param mixed $decoded
	 * @return array|null
	 */
	private function bank_transfer_unwrap_status_payload($decoded)
	{
		if (!is_array($decoded)) {
			return null;
		}
		if (
			!$this->bank_transfer_payload_has_status($decoded) &&
			isset($decoded['data']) && is_array($decoded['data']) &&
			$this->bank_transfer_payload_has_status($decoded['data'])
		) {
			return array_merge($decoded, $decoded['data']);
		}
		return $decoded;
	}

	/**
	 * Whether a decoded payload carries a payment status we understand.
	 * A bare top-level "status" is deliberately ignored: on several endpoints it
	 * describes the API call ("success"), not the payment.
	 */
	private function bank_transfer_payload_has_status(array $data)
	{
		return isset($data['payment_status'])
			|| isset($data['settled'])
			|| isset($data['failed'])
			|| isset($data['transaction_status'])
			|| isset($data['transaction']['status']);
	}

	/**
	 * Map a raw backend status payload to the state-engine vocabulary.
	 *
	 * @param array|null $data
	 * @return string|null 'success' | 'failed' | 'cancelled' | 'expired' | 'processing' | raw unknown value | null
	 */
	public function bank_transfer_normalize_remote_status($data)
	{
		$data = $this->bank_transfer_unwrap_status_payload($data);
		if (!is_array($data)) {
			return null;
		}

		$raw = $data['payment_status']
			?? $data['transaction_status']
			?? ($data['transaction']['status'] ?? null);
		$raw = is_string($raw) ? strtolower(trim($raw)) : '';

		$settled = isset($data['settled']) && filter_var($data['settled'], FILTER_VALIDATE_BOOLEAN);
		$failed  = isset($data['failed'])  && filter_var($data['failed'],  FILTER_VALIDATE_BOOLEAN);

		if ($settled || in_array($raw, ['success', 'paid', 'completed', 'settled', 'succeeded'], true)) {
			return 'success';
		}
		if ($failed || $raw === 'failed') {
			return 'failed';
		}
		if (in_array($raw, ['cancelled', 'canceled'], true)) {
			return 'cancelled';
		}
		if ($raw === 'expired') {
			return 'expired';
		}
		if (in_array($raw, ['pending', 'processing', 'initiated', 'created', 'open', 'awaiting'], true)) {
			return 'processing';
		}

		return $raw !== '' ? $raw : null;
	}

	/* =========================================================
	 * ORDER STATUS SYNC
	 * ========================================================= */

	/**
	 * Sync all pending Bank Transfer orders with the remote payment API.
	 *
	 * @param int $limit Max number of pending orders to check per run.
	 * @return array Summary of synced order states keyed by order id.
	 */
	public function sync_pending_orders($limit = 20)
	{
		if (!function_exists('wc_get_orders')) {
			return [];
		}

		$orders = wc_get_orders([
			'status'         => ['pending', 'on-hold'],
			'payment_method' => 'bank_transfer',
			'limit'          => absint($limit),
			'orderby'        => 'date',
			'order'          => 'DESC',
		]);

		$synced = [];
		if (!empty($orders) && is_array($orders)) {
			foreach ($orders as $order) {
				if (!$order instanceof WC_Order) {
					continue;
				}
				$last_attempt = (int) $order->get_meta('_bank_transfer_last_sync_attempt');
				if ($last_attempt > 0 && (time() - $last_attempt) < 15) {
					continue;
				}
				$order->update_meta_data('_bank_transfer_last_sync_attempt', time());
				$order->save();

				$result = $this->sync_single_order_status($order);
				if ($result) {
					$synced[$order->get_id()] = $result;
				}
			}
		}

		return $synced;
	}

	/**
	 * Sync a single Bank Transfer order with the remote payment API and push the
	 * outcome through the state engine.
	 *
	 * @param WC_Order|int $order Order instance or order ID.
	 * @return string|null 'success', 'failed', 'cancelled', 'expired', 'processing' or null when nothing usable came back.
	 */
	public function sync_single_order_status($order)
	{
		if (is_numeric($order)) {
			$order = wc_get_order(absint($order));
		}

		if (!$order instanceof WC_Order || $order->get_payment_method() !== 'bank_transfer') {
			return null;
		}

		$is_already_paid = ($order->has_status(['processing', 'completed']) || $order->get_meta('_bank_transfer_state') === 'success');
		$order_created   = $order->get_date_created() ? $order->get_date_created()->getTimestamp() : 0;
		if ($is_already_paid && (time() - $order_created > 14 * DAY_IN_SECONDS)) {
			return 'success';
		}

		$order_id   = $order->get_id();
		$log_prefix = "[Order #{$order_id}] Sync |";
		$ctx        = ['source' => 'bank-transfer-payment-gateway'];

		$pay_id = $this->bank_transfer_resolve_order_pay_id($order);
		$creds  = $this->get_order_account_credentials($order);

		if (empty($creds['public_key']) || empty($creds['secret_key'])) {
			Bank_Transfer_Payment_Gateway_Logger::warning("{$log_prefix} Missing API credentials for merchant account", $ctx);
			return null;
		}

		$lookup = $this->bank_transfer_fetch_remote_payment_status($order, $pay_id, $creds);

		if (!is_array($lookup['data'])) {
			$why = is_wp_error($lookup['response'])
				? $lookup['response']->get_error_message()
				: 'HTTP ' . (int) $lookup['http_code'];
			Bank_Transfer_Payment_Gateway_Logger::info("{$log_prefix} No usable status from backend ({$why})", $ctx);
			return null;
		}

		$status_data = $lookup['data'];
		$state       = $this->bank_transfer_normalize_remote_status($status_data);

		$tx_ref = sanitize_text_field((string) ($status_data['transaction']['reference'] ?? ($status_data['reference'] ?? '')));
		if ($tx_ref !== '') {
			$order->update_meta_data('_bank_transfer_transaction_reference', $tx_ref);
			$order->save();
		}

		Bank_Transfer_Payment_Gateway_Logger::info(
			"{$log_prefix} Backend reports '" . ($state ?? 'unknown') . "' via {$lookup['endpoint']}",
			array_merge($ctx, ['pay_id' => $pay_id])
		);

		if ($state === 'returned') {
			$result = BANK_TRANSFER_PAYMENT_ENGINE::handle_event(
				$order_id,
				'api_sync',
				[
					'status'        => 'returned',
					'payment_token' => $pay_id,
					'source'        => 'API Sync',
					'reason'        => $status_data['reason'] ?? ($status_data['return_reason'] ?? 'ACH Return / Reversal'),
				]
			);
			Bank_Transfer_Payment_Gateway_Logger::info("{$log_prefix} Engine result: " . wp_json_encode($result), $ctx);
			return 'returned';
		}

		if ($state === 'success') {
			// BK-05: Uniform strict verification for amount, currency, and pay_id
			if (!$this->bank_transfer_validate_payment_verification_data($order, $status_data, $pay_id)) {
				$this->bank_transfer_note_sync_mismatch($order, "Verification failed (amount, currency, or pay_id mismatch)");
				return null;
			}

			$result = BANK_TRANSFER_PAYMENT_ENGINE::handle_event(
				$order_id,
				'api_sync',
				[
					'status'        => 'success',
					'payment_token' => $pay_id,
					'source'        => 'API Sync',
				]
			);

			Bank_Transfer_Payment_Gateway_Logger::info("{$log_prefix} Engine result: " . wp_json_encode($result), $ctx);

			return 'success';
		}

		if (in_array($state, ['failed', 'cancelled', 'expired'], true)) {
			$result = BANK_TRANSFER_PAYMENT_ENGINE::handle_event(
				$order_id,
				'api_sync',
				[
					'status'        => $state,
					'payment_token' => $pay_id,
					'source'        => 'API Sync',
				]
			);

			Bank_Transfer_Payment_Gateway_Logger::info("{$log_prefix} Engine result: " . wp_json_encode($result), $ctx);

			return $state;
		}

		return 'processing';
	}

	/**
	 * Record (once) why a backend "paid" status was not applied to the order.
	 */
	private function bank_transfer_note_sync_mismatch($order, $detail)
	{
		Bank_Transfer_Payment_Gateway_Logger::warning(
			'[Order #' . $order->get_id() . '] Sync | Backend reports paid but ' . $detail . '. Order left unchanged for manual review.',
			['source' => 'bank-transfer-payment-gateway']
		);

		if (!$order->get_meta('_bank_transfer_sync_mismatch_noted')) {
			$order->add_order_note(
				'<strong>Bank Transfer Gateway</strong><br><br>' .
				esc_html__('The payment portal reports this payment as completed, but the details do not match the order:', 'bank-transfer-payment-gateway') .
				' ' . esc_html($detail) . '<br>' .
				esc_html__('The order status was left unchanged. Please verify the payment manually.', 'bank-transfer-payment-gateway')
			);
			$order->update_meta_data('_bank_transfer_sync_mismatch_noted', 'yes');
			$order->save();
		}
	}

	/* =========================================================
	 * AUTOMATIC SYNC TRIGGERS (ADMIN + CUSTOMER)
	 * ========================================================= */

	/**
	 * Auto-sync when an admin opens a pending order's edit screen.
	 *
	 * @param WC_Order $order
	 */
	public function bank_transfer_admin_order_sync_on_view($order)
	{
		if (!$order instanceof WC_Order || $order->get_payment_method() !== 'bank_transfer') {
			return;
		}
		if (!$order->has_status(['pending', 'on-hold'])) {
			return;
		}
		$last_sync = (int) $order->get_meta('_bank_transfer_last_admin_sync');
		if (time() - $last_sync < 15) {
			return;
		}
		$order->update_meta_data('_bank_transfer_last_admin_sync', time());
		$order->save();

		$this->sync_single_order_status($order);
	}

	/**
	 * Add a manual "check status" action to the order edit screen.
	 *
	 * @param array         $actions
	 * @param WC_Order|null $order
	 * @return array
	 */
	public function bank_transfer_add_order_actions($actions, $order = null)
	{
		if (!$order instanceof WC_Order) {
			global $theorder;
			$order = $theorder;
		}
		if ($order instanceof WC_Order && $order->get_payment_method() === 'bank_transfer') {
			$actions['bank_transfer_check_status'] = __('Check Bank Transfer payment status', 'bank-transfer-payment-gateway');
		}
		return $actions;
	}

	/**
	 * Handle the manual "check status" order action.
	 *
	 * @param WC_Order $order
	 */
	public function bank_transfer_process_order_action_check_status($order)
	{
		if (!$order instanceof WC_Order || $order->get_payment_method() !== 'bank_transfer') {
			return;
		}

		$result = $this->sync_single_order_status($order);

		if ($result === 'success') {
			$order->add_order_note(__('Manual status check: Payment confirmed successfully.', 'bank-transfer-payment-gateway'));
		} elseif ($result) {
			/* translators: %s: payment status reported by the payment portal */
			$order->add_order_note(sprintf(__('Manual status check: Payment portal reports status "%s".', 'bank-transfer-payment-gateway'), $result));
		} else {
			$order->add_order_note(__('Manual status check: No status update available from the payment portal.', 'bank-transfer-payment-gateway'));
		}
	}

	/**
	 * Auto-sync pending orders when an admin loads the Orders list screen.
	 *
	 * @param WP_Screen|null $screen
	 */
	public function bank_transfer_admin_orders_screen_sync($screen = null)
	{
		if (!is_admin() || !current_user_can('manage_woocommerce')) {
			return;
		}

		$screen_id = is_object($screen) ? ($screen->id ?? '') : '';
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$page      = isset($_GET['page']) ? sanitize_text_field(wp_unslash($_GET['page'])) : '';
		$post_type = isset($_GET['post_type']) ? sanitize_text_field(wp_unslash($_GET['post_type'])) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		// Single order edit screen (HPOS: ?page=wc-orders&action=edit&id=N, classic:
		// post.php?post=N). Refresh that order before the page renders so the status
		// WooCommerce prints is already the settled one, not the stale pending state.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$edit_id = isset($_GET['id']) ? absint($_GET['id']) : (isset($_GET['post']) ? absint($_GET['post']) : 0);
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ($edit_id > 0 && ($page === 'wc-orders' || $post_type === 'shop_order' || in_array($screen_id, ['shop_order', 'post', 'woocommerce_page_wc-orders'], true))) {
			$edit_order = wc_get_order($edit_id);
			if ($edit_order instanceof WC_Order) {
				$this->bank_transfer_admin_order_sync_on_view($edit_order);
			}
			return;
		}

		$is_orders_screen = in_array($screen_id, ['shop_order', 'woocommerce_page_wc-orders', 'edit-shop_order'], true)
			|| $page === 'wc-orders'
			|| $post_type === 'shop_order';

		if (!$is_orders_screen) {
			return;
		}

		$last_screen_sync = (int) get_transient('bank_transfer_last_orders_screen_sync');
		if (time() - $last_screen_sync >= 10) {
			set_transient('bank_transfer_last_orders_screen_sync', time(), 30);
			$this->sync_pending_orders(10);
		}
	}

	/**
	 * Auto-sync a pending order while its row renders in the HPOS orders table.
	 */
	public function bank_transfer_admin_order_row_filter($classes, $order)
	{
		if ($order instanceof WC_Order) {
			$this->bank_transfer_maybe_sync_order_row($order);
		}
		return $classes;
	}

	/**
	 * Auto-sync a pending order while its row renders in the classic (posts) orders table.
	 */
	public function bank_transfer_admin_post_class_filter($classes, $class, $post_id)
	{
		if (is_admin() && get_post_type($post_id) === 'shop_order') {
			$order = wc_get_order($post_id);
			if ($order instanceof WC_Order) {
				$this->bank_transfer_maybe_sync_order_row($order);
			}
		}
		return $classes;
	}

	private function bank_transfer_maybe_sync_order_row($order)
	{
		if ($order->get_payment_method() !== 'bank_transfer' || !$order->has_status(['pending', 'on-hold'])) {
			return;
		}
		$last_sync = (int) $order->get_meta('_bank_transfer_last_row_sync');
		if (time() - $last_sync < 300) {
			return;
		}
		$order->update_meta_data('_bank_transfer_last_row_sync', time());
		$order->save();

		// BK-14: Schedule background async sync rather than blocking the admin orders page render loop
		if (!wp_next_scheduled('bank_transfer_async_sync_order', [$order->get_id()])) {
			wp_schedule_single_event(time(), 'bank_transfer_async_sync_order', [$order->get_id()]);
		}
	}

	/**
	 * Auto-sync when the customer lands on the order-received page or views the
	 * order in My Account. Runs before the status text renders so the page shows
	 * the settled state straight away.
	 *
	 * @param int $order_id
	 */
	public function bank_transfer_customer_order_sync($order_id)
	{
		$order = wc_get_order($order_id);
		if (!$order instanceof WC_Order || $order->get_payment_method() !== 'bank_transfer') {
			return;
		}
		if (!$order->has_status(['pending', 'on-hold'])) {
			return;
		}
		$last_sync = (int) $order->get_meta('_bank_transfer_last_customer_sync');
		if (time() - $last_sync < 5) {
			return;
		}
		$order->update_meta_data('_bank_transfer_last_customer_sync', time());
		$order->save();

		$this->sync_single_order_status($order);
	}
}
