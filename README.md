# RT Bank Transfer Payment Gateway

Accept ACH bank transfer payments in WooCommerce through RT's hosted payment page (https://pay.rt.app).

| | |
|---|---|
| **Version** | 1.0.2 |
| **Requires** | WordPress 6.2+, WooCommerce 6.5.4+, PHP 8.0+ |
| **License** | GPLv3 or later |

## How it works

1. The customer chooses **Pay by bank transfer (ACH)** at checkout (classic or block checkout).
2. The plugin asks RT for a payment link (signed with your secret key) and sends the customer to RT's payment page. The order is **Pending payment**.
3. The customer connects their bank and authorises the debit. RT reports the debit as in flight and the order moves to **On hold** (stock stays reserved; WooCommerce's unpaid-order cancellation and the customer's cancel link no longer apply).
4. When the funds settle (1 to 5 business days) RT sends a signed webhook and the order moves to **Processing** (or **Completed**, as configured).
5. If the bank later returns the settled payment, the order moves to **Payment returned**, the return code is recorded on the order and the shop admin is e-mailed.

RT only reports `success` once the ACH debit has completed, so a paid order always means settled funds.

## Installation

1. Upload the plugin ZIP through **Plugins > Add New > Upload Plugin**, or extract it into `wp-content/plugins`.
2. Activate it.
3. Go to **WooCommerce > Settings > Payments > RT Bank Transfer Payment Gateway**.
4. Enable the gateway and add at least one account with its **live public key** and **live secret key** (RT merchant dashboard > Developer Settings > API Keys). Add sandbox keys too if you want to test.
5. Press **Sync Accounts** to confirm the keys are valid.

## Settings

| Setting | Meaning |
|---|---|
| Title / Description | What the customer sees at checkout. Each account can override them. |
| Sandbox | Use the accounts' sandbox keys. On a production site (`WP_ENVIRONMENT_TYPE`), sandbox-paid orders are placed on hold instead of marked paid. |
| Payment Accounts | One or more RT accounts. The lowest priority number is tried first; an account that fails its daily-limit check is skipped. |
| Order Status | Status after settlement: Processing or Completed. |
| Show Consent Checkbox | Require the customer to consent to data processing before paying. |
| Debug Log | Write plugin events to WooCommerce > Status > Logs. Customer e-mails, phones and IPs are masked. |

## Order statuses

| WooCommerce status | Plugin state | Meaning |
|---|---|---|
| Pending payment | pending | Link issued; nothing authorised yet. Auto-cancels like any unpaid order. |
| On hold | processing | Debit authorised and in flight. Never auto-cancelled. |
| Processing / Completed | success | Funds settled. |
| Failed | failed / expired | Attempt failed or link expired. The customer may pay again. |
| Cancelled | cancelled | Cancelled by the customer, an admin or RT. |
| Payment returned | returned | A settled debit was returned by the bank. Admin alerted. |

A second settled payment for an order that is already paid is never applied; it is flagged on the order and e-mailed to the admin for a refund.

## Integration with RT (for developers)

All calls to RT carry `Authorization: Bearer <public key>`, `X-Timestamp` (Unix seconds) and `X-Signature` = hex HMAC-SHA256 of `"<X-Timestamp>.<raw body>"` keyed with the account's secret key. The secret key is never transmitted. `/sync-account-status` signs `"<timestamp>.<public_key>"` per account instead.

RT calls the shop back at `POST /wp-json/rt-bank-transfer/v1/data` with the same signature scheme. The plugin requires the signature, refuses timestamps more than 300 seconds off, ignores a replayed signature, and checks the payment ID (any ID ever issued for the order), the exact amount, the currency and the order number before acting. The webhook answers:

| HTTP | Meaning |
|---|---|
| 200 | Applied, or already applied (duplicate / order already paid). |
| 400 | Payment ID, amount, currency or order does not match; RT will not retry. |
| 401 | Signature missing or invalid. |
| 409 | The status is not a valid transition from the order's state. |
| 422 | Unknown status word. |
| 423 | Order locked; RT retries. |

Status words accepted: `success`, `processing`, `pending`, `failed`, `canceled`, `expired`, `returned` (with `return_code` / `failure_reason`).

Filters: `rt_bank_transfer_alert_email` (recipient of return / duplicate alerts), `rt_bank_transfer_is_production_environment`, `bank_transfer_webhook_url`, `bank_transfer_api_host`.

## Upgrading from 1.0.x

* The gateway id is now `rt_bank_transfer`; orders placed under the old `bank_transfer` id are still recognised and the settings are copied over automatically.
* The plugin now uses its own `{prefix}rt_bank_transfer_payment_link` table; rows for this gateway's orders are copied from the shared `order_payment_link` table once. That table is left in place for other plugins.
* Payment links issued before the upgrade return to the old `bank-transfer/v1` route, which stays registered as an alias.
* ByteNFT / Unified plugin settings and keys are no longer imported. Enter your RT keys in the gateway settings.
* The RT payment service must accept signed requests (RT deploys this); older service versions require `api_secret` in the body and will refuse this plugin's requests.

## Support

https://pay.rt.app/contact-us
