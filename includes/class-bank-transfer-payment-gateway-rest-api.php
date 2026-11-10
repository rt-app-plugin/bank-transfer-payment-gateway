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

		// BT-13: Only add CORS headers for bank-transfer/v1 routes without breaking global WordPress REST handling.
		// Match strictly on the resolved REST route, anchored to our namespace. Never fall back to REQUEST_URI:
		// a substring match on it lets /wp-json/anything?x=bank-transfer/v1 receive our headers and the OPTIONS exit.
		add_filter('rest_pre_serve_request', function ($value, $result, $request) {
			$route = ($request instanceof WP_REST_Request) ? (string) $request->get_route() : '';

			if ($route !== '' && preg_match('#^/bank[-_]transfer/v1(?:/|$)#', $route)) {
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

		if (!$order || !is_a($order, 'WC_Order')) {
			return false;
		}

		$order_public_key = $order->get_meta('_bank_transfer_public_key');
		if (empty($order_public_key)) {
			Bank_Transfer_Payment_Gateway_Logger::warning('Webhook rejected: order has no public key', ['order_id' => $order->get_id()]);
			return false;
		}

		$valid_secrets = [];

		foreach ($accounts_data as $account) {
			if (!is_array($account)) {
				continue;
			}
			$pub = $is_sandbox ? ($account['sandbox_public_key'] ?? '') : ($account['live_public_key'] ?? '');
			$sec = $is_sandbox ? ($account['sandbox_secret_key'] ?? '') : ($account['live_secret_key'] ?? '');
			$sec = trim(trim((string) $sec), '"\'');

			if (empty($sec)) {
				continue;
			}

			if ($pub === $order_public_key) {
				$valid_secrets = [$sec];
				break;
			}
		}

		if (empty($valid_secrets)) {
			Bank_Transfer_Payment_Gateway_Logger::warning('Webhook rejected: no matching account found for order public key', ['order_id' => $order->get_id()]);
			return false;
		}

		// BK-07: Mandatory HMAC signature verification with replay protection
		$sig_header       = (string) $request->get_header('x-signature');
		$timestamp_header = (string) $request->get_header('x-timestamp');

		if (empty($sig_header) || empty($timestamp_header)) {
			Bank_Transfer_Payment_Gateway_Logger::warning('Webhook rejected: missing HMAC signature headers');
			return false;
		}

		// X-Timestamp must be a strict unsigned digit string (rejects "123abc", signs, floats, exponents).
		$timestamp_header = trim($timestamp_header);
		if (!ctype_digit($timestamp_header) || strlen($timestamp_header) > 12) {
			Bank_Transfer_Payment_Gateway_Logger::warning('Webhook timestamp malformed');
			return false;
		}

		$timestamp_int = (int) $timestamp_header;
		if (abs(time() - $timestamp_int) > 300) {
			Bank_Transfer_Payment_Gateway_Logger::warning('Webhook timestamp expired', ['timestamp' => $timestamp_int]);
			return false;
		}

		// Replay protection within the 300-second window
		$replay_cache_key = 'bank_transfer_webhook_sig_' . md5($sig_header);
		if (get_transient($replay_cache_key) !== false) {
			Bank_Transfer_Payment_Gateway_Logger::warning('Webhook rejected: replay detected', ['signature' => substr($sig_header, 0, 10) . '...']);
			return false;
		}

		$raw_body = $request->get_body();
		foreach ($valid_secrets as $secret) {
			$expected_sig = hash_hmac('sha256', $timestamp_header . '.' . $raw_body, $secret);
			if (hash_equals($expected_sig, $sig_header)) {
				set_transient($replay_cache_key, 1, 300);
				return true;
			}
		}

		Bank_Transfer_Payment_Gateway_Logger::warning('Webhook HMAC signature mismatch');
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
		// The backend has used more than one field name for the same two values;
		// accept them all so a status push is never dropped on a rename. A bare
		// top-level "status" is deliberately ignored (it describes the call).
		$api_order_status = sanitize_text_field((string) (
			$data['order_status']
			?? ($data['payment_status']
			?? ($data['transaction_status']
			?? ($params['order_status']
			?? ($params['payment_status'] ?? ''))))
		));
		$api_order_status = strtolower(trim(trim($api_order_status), '"\''));
		if ($api_order_status === '' && isset($data['settled']) && filter_var($data['settled'], FILTER_VALIDATE_BOOLEAN)) {
			$api_order_status = 'success';
		}

		$pay_id = sanitize_text_field((string) (
			$data['pay_id']
			?? ($data['uuid']
			?? ($data['payment_token']
			?? ($params['pay_id']
			?? ($params['uuid'] ?? ''))))
		));
		$pay_id = trim(trim($pay_id), '"\'');

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

			// The customer is back from the payment portal. Confirm the payment with
			// the backend right now so the order-received page shows the settled
			// state even when the webhook has not arrived yet.
			if ($order->has_status(['pending', 'on-hold']) && class_exists('BANK_TRANSFER_PAYMENT_GATEWAY_Loader')) {
				BANK_TRANSFER_PAYMENT_GATEWAY_Loader::get_instance()->sync_single_order_status($order);
				$order = wc_get_order($order->get_id());
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

		$order = ($order_id > 0) ? wc_get_order($order_id) : false;
		if (
			$order_id <= 0 ||
			!$order ||
			$order->get_payment_method() !== 'bank_transfer' ||
			!$this->bank_transfer_verify_webhook($request, $order)
		) {
			return new WP_REST_Response([
				'success'    => false,
				'error_code' => 'INVALID_API_KEY',
				'message'    => 'Authentication failed.'
			], 401);
		}

		// BT-05: Mandatory check that pay_id matches a payment id this order is
		// known under (order meta, active id, or the stored payment link row).
		// An order with no recorded id at all (older order, create-link response
		// without pay_id) adopts the webhook's id: the request is authenticated
		// with the order's own account secret and amount/currency are verified below.
		if (empty($pay_id)) {
			Bank_Transfer_Payment_Gateway_Logger::warning("Webhook missing Pay ID for Order #{$order_id}");
			return new WP_REST_Response([
				'success' => false,
				'message' => 'Payment ID required.'
			], 400);
		}

		$loader    = class_exists('BANK_TRANSFER_PAYMENT_GATEWAY_Loader') ? BANK_TRANSFER_PAYMENT_GATEWAY_Loader::get_instance() : null;
		$known_ids = $loader
			? $loader->bank_transfer_known_order_pay_ids($order)
			: array_values(array_filter([(string) $order->get_meta('_bank_transfer_pay_id')]));

		$id_matches = false;
		foreach ($known_ids as $known_id) {
			if (hash_equals((string) $known_id, $pay_id)) {
				$id_matches = true;
				break;
			}
		}

		if (!$id_matches && !empty($known_ids)) {
			Bank_Transfer_Payment_Gateway_Logger::warning("Webhook Pay ID mismatch for Order #{$order_id}", [
				'stored'   => $known_ids,
				'received' => $pay_id,
			]);
			return new WP_REST_Response([
				'success' => false,
				'message' => 'Payment ID mismatch.'
			], 400);
		}

		if (empty($known_ids)) {
			Bank_Transfer_Payment_Gateway_Logger::info("Webhook supplied Pay ID for Order #{$order_id}, which had none recorded; adopting it.", [
				'received' => $pay_id,
			]);
			$order->update_meta_data('_bank_transfer_pay_id', $pay_id);
			$order->save();
		}


		// BT-05: Mandatory amount check
		$raw_amount = $data['amount'] ?? ($data['total_amount'] ?? null);
		if ($raw_amount === null || !is_numeric($raw_amount)) {
			return new WP_REST_Response([
				'success' => false,
				'message' => 'Amount required.'
			], 400);
		}

		$webhook_amount = floatval($raw_amount);
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

		// BT-05: Mandatory currency check
		$curr_code = $data['curr_code'] ?? ($data['currency'] ?? '');
		if (empty($curr_code)) {
			return new WP_REST_Response([
				'success' => false,
				'message' => 'Currency required.'
			], 400);
		}

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

		// -------------------------
		// 3. ENGINE CALL (WEBHOOK ONLY)
		// -------------------------
		$result = null;
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

		if (is_array($result) && ($result['reason'] ?? '') === 'invalid_transition_blocked') {
			return new WP_REST_Response([
				'success'       => false,
				'message'       => 'State transition refused.',
				'state'         => $state,
				'order_status'  => $order ? $order->get_status() : '',
				'engine_reason' => $result['reason'] ?? null,
			], 422);
		}

		$wc_status = $order->get_status();
		if (($wc_status === 'processing' || $wc_status === 'completed') && $state !== 'returned') {
			$state = 'success';
		}

		$is_success = ($state === 'success' || $state === 'returned');
		$message = match ($state) {
			'success'    => 'Payment confirmed successfully.',
			'returned'   => 'Payment returned or reversed.',
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

		// The resolved state and engine outcome are echoed so the RT backend's
		// sync log can show what the shop did with the webhook (it treats any
		// 2xx as acknowledged and records this answer beside it).
		return new WP_REST_Response([
			'success'       => $is_success,
			'message'       => $message,
			'state'         => $state,
			'order_status'  => $wc_status,
			'engine_reason' => is_array($result) ? ($result['reason'] ?? null) : null,
		], 200);
	}
}