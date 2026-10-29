<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

// config.php
if (!defined('BANK_TRANSFER_PROTOCOL')) {
    define('BANK_TRANSFER_PROTOCOL', 'https://');
}

if (!defined('BANK_TRANSFER_HOST')) {
    define('BANK_TRANSFER_HOST', 'qa.rt.app');
}

if (!defined('BANK_TRANSFER_BASE_URL')) {
	define('BANK_TRANSFER_BASE_URL', BANK_TRANSFER_PROTOCOL . BANK_TRANSFER_HOST);
}

if (!defined('BANK_TRANSFER_API_BASE_URL')) {
	define('BANK_TRANSFER_API_BASE_URL', BANK_TRANSFER_BASE_URL . '/payment-api');
}


if (!defined('BANK_TRANSFER_PLUGIN_VERSION')) {
    define('BANK_TRANSFER_PLUGIN_VERSION', '0.1.0');
}
