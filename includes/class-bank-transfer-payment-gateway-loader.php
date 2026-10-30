<?php
if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly.
}

// Include the configuration file
require_once plugin_dir_path(__FILE__) . 'config.php';
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
		$this->check_rate_limit('block_ajax', 20, 60);

		// Nonce verification
		$nonce = isset($_POST['nonce'])
			? sanitize_text_field(wp_unslash($_POST['nonce']))
			: '';

		if (empty($nonce) || !wp_verify_nonce($nonce, 'bank_transfer_payment')) {
			wp_send_json(['result' => 'fail', 'error' => 'Security check failed.']);
			die;
		}

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

		$orderID = WC()->session
			? ( WC()->session->get('store_api_draft_order') ?: WC()->session->get('order_awaiting_payment') )
			: null;

		/*
		 * WooCommerce 10.8+ writes 'store_api_draft_order' in exactly one place:
		 * the Store API /checkout route (StoreApi/Routes/V1/Checkout.php). This
		 * handler bypasses that route - the JS calls preventDefault() on Place
		 * Order and posts here instead - so on WooCommerce 11 the key is never
		 * set and every block checkout ended at "Invalid order.".
		 *
		 * Older WooCommerce created the draft order during the cart routes, so
		 * the key was already populated by the time Place Order ran. That is why
		 * this worked in 1.0.16 and stopped working since.
		 *
		 * Build the order here, ALWAYS - not only when the pointers are empty.
		 *
		 * Reusing a session order id verbatim was sending whatever total that
		 * order was built with. Once an attempt had been rejected, changing the
		 * amount changed the cart but never the order, so request-payment kept
		 * receiving the identical order+amount and kept answering "This order
		 * appears to be a duplicate".
		 *
		 * WC_Checkout::create_order() already resolves this correctly: it
		 * resumes 'order_awaiting_payment' only while the cart hash still
		 * matches and the order is pending/failed, otherwise it starts a new
		 * order - and either way it calls set_data_from_cart(), so items,
		 * shipping, coupons and totals are rebuilt from the current cart every
		 * time. A changed amount therefore produces a correct amount, and a
		 * changed cart produces a new order id that is not a duplicate.
		 *
		 * The session pointers stay as the fallback for the case where there is
		 * no cart to build from.
		 */
		if ( ! empty( WC()->cart ) && ! WC()->cart->is_empty() ) {
			$orderID = $this->bank_transfer_create_block_order() ?: $orderID;
		}

		$status = [];
		if($orderID){
			$status = $bank_transferPayment->process_payment($orderID);
		}else{
			wc_add_notice(__('Invalid order.', 'bank-transfer-payment-gateway'), 'error');
			$status = ['result' => 'fail','error' => 'Invalid order.'];
		}
		
		wp_send_json($status);
		die;
	}

	/**
	 * Create the WooCommerce order for a block checkout payment.
	 *
	 * Delegates to WC_Checkout::create_order() so line items, shipping lines,
	 * coupons, taxes and totals are built by WooCommerce itself rather than by
	 * hand here.
	 *
	 * @return int Order ID, or 0 on failure.
	 */
	private function bank_transfer_create_block_order() {

		$checkout = WC()->checkout();
		$data     = $checkout->get_posted_data();
		$customer = WC()->customer;

		/*
		 * Block checkout inputs carry no name attribute, so the serialized form
		 * posts almost nothing and get_posted_data() returns blanks - it reads
		 * $_POST only, with no customer fallback. WC()->customer is what the
		 * Store API keeps in sync as the shopper types, so use it to fill in
		 * anything the post did not carry.
		 */
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

			/*
			 * BILLING ONLY - never shipping.
			 *
			 * WooCommerce already owns shipping: get_posted_data() decides via
			 * maybe_skip_fieldset() whether shipping was submitted at all, and
			 * create_order() copies billing into shipping when ship-to-different
			 * is off. Writing shipping keys here puts WC()->customer's copy on
			 * top of that decision, which overrides the address FunnelKit (and
			 * any other checkout that manages shipping itself) already set.
			 *
			 * Leaving shipping untouched keeps WooCommerce's own behaviour.
			 */
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

		if ( empty( $data['payment_method'] ) ) {
			$data['payment_method'] = 'bank_transfer';
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
	public function check_rate_limit($action, $max_requests = 60, $period = 60, $extra_key = '')
	{
		$ip = sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
		$key_suffix = !empty($extra_key) ? '_' . $extra_key : '';
		$transient_key = 'bt_rl_' . md5($action . '_' . $ip . $key_suffix);
		$count = (int) get_transient($transient_key);

		if ($count >= $max_requests) {
			wp_send_json_error([
				'message'      => 'Too many requests. Please slow down.',
				'stop_polling' => false,
				'rate_limited' => true,
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
				'docs'    => '<a href="' . esc_url(apply_filters('bank_transfer_docs_url', 'https://pay.rt.app/docs')) . '" target="_blank">' . esc_html__('Documentation', 'bank-transfer-payment-gateway') . '</a>',
				'support' => '<a href="' . esc_url(apply_filters('bank_transfer_support_url', 'https://pay.rt.app/contact-us')) . '" target="_blank">' . esc_html__('Support', 'bank-transfer-payment-gateway') . '</a>',
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
		// Sanitize and validate the order ID from $_POST
		$order_id = isset($_POST['order_id']) ? intval(sanitize_text_field(wp_unslash($_POST['order_id']))) : null;
		$this->check_rate_limit('status_check', 60, 60, $order_id ? (string) $order_id : '');
		check_ajax_referer('bank_transfer_payment', 'security');
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
		$payment_token = $order->get_meta('_bank_transfer_pay_id');
		$creds         = $this->get_order_account_credentials($order);
		$public_key    = $creds['public_key'];
		$secret_key    = $creds['secret_key'];
		$is_sandbox    = $creds['is_sandbox'];

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

		$response = wp_remote_post(
			$this->get_api_url('/api/update-txn-status'),
			[
				'method'    => 'POST',
				'body'      => wp_json_encode([
					'order_id'       => $order_id,
					'payment_token'  => $payment_token,
					'is_sandbox'     => $is_sandbox,
					'api_public_key' => $public_key,
				]),
				'headers'   => [
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . sanitize_text_field($public_key),
				],
				'timeout'   => 15,
				'sslverify' => true,
			]
		);

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

		$response_data = json_decode(
			wp_remote_retrieve_body($response),
			true
		);

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

		$payment_status =
			$response_data['transaction_status']
			?? $response_data['payment_status']
			?? null;

			
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

		$order_id = isset($_POST['order_id'])
			? intval(sanitize_text_field(wp_unslash($_POST['order_id'])))
			: 0;

		$this->check_rate_limit('popup_close', 60, 60, $order_id > 0 ? (string) $order_id : '');

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
		$payment_token = $order->get_meta('_bank_transfer_pay_id');
		$creds         = $this->get_order_account_credentials($order);
		$public_key    = $creds['public_key'];
		$secret_key    = $creds['secret_key'];
		$is_sandbox    = $creds['is_sandbox'];

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

		$response = wp_remote_post(
			$this->get_api_url('/api/update-txn-status'),
			[
				'method'    => 'POST',
				'body'      => wp_json_encode([
					'order_id'       => $order_id,
					'payment_token'  => $payment_token,
					'is_sandbox'     => $is_sandbox,
					'api_public_key' => $public_key,
				]),
				'headers'   => [
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . sanitize_text_field($public_key),
				],
				'timeout'   => 15,
				'sslverify' => true,
			]
		);

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

		$response_data = json_decode(
			wp_remote_retrieve_body($response),
			true
		);

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

		$payment_status =
			$response_data['transaction_status']
			?? $response_data['payment_status']
			?? null;

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

		$stop_polling = in_array($state, ['failed', 'cancelled', 'expired'], true);

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

		foreach ($accounts as &$account) {
			$isSandboxEnabled = isset($account['has_sandbox']) && $account['has_sandbox'] === 'on';

			// Prepare both live and sandbox entries
			if (!empty($account['live_public_key'])) {
				$accountsData[] = [
					'account_name' => $account['title'] ?? '',
					'public_key'   => $account['live_public_key'],
					'mode'         => 'live',
				];
				if (empty($first_public_key)) {
					$first_public_key = $account['live_public_key'];
				}
			}

			if ($isSandboxEnabled && !empty($account['sandbox_public_key'])) {
				$accountsData[] = [
					'account_name' => $account['title'] ?? '',
					'public_key'   => $account['sandbox_public_key'],
					'mode'         => 'sandbox',
				];
				if (empty($first_public_key)) {
					$first_public_key = $account['sandbox_public_key'];
				}
			}
		}

		if (empty($accountsData)) {
			Bank_Transfer_Payment_Gateway_Logger::warning('No valid credentials found in any payment account. Sync skipped.', $logger_context);
			return [];
		}

		$url = esc_url($this->get_api_url('/sync-account-status'));
		$response = wp_remote_post($url, [
			'headers' => [
				'Content-Type'  => 'application/json',
				'Authorization' => 'Bearer ' . sanitize_text_field($first_public_key),
			],
			'body'      => wp_json_encode(['accounts' => $accountsData]),
			'timeout'   => 15,
			'sslverify' => true,
		]);

		if (is_wp_error($response)) {
			Bank_Transfer_Payment_Gateway_Logger::error('Unable to connect to the sync service. Please check the server connection or endpoint.', $logger_context);
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
			Bank_Transfer_Payment_Gateway_Logger::error(
				'Unable to send plugin status. No public key found.',
				[
					'source' => 'bank-transfer-payment-gateway',
				]
			);
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
			'plugin_version'         => BANK_TRANSFER_PLUGIN_VERSION,
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
}