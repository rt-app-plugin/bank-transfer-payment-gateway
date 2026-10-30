<?php
if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly.
}

require_once plugin_dir_path(__FILE__) . 'class-bank-transfer-payment-state-engine.php';

class BANK_TRANSFER_PAYMENT_GATEWAY_REST_API
{
	private $logger;
	private static $instance = null;

	public static function get_instance()
	{
		if (null === self::$instance) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function __construct()
	{
		// Initialize the logger
		$this->logger = wc_get_logger();

		// BT-13: Only add CORS headers for bank-transfer/v1 routes without breaking global WordPress REST handling
		add_filter('rest_pre_serve_request', function ($value, $result, $request) {
			$route = ($request instanceof WP_REST_Request) ? $request->get_route() : '';
			if (empty($route) && !empty($_SERVER['REQUEST_URI'])) {
				$route = sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI']));
			}

			if (strpos($route, 'bank-transfer/v1') !== false || strpos($route, 'bank_transfer/v1') !== false) {
				header('Access-Control-Allow-Origin: ' . BANK_TRANSFER_BASE_URL);
				header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
				header('Access-Control-Allow-Headers: Authorization, Content-Type, X-WP-Nonce, X-Signature, X-Timestamp, X-API-Key, User-Agent, Accept');

				$request_method = filter_input(INPUT_SERVER, 'REQUEST_METHOD', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
				$request_method = $request_method ? strtoupper($request_method) : '';

				if ($request_method === 'OPTIONS') {
					status_header(200);
					exit;
				}
			}

			return $value;
		}, 15, 3);
	}

	public function bank_transfer_register_routes()
	{
		add_action('rest_api_init', function () {
			register_rest_route('bank-transfer/v1', '/data', array(
				'methods'             => ['GET', 'POST'],
				'callback'            => array($this, 'bank_transfer_handle_api_request'),
				'permission_callback' => '__return_true',
			));
			register_rest_route('bank_transfer/v1', '/data', array(
				'methods'             => ['GET', 'POST'],
				'callback'            => array($this, 'bank_transfer_handle_api_request'),
				'permission_callback' => '__return_true',
			));
		});
	}

	/**
	 * BT-05: Verifies webhook authentication using HMAC-SHA256 signature or current-mode secret key.
	 * Never accepts public keys or sandbox keys in live mode.
	 */
	private function bank_transfer_verify_webhook(WP_REST_Request $request, $order)
	{
		$settings   = get_option('woocommerce_bank_transfer_settings', []);
		$is_sandbox = isset($settings['sandbox']) && $settings['sandbox'] === 'yes';

		$accounts_data = get_option('woocommerce_bank_transfer_payment_gateway_accounts', []);
		if (is_string($accounts_data)) {
			$accounts_data = maybe_unserialize($accounts_data);
		}
		if (!is_array($accounts_data)) {
			$accounts_data = [];
		}
		if (isset($accounts_data['live_secret_key']) || isset($accounts_data['sandbox_secret_key'])) {
			$accounts_data = [$accounts_data];
		}

		if (empty($accounts_data)) {
			Bank_Transfer_Payment_Gateway_Logger::warning('No account data found for webhook auth', ['source' => 'bank-transfer-payment-gateway']);
			return false;
		}

		$order_public_key = $order->get_meta('_bank_transfer_public_key');
		$valid_secrets    = [];

		foreach ($accounts_data as $account) {
			if (!is_array($account)) {
				continue;
			}
			$pub = $is_sandbox ? ($account['sandbox_public_key'] ?? '') : ($account['live_public_key'] ?? '');
			$sec = $is_sandbox ? ($account['sandbox_secret_key'] ?? '') : ($account['live_secret_key'] ?? '');
			$sec = trim(trim(sanitize_text_field($sec)), '"\'');

			if (empty($sec)) {
				continue;
			}

			if (!empty($order_public_key) && $pub === $order_public_key) {
				$valid_secrets = [$sec];
				break;
			}
			$valid_secrets[] = $sec;
		}

		if (empty($valid_secrets)) {
			return false;
		}

		// 1. Check HMAC signature if provided
		$sig_header       = $request->get_header('x-signature');
		$timestamp_header = $request->get_header('x-timestamp');

		if (!empty($sig_header) && !empty($timestamp_header)) {
			$timestamp_int = intval($timestamp_header);
			if (abs(time() - $timestamp_int) > 300) {
				Bank_Transfer_Payment_Gateway_Logger::warning('Webhook timestamp expired', ['timestamp' => $timestamp_int]);
				return false;
			}

			$raw_body = $request->get_body();
			foreach ($valid_secrets as $secret) {
				$expected_sig = hash_hmac('sha256', $timestamp_header . '.' . $raw_body, $secret);
				if (hash_equals($expected_sig, $sig_header)) {
					return true;
				}
			}
			Bank_Transfer_Payment_Gateway_Logger::warning('Webhook HMAC signature mismatch');
			return false;
		}

		// 2. Fallback to secret key in authorization header or nonce parameter (reject public keys)
		$params      = $request->get_params();
		$api_key_raw = trim(trim($params['nonce'] ?? ''), '"\'');

		if (empty($api_key_raw)) {
			$auth_header = $request->get_header('authorization');
			if (!empty($auth_header) && preg_match('/Bearer\s+(\S+)/i', $auth_header, $matches)) {
				$api_key_raw = $matches[1];
			} elseif (!empty($request->get_header('x-api-key'))) {
				$api_key_raw = $request->get_header('x-api-key');
			}
		}

		if (!empty($api_key_raw)) {
			$decoded_nonce = base64_decode($api_key_raw, true);
			$key_to_verify = ($decoded_nonce !== false && !empty($decoded_nonce)) ? $decoded_nonce : $api_key_raw;
			$key_to_verify = trim(trim(sanitize_text_field($key_to_verify)), '"\'');

			foreach ($valid_secrets as $secret) {
				if (hash_equals($secret, $key_to_verify) || hash_equals($secret, $api_key_raw)) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Handles incoming Bank Transfer API requests.
	 *
	 * @param WP_REST_Request $request The REST API request object.
	 * @return WP_REST_Response The response object.
	 */
	public function bank_transfer_handle_api_request(WP_REST_Request $request)
	{
		$method      = strtoupper($request->get_method());
		$params      = $request->get_params();
		$log_context = ['source' => 'bank-transfer-payment-gateway'];

		$data = isset($params['api_data']) && is_array($params['api_data']) ? $params['api_data'] : $params;

		$order_id         = intval($data['order_id'] ?? ($params['order_id'] ?? 0));
		$api_order_status = trim(trim(sanitize_text_field($data['order_status'] ?? ($params['order_status'] ?? ''))), '"\'');
		$pay_id           = trim(trim(sanitize_text_field($data['pay_id'] ?? ($params['pay_id'] ?? ''))), '"\'');

		Bank_Transfer_Payment_Gateway_Logger::info(
			"Bank Transfer API HIT | Method: {$method} | Order #{$order_id} | Status: {$api_order_status} | Pay ID: {$pay_id}",
			$log_context
		);

		// -------------------------
		// 1. GET (RETURN / REDIRECT ONLY) - BT-01 & BT-02
		// -------------------------
		if ($method === 'GET') {
			$provided_key = sanitize_text_field($data['key'] ?? ($params['key'] ?? ($data['order_key'] ?? ($params['order_key'] ?? ''))));
			$order        = ($order_id > 0) ? wc_get_order($order_id) : false;

			// Verify order existence, order key, and payment method uniformly to prevent ID enumeration
			if (
				$order_id <= 0 ||
				!$order ||
				empty($provided_key) ||
				!hash_equals($order->get_order_key(), $provided_key) ||
				$order->get_payment_method() !== 'bank_transfer'
			) {
				return new WP_REST_Response([
					'success' => false,
					'message' => 'Invalid request'
				], 403);
			}

			// Safari / session tracking
			if (isset(WC()->session)) {
				WC()->session->set('order_awaiting_payment', $order->get_id());
			}

			$wc_status = $order->get_status();
			if (in_array($wc_status, ['failed', 'cancelled'], true)) {
				wc_add_notice('Payment was not completed. Please try again.', 'error');
				wp_safe_redirect(wc_get_checkout_url());
			} else {
				wp_safe_redirect($order->get_checkout_order_received_url());
			}
			exit;
		}

		// -------------------------
		// 2. POST (WEBHOOK ONLY)
		// -------------------------
		if ($method !== 'POST') {
			return new WP_REST_Response([
				'success' => false,
				'message' => 'Method not allowed'
			], 405);
		}

		if ($order_id <= 0) {
			return new WP_REST_Response([
				'success' => false,
				'message' => 'Invalid ID'
			], 400);
		}

		$order = wc_get_order($order_id);
		if (!$order || $order->get_payment_method() !== 'bank_transfer') {
			return new WP_REST_Response([
				'success' => false,
				'message' => 'Order not found'
			], 404);
		}

		// BT-05: Authenticate webhook strictly
		if (!$this->bank_transfer_verify_webhook($request, $order)) {
			return new WP_REST_Response([
				'success'    => false,
				'error_code' => 'INVALID_API_KEY',
				'message'    => 'Authentication failed.'
			], 401);
		}

		// BT-05: Check that pay_id matches stored order token if both exist
		$stored_pay_id = $order->get_meta('_bank_transfer_pay_id');
		if (!empty($stored_pay_id) && !empty($pay_id) && !hash_equals($stored_pay_id, $pay_id)) {
			Bank_Transfer_Payment_Gateway_Logger::warning("Webhook Pay ID mismatch for Order #{$order_id}", [
				'stored'   => $stored_pay_id,
				'received' => $pay_id,
			]);
			return new WP_REST_Response([
				'success' => false,
				'message' => 'Payment ID mismatch.'
			], 400);
		}

		// BT-05: Check amount if provided in webhook payload
		if (isset($data['amount']) && is_numeric($data['amount'])) {
			$webhook_amount = floatval($data['amount']);
			$order_amount   = floatval($order->get_total());
			if (abs($webhook_amount - $order_amount) > 0.01) {
				Bank_Transfer_Payment_Gateway_Logger::warning("Webhook amount mismatch for Order #{$order_id}", [
					'order_amount'   => $order_amount,
					'webhook_amount' => $webhook_amount,
				]);
				return new WP_REST_Response([
					'success' => false,
					'message' => 'Amount mismatch.'
				], 400);
			}
		}

		// BT-05: Check currency if provided in webhook payload
		$curr_code = $data['curr_code'] ?? ($data['currency'] ?? '');
		if (!empty($curr_code)) {
			$webhook_curr = strtoupper(trim(sanitize_text_field($curr_code)));
			$order_curr   = strtoupper(trim($order->get_currency()));
			if ($webhook_curr !== $order_curr) {
				Bank_Transfer_Payment_Gateway_Logger::warning("Webhook currency mismatch for Order #{$order_id}", [
					'order_currency'   => $order_curr,
					'webhook_currency' => $webhook_curr,
				]);
				return new WP_REST_Response([
					'success' => false,
					'message' => 'Currency mismatch.'
				], 400);
			}
		}

		// -------------------------
		// 3. ENGINE CALL (WEBHOOK ONLY)
		// -------------------------
		if (!empty($api_order_status)) {
			$result = BANK_TRANSFER_PAYMENT_ENGINE::handle_event(
				$order_id,
				'webhook_update',
				[
					'status'        => $api_order_status,
					'payment_token' => $pay_id,
					'source'        => 'Webhook'
				]
			);

			Bank_Transfer_Payment_Gateway_Logger::info(
				"Bank Transfer ENGINE RESULT | Order #{$order_id} | " . wp_json_encode($result),
				$log_context
			);

			// BT-39: Return 423 Locked when lock cannot be acquired so API retries
			if (!empty($result['reason']) && $result['reason'] === 'locked_skip') {
				return new WP_REST_Response([
					'success' => false,
					'message' => 'Order is currently locked, please retry.'
				], 423);
			}
		}

		// -------------------------
		// 4. RESOLVE FINAL STATE
		// -------------------------
		$order = wc_get_order($order_id);
		$state = BANK_TRANSFER_PAYMENT_ENGINE::resolve_final_state(
			$order,
			$api_order_status
		);

		$wc_status = $order->get_status();
		if ($wc_status === 'processing' || $wc_status === 'completed') {
			$state = 'success';
		}

		$is_success = ($state === 'success');
		$message = match ($state) {
			'success'    => 'Payment confirmed successfully.',
			'failed'     => 'Payment failed. Please try again.',
			'cancelled'  => 'Payment was cancelled.',
			'processing' => 'Payment is being processed.',
			'pending'    => 'Payment is pending.',
			default      => 'Payment status is being verified.'
		};

		Bank_Transfer_Payment_Gateway_Logger::info(
			"Bank Transfer FINAL RESPONSE | Order #{$order_id} | State: {$state} | WC: {$wc_status}",
			$log_context
		);

		return new WP_REST_Response([
			'success' => $is_success,
			'message' => $message
		], 200);
	}
}