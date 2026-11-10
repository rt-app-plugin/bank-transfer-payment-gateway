=== RT Bank Transfer Payment Gateway ===
Contributors: rtapp
Tags: woocommerce, payment gateway, ach, bank transfer
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 8.0
WC requires at least: 6.5.4
WC tested up to: 11.0
Stable tag: 1.0.2
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Accept ACH bank transfer payments in WooCommerce through RT's hosted payment page.

== Description ==

RT Bank Transfer Payment Gateway adds a "Pay by bank transfer (ACH)" option to your WooCommerce checkout. The customer is taken to RT's secure payment page (pay.rt.app), connects their US bank account and authorises the debit. The order is placed on hold while the transfer is in flight (1 to 5 business days) and marked paid only when RT confirms the funds have settled. If the bank later returns the payment, the order is moved to "Payment returned" and you are notified by e-mail.

Supports the classic and block checkouts, WooCommerce High-Performance Order Storage, multiple RT merchant accounts with priority routing, and a sandbox mode for testing.

You need an RT merchant account to use this plugin. Get your API keys from your RT merchant dashboard under Developer Settings > API Keys.

== External Services ==

This plugin connects to RT's payment service, operated by RT.app at https://pay.rt.app, to create payment sessions, check payment status and keep your merchant account status in sync. Nothing is sent until you have saved your API keys in the plugin settings.

What is sent, and when:

* `https://pay.rt.app/payment-api/request-payment` - when a customer places an order with this gateway. Sends the order number, order total and currency, the customer's name, e-mail address, phone number and billing address, the customer's IP address, the WooCommerce customer ID (0 for guests), the return URL of your shop, your account's public API key and the plugin version. The request is signed with your secret API key; the secret itself is not sent.
* `https://pay.rt.app/payment-api/dailylimit` - when the checkout page is shown and when an order is placed, to check that the order total is within your account's daily limit. Sends the amount and your public API key, signed with the secret key.
* `https://pay.rt.app/payment-api/payment-requests/public/{payment id}/status` - when the customer returns from the payment page, while the checkout page is waiting for the payment, when you open an order in the admin, and every two hours for orders still awaiting payment. Sends the payment ID and your public API key, signed with the secret key.
* `https://pay.rt.app/payment-api/cancel-order-link` - when an order with an unstarted payment is cancelled in WooCommerce. Sends the order number and payment ID, signed with the secret key.
* `https://pay.rt.app/payment-api/sync-account-status` - every two hours and when you press "Sync Accounts" in the settings, to check that your API keys are valid. Sends each account's public key with a signature made with its secret key.
* `https://pay.rt.app/payment-api/plugin/check/plugin` - when the plugin is activated, deactivated, its settings are saved, and after each account sync. Sends your account titles and public keys, your shop's domain name, and the plugin, WordPress and WooCommerce versions.

RT sends payment status updates back to your shop at `/wp-json/rt-bank-transfer/v1/data`, signed with your secret API key. Updates without a valid signature are rejected.

Your secret API keys are stored in your WordPress database and are only ever used to sign requests; they are never transmitted to RT or shown in the browser. Customer bank account details are entered on RT's payment page and never pass through your shop.

* Terms of Service: https://pay.rt.app/terms
* Privacy Policy: https://pay.rt.app/privacy

== Installation ==

1. Download the plugin ZIP file.
2. In WordPress go to Plugins > Add New > Upload Plugin and upload the ZIP, or extract it into `wp-content/plugins`.
3. Activate the plugin through the 'Plugins' menu in WordPress.
4. Go to WooCommerce > Settings > Payments > RT Bank Transfer Payment Gateway, enable the gateway and add at least one account with its live public and secret keys.
5. Press "Sync Accounts" to confirm the keys are valid.

== Frequently Asked Questions ==

= How do I obtain API keys? =

Log in to your RT merchant dashboard and go to Developer Settings > API Keys.

= Why is my order "On hold"? =

The customer authorised the bank transfer and the debit is in flight. ACH transfers take 1 to 5 business days to settle. The order moves to Processing (or Completed, as configured) when RT confirms the funds arrived.

= What does "Payment returned" mean? =

The customer's bank returned the settled payment (for example, insufficient funds or an unauthorised-debit claim). The funds are no longer in your account. The order note shows the bank's return code; review the order before fulfilling it.

= Can I test without taking real payments? =

Yes. Enable sandbox mode and enter your sandbox keys. On a site whose `WP_ENVIRONMENT_TYPE` is `production`, sandbox-paid orders are placed on hold with a note instead of being marked paid, so test orders are never fulfilled by mistake.

== Changelog ==

= 1.0.2 =
* Security: webhooks now require a valid HMAC signature; a raw secret key is no longer accepted as a credential, and replayed deliveries are ignored.
* Security: the merchant secret key is no longer sent to RT in request bodies; every request is signed instead.
* Security: a guest checkout no longer links the order to a registered customer who happens to share the typed e-mail address.
* ACH returns and reversals of settled payments move the order to a new "Payment returned" status and e-mail the shop admin.
* Orders are placed on hold while the ACH debit is in flight and are no longer auto-cancelled or cancellable by the customer during that time.
* A new payment link is refused while a debit is in flight; every payment ID issued for an order is remembered, and a duplicate settled payment is flagged for refund instead of applied.
* One strict check of payment ID, exact amount, currency and order for every status path (webhook, customer return, status polling, scheduled sync).
* Block checkout now goes through WooCommerce's Store API, so all WooCommerce validation and extensions run.
* Removed the import of ByteNFT / Unified plugin credentials and settings.
* The checkout script no longer rewrites billing names or city for other gateways.
* The plugin uses its own database table and `rt_`-prefixed gateway, REST and cron identifiers.
* Admin order screens no longer call RT while rendering; status refreshes are queued in the background.
* Scheduled sync covers every open order (paged), not only the newest 20.
* Corrected the default checkout title and description, the readme, plugin headers and the external-services disclosure.

= 1.0.1 =
* Accept expired and cancelled webhooks and reset the payment state when a new link is created.
* Fixed order status updates for RT's webhook payload.

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.0.2 =
Security release. Requires the RT payment service update that accepts signed requests (deployed by RT). After updating, open the gateway settings once and press "Sync Accounts". Orders placed before the update keep working; new payment links use the `rt-bank-transfer/v1` return route.

== Support ==

For support, visit: https://pay.rt.app/contact-us
