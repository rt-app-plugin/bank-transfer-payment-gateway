<?php
if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly.
}

require_once plugin_dir_path(__FILE__) . 'config.php';
require_once plugin_dir_path(__FILE__) . 'bank-transfer-payment-gateway-utils.php';
require_once plugin_dir_path(__FILE__) . 'class-bank-transfer-payment-state-engine.php';

class BANK_TRANSFER_PAYMENT_GATEWAY_REST_API
{
	/** Webhook timestamps older than this are refused (seconds). */
	const WEBHOOK_MAX_SKEW = 300;

	/** How long a seen signature is remembered for replay detection (seconds). */
	const WEBHOOK_REPLAY_TTL = 900;

	/**
	 * Namespaces the webhook / return route answers on. The first is the one
	 * new payment links are issued with (BK-13); the others are the pre-1.0.2
	 * names kept so links already handed to RT keep working.
	 */
	const NAMESPACES = [BANK_TRANSFER_REST_NAMESPACE, 'bank-transfer/v1', 'bank_transfer/v1'];

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

		// BT-13: Only add CORS headers for this plugin's routes without breaking global WordPress REST handling.
		// Match strictly on the resolved REST route, anchored to our namespaces. Never fall back to REQUEST_URI:
		// a substring match on it lets /wp-json/anything?x=bank-transfer/v1 receive our headers and the OPTIONS exit.
		add_filter('rest_pre_serve_request', function ($value, $result, $request) {
			$route = ($request instanceof WP_REST_Request) ? (string) $request->get_route() : '';

			if ($route !== '' && preg_match('#^/(rt-bank-transfer|bank[-_]transfer)/v1(?:/|$)#', $route)) {
				header('Access-Control-Allow-Origin: ' . BANK_TRANSFER_BASE_URL);
				header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
				header('Access-Control-Allow-Headers: Authorization, Content-Type, X-WP-Nonce, X-Signature, X-Timestamp, User-Agent, Accept');

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
			foreach (self::NAMESPACES as $namespace) {
				register_rest_route($namespace, '/data', array(
					'methods'             => ['GET', 'POST'],
					'callback'            => array($this, 'bank_transfer_handle_api_request'),
					'permission_callback' => '__return_true',
				));
			}
		});
	}

	/**
	 * BK-07: a webhook is accepted only with a valid HMAC-SHA256 signature of
	 * "<X-Timestamp>.<raw body>" made with the secret key of the account that
	 * created the order's payment, with the timestamp within WEBHOOK_MAX_SKEW
	 * and the signature not seen before. No other credential is accepted: a
	 * raw secret in a header, bearer or query string is refused.
	 *
	 * @return true|WP_Error
	 */
	private function bank_transfer_verify_webhook(WP_REST_Request $request, $order)
	{
		if (!$order || !is_a($order, 'WC_Order')) {
			return new WP_Error('no_order', 'Authentication failed.');
		}

		$account = bank_transfer_get_order_account($order);
		if ($account === null || empty($account['secret_key'])) {
			Bank_Transfer_Payment_Gateway_Logger::warning('Webhook rejected: no configured account matches the order public key', ['order_id' => $order->get_id()]);
			return new WP_Error('no_account', 'Authentication failed.');
		}

		// The order was created under one mode (live or sandbox); its public key
		// only exists in that mode's key pair, so the mode is fixed by the order,
		// not by the current sandbox setting (BK-18).
		$secret = trim(trim((string) $account['secret_key']), '"\'');

		$sig_header       = trim((string) $request->get_header('x-signature'));
		$timestamp_header = trim((string) $request->get_header('x-timestamp'));

		if ($sig_header === '' || $timestamp_header === '') {
			Bank_Transfer_Payment_Gateway_Logger::warning('Webhook rejected: X-Signature / X-Timestamp headers required', ['order_id' => $order->get_id()]);
			return new WP_Error('signature_required', 'Authentication failed.');
		}

		// X-Timestamp must be a strict unsigned digit string (rejects "123abc", signs, floats, exponents).
		if (!ctype_digit($timestamp_header) || strlen($timestamp_header) > 12) {
			Bank_Transfer_Payment_Gateway_Logger::warning('Webhook timestamp malformed');
			return new WP_Error('timestamp_malformed', 'Authentication failed.');
		}

		$timestamp_int = (int) $timestamp_header;
		if (abs(time() - $timestamp_int) > self::WEBHOOK_MAX_SKEW) {
			Bank_Transfer_Payment_Gateway_Logger::warning('Webhook timestamp expired', ['timestamp' => $timestamp_int]);
			return new WP_Error('timestamp_expired', 'Authentication failed.');
		}

		$raw_body     = $request->get_body();
		$expected_sig = hash_hmac('sha256', $timestamp_header . '.' . $raw_body, $secret);
		if (!hash_equals($expected_sig, strtolower($sig_header))) {
			Bank_Transfer_Payment_Gateway_Logger::warning('Webhook HMAC signature mismatch', ['order_id' => $order->get_id()]);
			return new WP_Error('signature_mismatch', 'Authentication failed.');
		}

		// Replay store: the same signature is only ever accepted once.
		$replay_key = 'bt_wh_' . substr(hash('sha256', $order->get_id() . '|' . $sig_header), 0, 40);
		if (get_transient($replay_key) !== false) {
			Bank_Transfer_Payment_Gateway_Logger::warning('Webhook replay ignored', ['order_id' => $order->get_id()]);
			return new WP_Error('replay', 'Duplicate delivery.');
		}
		set_transient($replay_key, 1, self::WEBHOOK_REPLAY_TTL);

		return true;
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
		$log_context = ['source' => 'bank-transfer-payment-gateway'];

		// -------------------------
		// 1. GET (RETURN / REDIRECT ONLY) - BT-01 & BT-02
		// -------------------------
		if ($method === 'GET') {
			$params       = $request->get_query_params();
			$order_id     = intval($params['order_id'] ?? 0);
			$provided_key = sanitize_text_field($params['key'] ?? ($params['order_key'] ?? ''));
			$order        = ($order_id > 0) ? wc_get_order($order_id) : false;

			Bank_Transfer_Payment_Gateway_Logger::info(
				"Bank Transfer API HIT | Method: {$method} | Order #{$order_id}",
				$log_context
			);

			// Verify order existence, order key, and payment method uniformly to prevent ID enumeration
			if (
				$order_id <= 0 ||
				!$order ||
				empty($provided_key) ||
				!hash_equals($order->get_order_key(), $provided_key) ||
				!bank_transfer_is_gateway_order($order)
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

		// Only the signed body is read. Query-string and form parameters are
		// never merged in: they are not covered by the signature.
		$raw_body = $request->get_body();
		$body     = json_decode($raw_body, true);
		if (!is_array($body)) {
			return new WP_REST_Response([
				'success' => false,
				'message' => 'Invalid payload'
			], 400);
		}

		$data = isset($body['api_data']) && is_array($body['api_data']) ? $body['api_data'] : $body;

		$order_id = intval($data['order_id'] ?? 0);
		// The backend has used more than one field name for the same two values;
		// accept them all so a status push is never dropped on a rename. A bare
		// top-level "status" is deliberately ignored (it describes the call).
		$api_order_status = sanitize_text_field((string) (
			$data['order_status']
			?? ($data['payment_status']
			?? ($data['transaction_status'] ?? ''))
		));
		$api_order_status = strtolower(trim(trim($api_order_status), '"\''));
		if ($api_order_status === '' && isset($data['settled']) && filter_var($data['settled'], FILTER_VALIDATE_BOOLEAN)) {
			$api_order_status = 'success';
		}

		$pay_id = sanitize_text_field((string) (
			$data['pay_id']
			?? ($data['uuid']
			?? ($data['payment_token'] ?? ''))
		));
		$pay_id = trim(trim($pay_id), '"\'');

		Bank_Transfer_Payment_Gateway_Logger::info(
			"Bank Transfer API HIT | Method: {$method} | Order #{$order_id} | Status: {$api_order_status} | Pay ID: {$pay_id}",
			$log_context
		);

		$order = ($order_id > 0) ? wc_get_order($order_id) : false;
		if ($order_id <= 0 || !$order || !bank_transfer_is_gateway_order($order)) {
			return new WP_REST_Response([
				'success'    => false,
				'error_code' => 'INVALID_API_KEY',
				'message'    => 'Authentication failed.'
			], 401);
		}

		$verified = $this->bank_transfer_verify_webhook($request, $order);
		if (is_wp_error($verified)) {
			if ($verified->get_error_code() === 'replay') {
				// Already processed once; acknowledge without acting on it.
				return new WP_REST_Response([
					'success' => false,
					'message' => 'Duplicate delivery ignored.',
					'state'   => BANK_TRANSFER_PAYMENT_ENGINE::resolve_final_state($order, $api_order_status),
				], 200);
			}
			return new WP_REST_Response([
				'success'    => false,
				'error_code' => 'INVALID_API_KEY',
				'message'    => 'Authentication failed.'
			], 401);
		}

		// -------------------------
		// 3. BK-05: ONE STRICT CHECK (payment id, exact amount, currency, order)
		// -------------------------
		$loader    = class_exists('BANK_TRANSFER_PAYMENT_GATEWAY_Loader') ? BANK_TRANSFER_PAYMENT_GATEWAY_Loader::get_instance() : null;
		$known_ids = $loader
			? $loader->bank_transfer_known_order_pay_ids($order)
			: array_values(array_filter([(string) $order->get_meta('_bank_transfer_pay_id')]));

		$claim = bank_transfer_verify_payment_claim($order, $data, $pay_id, $known_ids);
		if (is_wp_error($claim)) {
			Bank_Transfer_Payment_Gateway_Logger::warning("Webhook refused for Order #{$order_id}: " . $claim->get_error_message(), [
				'code'     => $claim->get_error_code(),
				'received' => $pay_id,
			]);
			return new WP_REST_Response([
				'success' => false,
				'message' => $claim->get_error_message(),
			], 400);
		}

		if ($api_order_status === '') {
			return new WP_REST_Response([
				'success' => false,
				'message' => 'Status required.'
			], 422);
		}

		// -------------------------
		// 4. ENGINE CALL (WEBHOOK ONLY)
		// -------------------------
		$result = BANK_TRANSFER_PAYMENT_ENGINE::handle_event(
			$order_id,
			'webhook_update',
			[
				'status'         => $api_order_status,
				'payment_token'  => $pay_id,
				'source'         => 'Webhook',
				'return_code'    => sanitize_text_field((string) ($data['return_code'] ?? '')),
				'failure_reason' => sanitize_text_field((string) ($data['failure_reason'] ?? '')),
			]
		);

		Bank_Transfer_Payment_Gateway_Logger::info(
			"Bank Transfer ENGINE RESULT | Order #{$order_id} | " . wp_json_encode($result),
			$log_context
		);

		$reason = is_array($result) ? ($result['reason'] ?? null) : null;

		// BT-39: Return 423 Locked when lock cannot be acquired so API retries
		if ($reason === 'locked_skip') {
			return new WP_REST_Response([
				'success' => false,
				'message' => 'Order is currently locked, please retry.'
			], 423);
		}

		// -------------------------
		// 5. RESOLVE FINAL STATE
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
			'returned'   => 'Payment was returned by the bank.',
			default      => 'Payment status is being verified.'
		};

		// BK-17: a webhook the shop did not act on is not acknowledged with 200.
		// 422: the status word is unknown to this plugin. 409: the transition is
		// not allowed from the order's current state. RT records the answer.
		$http_code = match ($reason) {
			'no_state'                   => 422,
			'invalid_transition_blocked' => 409,
			default                      => ($result === false ? 400 : 200)
		};

		Bank_Transfer_Payment_Gateway_Logger::info(
			"Bank Transfer FINAL RESPONSE | Order #{$order_id} | State: {$state} | WC: {$wc_status} | HTTP: {$http_code}",
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
			'engine_reason' => $reason,
		], $http_code);
	}
}
