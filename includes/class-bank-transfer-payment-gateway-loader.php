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
		add_action('wp_ajax_rt_bank_transfer_check_payment_status', array($this, 'bank_transfer_handle_check_payment_status_request'));
		add_action('wp_ajax_nopriv_rt_bank_transfer_check_payment_status', array($this, 'bank_transfer_handle_check_payment_status_request'));

		add_action('wp_ajax_rt_bank_transfer_popup_closed_event', array($this, 'handle_popup_close'));
		add_action('wp_ajax_nopriv_rt_bank_transfer_popup_closed_event', array($this, 'handle_popup_close'));

		add_action('wp_ajax_' . BANK_TRANSFER_GATEWAY_ID . '_manual_sync', [$this, 'bank_transfer_manual_sync_callback']);
		add_filter('cron_schedules', [$this, 'bank_transfer_add_cron_interval']);
		add_action(BANK_TRANSFER_CRON_HOOK, [$this, 'handle_cron_event']);

		// Order status sync. BK-14: nothing in the admin render path calls RT;
		// admin screens only queue the async hook below.
		add_action(BANK_TRANSFER_ASYNC_SYNC_HOOK, [$this, 'sync_single_order_status'], 10, 1);
		add_action('woocommerce_admin_order_data_after_order_details', [$this, 'bank_transfer_admin_order_sync_on_view'], 5);
		add_action('current_screen', [$this, 'bank_transfer_admin_orders_screen_sync']);
		add_filter('woocommerce_order_actions', [$this, 'bank_transfer_add_order_actions'], 10, 2);
		add_action('woocommerce_order_action_bank_transfer_check_status', [$this, 'bank_transfer_process_order_action_check_status']);
		add_action('woocommerce_before_thankyou', [$this, 'bank_transfer_customer_order_sync']);
		add_action('woocommerce_view_order', [$this, 'bank_transfer_customer_order_sync']);

		add_action('woocommerce_before_checkout_form', [$this, 'bank_transfer_show_checkout_error']);

		// BT-33: Prevent order reuse on standard checkout for this gateway if identity changes
		add_action('woocommerce_before_checkout_process', function() {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$payment_method = isset($_POST['payment_method']) ? sanitize_text_field(wp_unslash($_POST['payment_method'])) : '';
			if (BANK_TRANSFER_GATEWAY_ID === $payment_method) {
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
		if (!function_exists('WC') || !WC()->session) return;

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

	/**
	 * Retrieve merchant credentials associated with an order or active gateway settings.
	 * Used for read-only status lookups only; the webhook never falls back to
	 * another account (see the REST API class).
	 *
	 * @param WC_Order|null $order
	 * @return array
	 */
	private function get_order_account_credentials($order = null)
	{
		$account = bank_transfer_get_order_account($order);
		if (is_array($account)) {
			return $account;
		}

		$is_sandbox = bank_transfer_is_sandbox();

		// Fallback to first available account
		$accounts = bank_transfer_get_accounts();
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
	 * BT-15 / BK-17: Rate limiting helper for public AJAX actions, counted per
	 * WooCommerce session (else per client IP as WooCommerce resolves it).
	 */
	public function check_rate_limit($action, $max_requests = 15, $period = 60)
	{
		$subject = bank_transfer_rate_limit_subject();
		$transient_key = 'bt_rl_' . md5($action . '_' . $subject);
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
			'<a href="' . esc_url(admin_url('admin.php?page=wc-settings&tab=checkout&section=' . BANK_TRANSFER_GATEWAY_ID)) . '">' . esc_html__('Settings', 'bank-transfer-payment-gateway') . '</a>',
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
				'docs'    => '<a href="' . esc_url(apply_filters('bank_transfer_docs_url', BANK_TRANSFER_BASE_URL . '/docs')) . '" target="_blank">' . esc_html__('Documentation', 'bank-transfer-payment-gateway') . '</a>',
				'support' => '<a href="' . esc_url(apply_filters('bank_transfer_support_url', BANK_TRANSFER_BASE_URL . '/contact-us')) . '" target="_blank">' . esc_html__('Support', 'bank-transfer-payment-gateway') . '</a>',
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

	/* =========================================================
	 * CUSTOMER STATUS POLLING (classic checkout popup)
	 * ========================================================= */

	/**
	 * Load the order a public status request is about, after the nonce, order
	 * key and gateway checks (BT-06). Dies with a JSON error otherwise.
	 *
	 * @return WC_Order
	 */
	private function bank_transfer_authorized_order_from_request($log_label)
	{
		$order_id = isset($_POST['order_id']) ? intval(sanitize_text_field(wp_unslash($_POST['order_id']))) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$order_key = isset($_POST['order_key']) ? sanitize_text_field(wp_unslash($_POST['order_key'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		$order = ($order_id > 0) ? wc_get_order($order_id) : false;

		if (
			!$order ||
			!bank_transfer_is_gateway_order($order) ||
			empty($order_key) ||
			!hash_equals($order->get_order_key(), $order_key)
		) {
			Bank_Transfer_Payment_Gateway_Logger::info(
				"[Order #{$order_id}] {$log_label} | Security check failed (invalid key or non-bank-transfer order)"
			);

			wp_send_json_error([
				'reload'       => false,
				'message'      => 'Security check failed.',
				'stop_polling' => true
			], 403);
			wp_die();
		}

		return $order;
	}

	/**
	 * Handle the AJAX request for checking payment status.
	 */
	public function bank_transfer_handle_check_payment_status_request()
	{
		check_ajax_referer('bank_transfer_payment', 'security');
		$this->check_rate_limit('status_check', 30, 60);

		$order = $this->bank_transfer_authorized_order_from_request('CheckStatus');
		$this->bank_transfer_answer_status_poll($order, 'redirect_check', 'CheckStatus');
	}

	public function handle_popup_close()
	{
		check_ajax_referer('bank_transfer_payment', 'security');
		$this->check_rate_limit('popup_close', 30, 60);

		$order = $this->bank_transfer_authorized_order_from_request('PopupClose');
		$this->bank_transfer_answer_status_poll($order, 'popup_close', 'PopupClose');
	}

	/**
	 * Ask RT for the payment's status, push it through the shared validation
	 * and the engine, and answer the polling checkout page.
	 */
	private function bank_transfer_answer_status_poll($order, $event_type, $log_label)
	{
		$order_id   = $order->get_id();
		$log_prefix = "[Order #{$order_id}]";

		$outcome = $this->bank_transfer_refresh_order_from_remote($order, $event_type);

		if (!empty($outcome['error'])) {
			$http = (int) ($outcome['http_code'] ?? 0);
			$stop = in_array($http, [401, 403], true) || ($outcome['reason'] ?? '') === 'credentials';

			Bank_Transfer_Payment_Gateway_Logger::warning(
				"{$log_prefix} {$log_label} | " . $outcome['error']
			);

			wp_send_json_error([
				'reload'       => false,
				'stop_polling' => $stop,
				'message'      => $outcome['error'],
			], $http >= 400 && $http < 600 ? $http : 500);
			wp_die();
		}

		$payment_status = $outcome['state'];

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
		// ALWAYS RELOAD ORDER AFTER ENGINE
		// -------------------------
		$order = wc_get_order($order_id);

		$state = BANK_TRANSFER_PAYMENT_ENGINE::resolve_final_state($order, $payment_status);

		if ($order->get_meta('_bank_transfer_state') === 'success' || $order->has_status(['processing', 'completed'])) {
			$state = 'success';
		}

		$is_success = ($state === 'success');

		$message = match ($state) {
			'success'    => 'Your payment was completed successfully.',
			'failed'     => 'Payment failed. Please try again or use another method.',
			'cancelled'  => 'You cancelled the payment.',
			'expired'    => 'The payment session expired. Please try again.',
			'processing' => 'Your bank transfer has been authorised and is being processed.',
			default      => 'We couldn’t confirm your payment status yet. If needed, you can try placing the order again after checking your order status.'
		};

		// A settled payment and an authorised (in-flight) debit both send the
		// customer to the order-received page; a closed attempt goes back to checkout.
		$redirect = null;
		if ($state === 'success' || $state === 'processing') {
			$redirect = $order->get_checkout_order_received_url();
		} elseif (in_array($state, ['failed', 'cancelled', 'expired'], true)) {
			$redirect = wc_get_checkout_url();
		}

		$stop_polling = in_array($state, ['success', 'processing', 'failed', 'cancelled', 'expired'], true);

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
		$schedules[BANK_TRANSFER_CRON_INTERVAL] = array(
			'interval' => 2 * 60 * 60, // 2 hours in seconds = 7200
			'display'  => __('Every Two Hours (RT Bank Transfer)', 'bank-transfer-payment-gateway')
		);
		return $schedules;
	}

	function activate_cron_job()
	{
		Bank_Transfer_Payment_Gateway_Logger::info('Automatic payment status checks have been enabled.', ['source' => 'bank-transfer-payment-gateway']);

		// Clear existing scheduled event if it exists
		wp_clear_scheduled_hook(BANK_TRANSFER_CRON_HOOK);

		// Schedule with new interval
		wp_schedule_event(time(), BANK_TRANSFER_CRON_INTERVAL, BANK_TRANSFER_CRON_HOOK);
	}

	function deactivate_cron_job()
	{
		Bank_Transfer_Payment_Gateway_Logger::info('Automatic payment status checks have been disabled.', ['source' => 'bank-transfer-payment-gateway']);
		wp_clear_scheduled_hook(BANK_TRANSFER_CRON_HOOK);
		wp_clear_scheduled_hook(BANK_TRANSFER_ASYNC_SYNC_HOOK);
	}


	/**
	 * Refresh the configured accounts' statuses from RT and reconcile the
	 * orders still waiting for payment.
	 *
	 * BK-10: the secret keys never leave the shop. Each account proves its
	 * secret with a signature over "<timestamp>.<public_key>".
	 */
	public function handle_cron_event()
	{
		$logger_context = ['source' => 'bank-transfer-payment-gateway'];

		$accounts = bank_transfer_get_accounts();

		if (empty($accounts)) {
			Bank_Transfer_Payment_Gateway_Logger::warning('No payment accounts found or the account format is invalid. Sync aborted.', $logger_context);
			return [];
		}

		$accountsData = [];
		$updated      = false;

		foreach ($accounts as &$account) {
			$isSandboxEnabled = isset($account['has_sandbox']) && $account['has_sandbox'] === 'on';

			// Prepare both live and sandbox entries
			if (!empty($account['live_public_key']) && !empty($account['live_secret_key'])) {
				$accountsData[] = array_merge([
					'account_name' => $account['title'] ?? '',
					'public_key'   => $account['live_public_key'],
					'mode'         => 'live',
				], bank_transfer_account_signature($account['live_public_key'], $account['live_secret_key']));
			}

			if ($isSandboxEnabled) {
				if (!empty($account['sandbox_public_key']) && !empty($account['sandbox_secret_key'])) {
					$accountsData[] = array_merge([
						'account_name' => $account['title'] ?? '',
						'public_key'   => $account['sandbox_public_key'],
						'mode'         => 'sandbox',
					], bank_transfer_account_signature($account['sandbox_public_key'], $account['sandbox_secret_key']));
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
		unset($account);

		if (empty($accountsData)) {
			Bank_Transfer_Payment_Gateway_Logger::warning('No valid credentials found in any payment account. Sync skipped.', $logger_context);
			return [];
		}

		$url  = esc_url($this->get_api_url('/sync-account-status'));
		$body = wp_json_encode(['accounts' => array_slice($accountsData, 0, 10)]);

		$response = wp_remote_post($url, [
			'headers'   => [
				'Content-Type' => 'application/json',
				'Accept'       => 'application/json',
			],
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
							($account['live_public_key'] ?? '') === $statusData['public_key']
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
							($account['sandbox_public_key'] ?? '') === $statusData['public_key']
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
					unset($account);
				}
			}
		}

		if (!empty($statusSummary)) {
			if ($updated) {
				update_option(BANK_TRANSFER_ACCOUNTS_OPTION, $accounts);

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

	/**
	 * Tell RT the plugin's state (installed, enabled, versions). Sends public
	 * keys only. BK-17: nothing is sent before the merchant has entered keys.
	 */
	public function bank_transfer_send_plugin_status($plugin_status, $gateway_loaded)
	{
		if (!bank_transfer_is_configured()) {
			return;
		}

		$accounts = bank_transfer_get_accounts();

		if (empty($accounts)) {
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
	 * Resolve the RT payment id (pay_id / UUID) for an order.
	 *
	 * Order meta is the primary source. When it is empty (older orders, a
	 * create-link response without pay_id, or meta lost on a retry) fall back to
	 * the active pay id and to the payment link table, including the id
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
	 * Every payment id this order is known under: current meta, active id,
	 * the link table and the history of every link created for the order
	 * (BK-04: a webhook for an earlier link must still be recognised).
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

		$history = $order->get_meta('_bank_transfer_pay_id_history');
		if (is_array($history)) {
			foreach ($history as $entry) {
				$ids[] = sanitize_text_field((string) (is_array($entry) ? ($entry['pay_id'] ?? '') : $entry));
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
	 * Read the payment link row written by process_payment().
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

		$table_name = esc_sql(bank_transfer_table_name());

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$table_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table_name));
		if ($table_exists !== $table_name) {
			return null;
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
	 * Extract a pay id from an RT payment link
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
	 * Ask the RT backend for the current status of a payment:
	 * GET /payment-requests/public/{pay_id}/status, with the public key as
	 * bearer and X-Signature = HMAC-SHA256(secret_key, X-Timestamp . '.' . pay_id).
	 *
	 * BK-05: a lookup needs the stored pay_id. There is no lookup by order
	 * number alone, and the secret key is only ever used to sign.
	 *
	 * @param WC_Order $order
	 * @param string   $pay_id
	 * @param array    $creds  ['public_key' => , 'secret_key' => , 'is_sandbox' => ]
	 * @return array{response: array|WP_Error|null, data: array|null, http_code: int, endpoint: string}
	 *               'data' is the decoded payload when a usable status came back, else null.
	 */
	public function bank_transfer_fetch_remote_payment_status($order, $pay_id, $creds)
	{
		$public_key = (string) ($creds['public_key'] ?? '');
		$secret_key = (string) ($creds['secret_key'] ?? '');
		$order_id   = $order instanceof WC_Order ? $order->get_id() : 0;
		$pay_id     = sanitize_text_field((string) $pay_id);

		$result = ['response' => null, 'data' => null, 'http_code' => 0, 'endpoint' => 'payment-requests/status'];

		if ($public_key === '' || $secret_key === '') {
			$result['response'] = new WP_Error('bank_transfer_missing_credentials', 'Missing merchant account credentials.');
			return $result;
		}

		if ($pay_id === '') {
			$result['response'] = new WP_Error('bank_transfer_missing_pay_id', 'No payment id recorded for this order.');
			return $result;
		}

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
			(is_wp_error($response) ? $response->get_error_message() : 'HTTP ' . $result['http_code']) . ')',
			['source' => 'bank-transfer-payment-gateway']
		);

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
	 * The request-level payment_status decides: "success" only once the ACH
	 * debit completed (funds settled, BK-02); "pending" while the link is open.
	 * A pending request whose transaction is "processing" means the customer
	 * authorised the debit and it is in flight.
	 *
	 * @param array|null $data
	 * @return string|null 'success' | 'failed' | 'cancelled' | 'expired' | 'processing' | 'pending' | 'returned' | null
	 */
	public function bank_transfer_normalize_remote_status($data)
	{
		$data = $this->bank_transfer_unwrap_status_payload($data);
		if (!is_array($data)) {
			return null;
		}

		$payment_status = $data['payment_status'] ?? null;
		$txn_status     = $data['transaction_status'] ?? ($data['transaction']['status'] ?? null);

		$settled = isset($data['settled']) && filter_var($data['settled'], FILTER_VALIDATE_BOOLEAN);
		if ($settled) {
			return 'success';
		}

		$mapped = is_string($payment_status) ? BANK_TRANSFER_PAYMENT_ENGINE::map_status($payment_status) : null;
		$txn    = is_string($txn_status) ? BANK_TRANSFER_PAYMENT_ENGINE::map_status($txn_status) : null;

		if ($mapped === 'pending' && $txn === 'processing') {
			return 'processing';
		}
		if ($mapped !== null) {
			return $mapped;
		}

		$failed = isset($data['failed']) && filter_var($data['failed'], FILTER_VALIDATE_BOOLEAN);
		if ($failed) {
			return 'failed';
		}

		return $txn;
	}

	/* =========================================================
	 * ORDER STATUS SYNC
	 * ========================================================= */

	/**
	 * Ask RT for an order's status and apply it to the order through the one
	 * shared validation (BK-05) and the state engine. Used by the customer
	 * status polls, the customer return, the admin action and the cron.
	 *
	 * @param WC_Order $order
	 * @param string   $event_type Engine event type ('api_sync', 'popup_close', 'redirect_check').
	 * @return array{state: string|null, error: string|null, http_code: int, reason: string|null}
	 */
	public function bank_transfer_refresh_order_from_remote($order, $event_type = 'api_sync')
	{
		$order_id   = $order->get_id();
		$log_prefix = "[Order #{$order_id}] Sync |";
		$ctx        = ['source' => 'bank-transfer-payment-gateway'];
		$out        = ['state' => null, 'error' => null, 'http_code' => 0, 'reason' => null];

		$pay_id = $this->bank_transfer_resolve_order_pay_id($order);
		$creds  = $this->get_order_account_credentials($order);

		if (empty($creds['public_key']) || empty($creds['secret_key'])) {
			Bank_Transfer_Payment_Gateway_Logger::warning("{$log_prefix} Missing API credentials for merchant account", $ctx);
			$out['error']  = 'Missing merchant account credentials.';
			$out['reason'] = 'credentials';
			$out['http_code'] = 400;
			return $out;
		}

		if ($pay_id === '') {
			Bank_Transfer_Payment_Gateway_Logger::warning("{$log_prefix} No payment id recorded; status cannot be verified", $ctx);
			$out['error']  = 'No payment id recorded for this order.';
			$out['reason'] = 'no_pay_id';
			$out['http_code'] = 400;
			return $out;
		}

		$lookup = $this->bank_transfer_fetch_remote_payment_status($order, $pay_id, $creds);
		$out['http_code'] = (int) $lookup['http_code'];

		if (is_wp_error($lookup['response'])) {
			$out['error']  = 'API connection failed.';
			$out['reason'] = 'network';
			return $out;
		}

		if ($out['http_code'] === 401 || $out['http_code'] === 403) {
			$out['error']  = 'API authentication failed. Please verify merchant credentials.';
			$out['reason'] = 'auth';
			return $out;
		}

		if ($out['http_code'] === 429) {
			$out['error']  = 'Rate limit exceeded. Please wait a moment.';
			$out['reason'] = 'rate_limit';
			return $out;
		}

		if (!is_array($lookup['data'])) {
			Bank_Transfer_Payment_Gateway_Logger::info("{$log_prefix} No usable status from backend (HTTP {$out['http_code']})", $ctx);
			if ($out['http_code'] < 200 || $out['http_code'] >= 300) {
				$out['error']  = 'Unable to check transaction status at this time.';
				$out['reason'] = 'upstream';
			}
			return $out;
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

		$out['state'] = $state;

		if ($state === null) {
			return $out;
		}

		if ($state === 'success') {
			// The backend confirms money arrived. Before touching the order make sure
			// the payment really belongs to it: the same check the webhook performs.
			$claim = bank_transfer_verify_payment_claim($order, $status_data, $pay_id, $this->bank_transfer_known_order_pay_ids($order));
			if (is_wp_error($claim)) {
				$this->bank_transfer_note_sync_mismatch($order, $claim->get_error_message());
				$out['state']  = null;
				$out['reason'] = 'claim_mismatch';
				return $out;
			}
		}

		if ($state === 'returned') {
			// Only a signed RT webhook may move a paid order to returned (BK-01).
			return $out;
		}

		$result = BANK_TRANSFER_PAYMENT_ENGINE::handle_event(
			$order_id,
			$event_type,
			[
				'status'        => $state,
				'payment_token' => $pay_id,
				'source'        => 'API Sync',
			]
		);

		Bank_Transfer_Payment_Gateway_Logger::info("{$log_prefix} Engine result: " . wp_json_encode($result), $ctx);

		return $out;
	}

	/**
	 * Sync every open Bank Transfer order with the remote payment API, in
	 * pages, within a time budget (BK-17: not just the newest 20).
	 *
	 * @param int $max_orders Upper bound of orders checked in this run.
	 * @param int $time_budget Seconds this run may spend.
	 * @return array Summary of synced order states keyed by order id.
	 */
	public function sync_pending_orders($max_orders = 300, $time_budget = 20)
	{
		if (!function_exists('wc_get_orders')) {
			return [];
		}

		$started   = microtime(true);
		$page_size = 25;
		$synced    = [];
		$seen      = 0;

		foreach (bank_transfer_gateway_ids() as $gateway_id) {
			$page = 1;
			while ($seen < $max_orders && (microtime(true) - $started) < $time_budget) {
				$orders = wc_get_orders([
					'status'         => ['pending', 'on-hold'],
					'payment_method' => $gateway_id,
					'limit'          => $page_size,
					'page'           => $page,
					'orderby'        => 'date',
					'order'          => 'ASC',
					'date_created'   => '>' . (time() - 30 * DAY_IN_SECONDS),
				]);

				if (empty($orders) || !is_array($orders)) {
					break;
				}

				foreach ($orders as $order) {
					if (!$order instanceof WC_Order) {
						continue;
					}
					$seen++;
					if ($seen > $max_orders || (microtime(true) - $started) >= $time_budget) {
						break 2;
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

				if (count($orders) < $page_size) {
					break;
				}
				$page++;
			}
		}

		return $synced;
	}

	/**
	 * Sync a single Bank Transfer order with the remote payment API and push the
	 * outcome through the state engine.
	 *
	 * @param WC_Order|int $order Order instance or order ID.
	 * @return string|null 'success', 'failed', 'cancelled', 'expired', 'processing', 'pending' or null when nothing usable came back.
	 */
	public function sync_single_order_status($order)
	{
		if (is_numeric($order)) {
			$order = wc_get_order(absint($order));
		}

		if (!$order instanceof WC_Order || !bank_transfer_is_gateway_order($order)) {
			return null;
		}

		if ($order->has_status(['processing', 'completed']) || $order->get_meta('_bank_transfer_state') === 'success') {
			return 'success';
		}

		if ($order->has_status([BANK_TRANSFER_RETURNED_STATUS])) {
			return 'returned';
		}

		$outcome = $this->bank_transfer_refresh_order_from_remote($order, 'api_sync');

		return $outcome['state'] ?? null;
	}

	/**
	 * Record (once) why a backend "paid" status was not applied to the order.
	 */
	private function bank_transfer_note_sync_mismatch($order, $detail)
	{
		Bank_Transfer_Payment_Gateway_Logger::warning(
			'[Order #' . $order->get_id() . '] Sync | Backend reports paid but ' . $detail . ' Order left unchanged for manual review.',
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
	 * BK-14: queue a status refresh for an order instead of calling RT while
	 * an admin page renders. Action Scheduler when WooCommerce provides it,
	 * else a WP-Cron single event (WordPress de-duplicates identical events).
	 *
	 * @param int $order_id
	 */
	public function bank_transfer_schedule_async_sync($order_id)
	{
		$order_id = absint($order_id);
		if ($order_id <= 0) {
			return;
		}
		if (function_exists('as_enqueue_async_action')) {
			if (function_exists('as_has_scheduled_action') && as_has_scheduled_action(BANK_TRANSFER_ASYNC_SYNC_HOOK, [$order_id], 'rt-bank-transfer')) {
				return;
			}
			as_enqueue_async_action(BANK_TRANSFER_ASYNC_SYNC_HOOK, [$order_id], 'rt-bank-transfer');
			return;
		}
		if (!wp_next_scheduled(BANK_TRANSFER_ASYNC_SYNC_HOOK, [$order_id])) {
			wp_schedule_single_event(time(), BANK_TRANSFER_ASYNC_SYNC_HOOK, [$order_id]);
		}
	}

	/**
	 * Queue a refresh when an admin opens a pending order's edit screen.
	 *
	 * @param WC_Order $order
	 */
	public function bank_transfer_admin_order_sync_on_view($order)
	{
		if (!$order instanceof WC_Order || !bank_transfer_is_gateway_order($order)) {
			return;
		}
		if (!$order->has_status(['pending', 'on-hold'])) {
			return;
		}
		$last_sync = (int) $order->get_meta('_bank_transfer_last_admin_sync');
		if (time() - $last_sync < 60) {
			return;
		}
		$order->update_meta_data('_bank_transfer_last_admin_sync', time());
		$order->save();

		$this->bank_transfer_schedule_async_sync($order->get_id());
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
		if ($order instanceof WC_Order && bank_transfer_is_gateway_order($order)) {
			$actions['bank_transfer_check_status'] = __('Check Bank Transfer payment status', 'bank-transfer-payment-gateway');
		}
		return $actions;
	}

	/**
	 * Handle the manual "check status" order action (admin-initiated, so this
	 * one call to RT is synchronous).
	 *
	 * @param WC_Order $order
	 */
	public function bank_transfer_process_order_action_check_status($order)
	{
		if (!$order instanceof WC_Order || !bank_transfer_is_gateway_order($order)) {
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
	 * Queue a refresh of the open orders when an admin loads the Orders list
	 * (throttled; nothing is fetched during the render).
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
		$edit_id   = isset($_GET['id']) ? absint($_GET['id']) : (isset($_GET['post']) ? absint($_GET['post']) : 0);
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
		if (time() - $last_screen_sync < 60) {
			return;
		}
		set_transient('bank_transfer_last_orders_screen_sync', time(), 120);

		foreach (bank_transfer_gateway_ids() as $gateway_id) {
			$orders = wc_get_orders([
				'status'         => ['pending', 'on-hold'],
				'payment_method' => $gateway_id,
				'limit'          => 20,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'return'         => 'ids',
			]);
			foreach ((array) $orders as $order_id) {
				$this->bank_transfer_schedule_async_sync($order_id);
			}
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
		if (!$order instanceof WC_Order || !bank_transfer_is_gateway_order($order)) {
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
