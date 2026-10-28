<?php

if (!defined('ABSPATH')) {
	exit;
}

class Bank_Transfer_Payment_Gateway_Logger {

	private static function get_logger()
	{
		if (!function_exists('wc_get_logger')) {
			return null;
		}

		return wc_get_logger();
	}

	private static function is_debug_enabled(): bool
	{
		$settings = get_option('woocommerce_bank_transfer_settings', []);
		return is_array($settings) && isset($settings['debug']) && 'yes' === $settings['debug'];
	}

	private static function mask_email($email)
	{
		if (!is_string($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
			return '***';
		}
		$parts  = explode('@', $email);
		$name   = $parts[0];
		$domain = $parts[1] ?? '';
		$len    = strlen($name);

		if ($len <= 2) {
			$masked_name = $name[0] . '***';
		} else {
			$masked_name = substr($name, 0, 2) . '***' . substr($name, -1);
		}

		return $masked_name . '@' . $domain;
	}

	private static function mask_phone($phone)
	{
		if (!is_string($phone)) {
			return '***';
		}
		$clean = preg_replace('/[^\d+]/', '', $phone);
		if (strlen($clean) <= 4) {
			return '***';
		}
		return substr($clean, 0, 3) . '***' . substr($clean, -2);
	}

	private static function sanitize_context($data)
	{
		if (!is_array($data)) {
			return $data;
		}

		$clean = [];
		foreach ($data as $key => $value) {
			$normalized_key = strtolower(str_replace(['_', '-'], '', (string) $key));

			if (in_array($normalized_key, [
				'publickey',
				'livepublickey',
				'sandboxpublickey',
				'apipublickey',
				'secretkey',
				'livesecretkey',
				'sandboxsecretkey',
				'apisecretkey',
				'apisecret',
				'trace',
				'billingaddress1',
				'billingaddress2',
				'address1',
				'address2',
				'address',
				'billingaddress',
			], true)) {
				continue;
			}

			if (in_array($normalized_key, ['email', 'customeremail', 'billingemail'], true)) {
				$clean[$key] = self::mask_email($value);
				continue;
			}

			if (in_array($normalized_key, ['phone', 'phonenumber', 'billingphone', 'normalized'], true)) {
				$clean[$key] = is_array($value) ? '***' : self::mask_phone($value);
				continue;
			}

			if (in_array($normalized_key, ['paymentlink', 'redirect', 'url'], true)) {
				$clean[$key] = '[REDACTED_URL]';
				continue;
			}

			if (in_array($normalized_key, ['postcode', 'billingpostcode'], true)) {
				$clean[$key] = '[REDACTED_POSTCODE]';
				continue;
			}

			$clean[$key] = is_array($value) ? self::sanitize_context($value) : $value;
		}

		return $clean;
	}

	private static function format_context($context)
	{
		$entry = [
			'source' => 'bank-transfer-payment-gateway'
		];

		if (!is_array($context)) {
			return $entry;
		}

		$sanitized = self::sanitize_context($context);

		foreach ($sanitized as $key => $value) {
			$entry[$key] = is_scalar($value)
				? $value
				: wp_json_encode($value);
		}

		return $entry;
	}

	public static function info($message, $context = [])
	{
		if (!self::is_debug_enabled()) {
			return;
		}

		$logger = self::get_logger();
		if (!$logger) return;

		$logger->info($message, self::format_context($context));
	}

	public static function warning($message, $context = [])
	{
		$logger = self::get_logger();
		if (!$logger) return;

		$logger->warning($message, self::format_context($context));
	}

	public static function error($message, $context = [])
	{
		$logger = self::get_logger();
		if (!$logger) return;

		$logger->error($message, self::format_context($context));
	}
}