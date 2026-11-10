<?php
if (!defined('ABSPATH')) exit;

require_once plugin_dir_path(__FILE__) . 'config.php';
require_once plugin_dir_path(__FILE__) . 'bank-transfer-payment-gateway-utils.php';

/**
 * The order's payment state machine.
 *
 * States: pending (link open, nothing authorised), processing (the ACH debit
 * is in flight: customer authorised, money not settled -- WooCommerce
 * on-hold), success (funds settled -- processing/completed), failed,
 * cancelled, expired, returned (a settled debit the bank sent back --
 * WooCommerce "Payment returned").
 *
 * success is final for every source except a signed RT webhook, which alone
 * may move a paid order to returned (BK-01). A second settled payment on a
 * paid order is never applied; it is flagged for a refund (BK-04).
 */
class BANK_TRANSFER_PAYMENT_ENGINE
{
    const LOCK_TTL  = 12;
    const EVENT_TTL = 86400;

    /** Event types whose payload came from an RT webhook verified with the merchant secret. */
    const SIGNED_EVENT_TYPES = ['webhook_update'];

    /* =========================================================
     * ENTRY POINT
     * ========================================================= */
    public static function handle_event($order_id, $event_type, $payload = [])
    {
        $order = wc_get_order($order_id);
        if (!$order || !is_a($order, 'WC_Order')) return false;

        if (!bank_transfer_is_gateway_order($order)) {
            Bank_Transfer_Payment_Gateway_Logger::warning('Rejected event: order payment method is not this gateway.', [
                'order_id' => $order_id,
            ]);
            return false;
        }

        $event_id = self::generate_event_id($event_type, $payload);

        if (in_array($event_type, ['api_update', 'webhook_update'], true) && self::is_duplicate_event($order_id, $event_id)) {
            return self::safe_response($order, 'duplicate_event_ignored', self::get_state($order));
        }

        global $wpdb;
        $lock_name = "bank_transfer_lock_{$order_id}";
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $lock_acquired = (int) $wpdb->get_var($wpdb->prepare("SELECT GET_LOCK(%s, 5)", $lock_name));
        if ($lock_acquired !== 1) {
            return self::safe_response($order, 'locked_skip');
        }

        try {

            $current_state = self::normalize_state(self::get_state($order));
            $new_state     = self::normalize_state(self::resolve_state($payload));

            if (!$new_state) {
                return self::safe_response($order, 'no_state', $current_state);
            }

            $is_signed      = in_array($event_type, self::SIGNED_EVENT_TYPES, true);
            $can_transition = null;

            if ($current_state === 'success') {
                /*
                 * BK-01: RT reports a return / reversal of a SETTLED debit as
                 * "returned" (with the bank's R-code), and the request itself as
                 * "failed". Either word on a paid order, from a signed webhook
                 * only, moves the order out of processing. This is the one
                 * transition out of success; the map below never allows it.
                 */
                if ($is_signed && in_array($new_state, ['returned', 'failed'], true)) {
                    $new_state      = 'returned';
                    $can_transition = true;
                } elseif ($new_state === 'success') {
                    /*
                     * BK-04: a second settled payment for an order that is already
                     * paid (another link was created and paid while the first
                     * debit was in flight). The order stays paid once; the extra
                     * payment is flagged for a refund.
                     */
                    $token = (string) ($payload['payment_token'] ?? '');
                    $paid  = (string) $order->get_meta('_bank_transfer_pay_id');
                    if ($token !== '' && $paid !== '' && !hash_equals($paid, $token)) {
                        self::flag_duplicate_payment($order, $token, $event_type);
                        self::mark_event($order_id, $event_id);
                        return self::safe_response($order, 'duplicate_payment_flagged', 'success');
                    }
                    return self::safe_response($order, 'final_success_locked', 'success');
                } else {
                    return self::safe_response($order, 'final_success_locked', 'success');
                }
            } elseif ($new_state === 'returned') {
                // A return only makes sense for money that arrived.
                return self::safe_response($order, 'invalid_transition_blocked', $current_state);
            }

            if ($can_transition === null) {
                $can_transition = self::can_transition($current_state, $new_state);

                /**
                 * A verified successful payment must always be able to settle an order
                 * that is still waiting for money. The engine state can lag behind the
                 * backend (pending / on-hold / checkout-draft / processing / failed) or
                 * record a closed attempt (cancelled / expired) while the transfer still
                 * arrived afterwards. In every one of those cases the money is in the
                 * merchant account, so WooCommerce has to reflect it.
                 */
                if ($new_state === 'success') {
                    $can_transition = in_array(
                        $current_state,
                        ['pending', 'on-hold', 'checkout-draft', 'processing', 'failed', 'cancelled', 'expired'],
                        true
                    );
                }
            }

            if (!$can_transition) {
                Bank_Transfer_Payment_Gateway_Logger::warning('Refused invalid order state transition.', [
                    'order_id' => $order_id,
                    'from'     => $current_state,
                    'to'       => $new_state,
                ]);

                return self::safe_response(
                    $order,
                    'invalid_transition_blocked',
                    $current_state
                );
            }

            self::apply($order, $new_state, $event_type, $payload);
            self::mark_event($order_id, $event_id);

            if ($new_state === 'failed') {

                $existing = self::timeline_has_state_for_token(
                    $order,
                    'failed',
                    $payload['payment_token'] ?? ''
                );

                if (!$existing) {
                    self::push_timeline_event(
                        $order,
                        $new_state,
                        $event_type,
                        $payload
                    );
                }

            } elseif ($new_state === 'pending' && $current_state === 'pending') {
                // Nothing happened; keep the timeline readable.
            } else {

                self::push_timeline_event(
                    $order,
                    $new_state,
                    $event_type,
                    $payload
                );
            }

            self::sync_order_notes($order);

            return self::safe_response($order, 'updated', $new_state);

        } finally {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->query($wpdb->prepare("SELECT RELEASE_LOCK(%s)", $lock_name));
        }
    }

    private static function timeline_has_state_for_token($order, $state, $payment_token)
    {
        if (empty($payment_token)) {
            return false;
        }

        $timeline = self::get_timeline($order);

        foreach ($timeline as $event) {

            if (
                ($event['type'] ?? '') === $state &&
                ($event['payment_token'] ?? '') === $payment_token
            ) {
                return true;
            }
        }

        return false;
    }

    /* =========================================================
     * TIMELINE ENGINE (SOURCE OF TRUTH)
     * ========================================================= */
    private static function push_timeline_event($order, $state, $event_type, $payload)
    {
        $timeline = self::get_timeline($order);

        $timeline[] = [
            'type'          => $state,
            'event_type'    => $event_type,
            'time'          => current_time('mysql'),
            'payment_token' => $payload['payment_token'] ?? null,
        ];

        $order->update_meta_data('_bank_transfer_timeline', $timeline);
        $order->save();
    }

    /* =========================================================
     * APPLY STATE (WooCommerce sync only)
     * ========================================================= */
    private static function apply($order, $state, $event_type, $payload)
    {
        if (self::get_state($order) === 'success' && $state !== 'returned') {
            return;
        }

        $order_id = $order->get_id();
        $payment_token = $payload['payment_token'] ?? '';

        // stable idempotency key (VERY IMPORTANT)
        $state_lock_key = md5($order_id . '|' . $state . '|' . $payment_token);

        $last_lock = $order->get_meta('_bank_transfer_state_lock');

        // The lock only short-circuits a repeat of an event that already reached
        // WooCommerce. A 'success' lock on an order that is still unpaid means the
        // earlier attempt never completed (request died, status update failed), so
        // it must be allowed through again.
        if (
            $last_lock === $state_lock_key &&
            !($state === 'success' && $order->has_status(['pending', 'on-hold', 'failed', 'cancelled', 'checkout-draft']))
        ) {
            return;
        }

        $order->update_meta_data('_bank_transfer_state_lock', $state_lock_key);

        $wc_status = match ($state) {
            'success'    => self::get_success_wc_status(),
            'processing' => 'on-hold',
            'failed'     => 'failed',
            'cancelled'  => 'cancelled',
            'expired'    => 'failed',
            'returned'   => BANK_TRANSFER_RETURNED_STATUS,
            default      => null
        };

        if ($wc_status) {
            if ($state === 'success') {
                self::complete_payment($order, $wc_status, $payment_token);
            } elseif ($state === 'processing') {
                /*
                 * BK-03: the customer authorised the debit; the money is on its
                 * way for 1-5 business days. on-hold keeps the stock reserved and
                 * takes the order out of WooCommerce's unpaid-order cancellation
                 * and the customer's cancel link.
                 */
                if ($order->has_status(['pending', 'failed', 'cancelled', 'checkout-draft'])) {
                    $order->update_status(
                        'on-hold',
                        __('Bank transfer authorised by the customer; the ACH debit is in flight. The order is on hold until RT confirms the funds settled.', 'bank-transfer-payment-gateway')
                    );
                }
            } elseif ($state === 'returned') {
                self::apply_return($order, $payload);
            } else {
                $order->update_status($wc_status, '');
            }
        }

        $order->update_meta_data('_bank_transfer_state', $state);
        $order->update_meta_data('_bank_transfer_last_event', $event_type);
        $order->update_meta_data('_bank_transfer_last_event_time', current_time('mysql'));

        if (!empty($payment_token) && $state !== 'returned') {
            $order->update_meta_data('_bank_transfer_pay_id', $payment_token);
        }

        if ($state === 'success') {
            $order->update_meta_data('_bank_transfer_payment_success', 'yes');
            $order->update_meta_data('_bank_transfer_payment_finalized', true);
            $order->update_meta_data('_bank_transfer_paid_amount', (float) $order->get_total());
        }

        $order->save();
    }

    /**
     * Mark the order paid.
     *
     * BK-18: a sandbox "success" on a production site must not put a real
     * order into fulfilment. The order is parked on-hold with a note instead
     * of processing/completed, and stock is not reduced a second time.
     */
    private static function complete_payment($order, $wc_status, $payment_token)
    {
        if ($order->get_meta('_is_test_order') && bank_transfer_is_production_environment()) {
            $order->update_status(
                'on-hold',
                __('SANDBOX test payment reported as successful. No real funds were received. This site is configured as a production site, so the order was placed on hold instead of being marked paid.', 'bank-transfer-payment-gateway')
            );
            $order->update_meta_data('_bank_transfer_sandbox_success_held', 'yes');
            return;
        }

        // payment_complete() records the transaction id and paid date,
        // reduces stock and moves the order to processing/completed the
        // WooCommerce way. Then honour the configured success status.
        $order->payment_complete($payment_token);
        if ($order->get_status() !== $wc_status) {
            $order->update_status($wc_status, '');
        }
    }

    /**
     * BK-01: the bank sent a settled debit back. Record the code, move the
     * order to "Payment returned" and tell the shop owner.
     */
    private static function apply_return($order, $payload)
    {
        $return_code = sanitize_text_field((string) ($payload['return_code'] ?? ''));
        $reason      = sanitize_text_field((string) ($payload['failure_reason'] ?? ''));
        $token       = sanitize_text_field((string) ($payload['payment_token'] ?? ''));

        $detail = $return_code !== '' ? sprintf(' (%s)', $return_code) : '';
        $note   = sprintf(
            /* translators: 1: return code in parentheses or empty, 2: payment id */
            __('The bank returned / reversed the settled payment%1$s for payment ID %2$s. The funds are no longer in your account. Review the order before fulfilling or shipping it.', 'bank-transfer-payment-gateway'),
            $detail,
            $token
        );
        if ($reason !== '') {
            $note .= ' ' . $reason;
        }

        $order->update_meta_data('_bank_transfer_payment_returned', 'yes');
        $order->update_meta_data('_bank_transfer_return_code', $return_code);
        $order->update_meta_data('_bank_transfer_returned_at', current_time('mysql'));
        $order->update_status(BANK_TRANSFER_RETURNED_STATUS, '');

        bank_transfer_notify_admin(
            sprintf(
                /* translators: %s: order number */
                __('Bank transfer payment returned for order #%s', 'bank-transfer-payment-gateway'),
                $order->get_order_number()
            ),
            $note,
            $order
        );
    }

    /**
     * BK-04: a settled payment arrived for an order that is already paid under
     * another payment id. Never a second payment_complete(); flag it.
     */
    private static function flag_duplicate_payment($order, $token, $event_type)
    {
        $flagged = $order->get_meta('_bank_transfer_duplicate_payments');
        $flagged = is_array($flagged) ? array_values(array_filter($flagged, 'is_string')) : [];
        if (in_array($token, $flagged, true)) {
            return;
        }
        $flagged[] = $token;
        $order->update_meta_data('_bank_transfer_duplicate_payments', $flagged);
        $order->save();

        Bank_Transfer_Payment_Gateway_Logger::warning('Duplicate settled payment flagged for an already paid order.', [
            'order_id'   => $order->get_id(),
            'event_type' => $event_type,
        ]);

        bank_transfer_notify_admin(
            sprintf(
                /* translators: %s: order number */
                __('Duplicate bank transfer payment on order #%s', 'bank-transfer-payment-gateway'),
                $order->get_order_number()
            ),
            sprintf(
                /* translators: 1: payment id of the duplicate, 2: payment id the order was paid with */
                __('RT reports a second settled payment (payment ID %1$s) for this order, which was already paid with payment ID %2$s. The order was NOT changed. The customer has paid twice; refund the duplicate from your RT dashboard.', 'bank-transfer-payment-gateway'),
                $token,
                (string) $order->get_meta('_bank_transfer_pay_id')
            ),
            $order
        );
    }

    private static function get_timeline($order)
    {
        $data = $order->get_meta('_bank_transfer_timeline', true);

        if (empty($data)) {
            return [];
        }

        // If corrupted string
        if (!is_array($data)) {
            return [];
        }

        // sanitize each entry
        return array_values(array_filter($data, function ($item) {
            return is_array($item) && isset($item['type']);
        }));
    }

    /* =========================================================
     * TIMELINE → ORDER NOTES SYNC
     * ========================================================= */
    private static function sync_order_notes($order)
    {
        $timeline = self::get_timeline($order);

        if (empty($timeline)) {
            return;
        }

        $failed_events = [];
        $success_event = null;
        $cancel_event  = null;
        $seen_failed   = [];

        /**
         * SINGLE PASS TIMELINE PARSE
         */
        foreach ($timeline as $event) {

            $type  = $event['type'] ?? '';
            $token = trim((string) ($event['payment_token'] ?? ''));

            /**
             * FAILED EVENTS
             */
            if ($type === 'failed') {

                if (
                    !empty($token) &&
                    !isset($seen_failed[$token])
                ) {
                    $seen_failed[$token] = true;

                    $failed_events[] = $event;
                }
            }

            /**
             * SUCCESS EVENT
             */
            if (
                $type === 'success' &&
                !$success_event
            ) {
                $success_event = $event;
            }

            /**
             * CANCEL EVENT
             */
            if (
                $type === 'cancelled' &&
                !$cancel_event
            ) {
                $cancel_event = $event;
            }
        }

        /**
         * FAILED NOTES
         */
        $already_synced = (int) $order->get_meta('_bank_transfer_failed_note_count');

        $actual_failed_count = count($failed_events);

        if ($actual_failed_count > $already_synced) {

            for ($i = $already_synced; $i < $actual_failed_count; $i++) {

                $event = $failed_events[$i];

                $attempt_number = $i + 1;

                $order->add_order_note(
                    self::build_order_note(
                        "Payment Failed (Attempt {$attempt_number})",
                        $event['payment_token'] ?? '',
                        $event['event_type'] ?? ''
                    )
                );
            }

            $order->update_meta_data(
                '_bank_transfer_failed_note_count',
                $actual_failed_count
            );
        }

        /**
         * SUCCESS NOTE
         */
        if (
            $success_event &&
            !$order->get_meta('_bank_transfer_success_note_added')
        ) {

            $order->add_order_note(
                self::build_order_note(
                    'Payment completed successfully.',
                    $success_event['payment_token'] ?? '',
                    $success_event['event_type'] ?? ''
                )
            );

            $order->update_meta_data(
                '_bank_transfer_success_note_added',
                'yes'
            );
        }

        /**
         * CANCEL NOTE
         */
        if (
            $cancel_event &&
            !$order->get_meta('_bank_transfer_cancel_note_added')
        ) {

            $order->add_order_note(
                self::build_order_note(
                    'Payment was cancelled.',
                    $cancel_event['payment_token'] ?? '',
                    $cancel_event['event_type'] ?? ''
                )
            );

            $order->update_meta_data(
                '_bank_transfer_cancel_note_added',
                'yes'
            );
        }

        $order->save();
    }

    private static function build_order_note(
        $title,
        $payment_token,
        $event_type
    ) {

        $source = match ($event_type) {
            'popup_close'    => 'Customer Return from Payment Page',
            'redirect'       => 'Customer Redirect',
            'redirect_check' => 'Customer Status Check',
            'webhook_update' => 'Webhook',
            'api_sync'       => 'Automatic Status Check',
            default          => 'System'
        };

        // Decode Base64 payment token if possible
        $decoded_payment_id = base64_decode($payment_token, true);

        if (empty($decoded_payment_id)) {
            $decoded_payment_id = $payment_token;
        }

        return sprintf(
            '<strong>Bank Transfer Gateway</strong><br><br>
            <strong>%s</strong><br><br>
            <strong>Payment ID:</strong> %s<br>
            <strong>Updated Via:</strong> %s<br>
            <strong>Recorded At:</strong> %s',
            esc_html($title),
            esc_html($decoded_payment_id),
            esc_html($source),
            esc_html(current_time('F j, Y \a\t g:i A'))
        );
    }

    /* =========================================================
     * STATE RESOLUTION
     * ========================================================= */

    /**
     * Map a status word from RT to an engine state.
     *
     * RT's contract (payment backend, wordpress/order_sync.py): "success" is
     * sent only once the ACH debit has COMPLETED, i.e. the funds settled
     * (BK-02). "processing" means the customer authorised the debit and it is
     * in flight; "pending" means the link is open and nothing has happened.
     * "returned" (with return_code) is a settled debit the bank sent back.
     */
    public static function map_status($status)
    {
        $status = is_string($status) && $status !== '' ? strtolower(trim($status)) : null;

        return match ($status) {
            'success', 'paid', 'completed', 'settled', 'succeeded'            => 'success',
            'failed', 'rejected', 'declined'                                   => 'failed',
            'cancelled', 'canceled'                                            => 'cancelled',
            'expired'                                                          => 'expired',
            'processing', 'submitted', 'submitting', 'authorized', 'authorised', 'in_flight', 'in-flight' => 'processing',
            'pending', 'initiated', 'created', 'open', 'awaiting'              => 'pending',
            'returned', 'reversed', 'reversal', 'nsf', 'chargeback', 'charged_back', 'disputed', 'dispute', 'refunded', 'refund' => 'returned',
            default                                                            => null
        };
    }

    private static function resolve_state($payload)
    {
        $status = $payload['status']
            ?? $payload['payment_status']
            ?? $payload['transaction_status']
            ?? $payload['order_status']
            ?? null;

        return self::map_status($status);
    }

    /* =========================================================
     * NORMALIZATION
     * ========================================================= */
    private static function normalize_state($state)
    {
        return match ($state) {
            'completed' => 'success',
            'canceled'  => 'cancelled',
            BANK_TRANSFER_RETURNED_STATUS => 'returned',
            default     => $state
        };
    }

    /* =========================================================
     * TRANSITIONS
     * ========================================================= */
    private static function can_transition($from, $to)
    {
        if ($from === 'success') return false;
        if ($from === $to) return true;

        // 'expired' and 'cancelled' are reported by the RT backend webhook once
        // a link dies unpaid or the payer abandons an open attempt. A bank
        // transfer can still settle after the attempt was closed on the portal,
        // so 'success' stays reachable from every non-final state (handle_event
        // enforces the same rule). 'processing' (debit in flight) is reachable
        // from every open or closed-but-retried state; 'returned' only follows
        // 'success' (handle_event).
        $map = [
            'pending'        => ['pending', 'cancelled', 'processing', 'success', 'failed', 'expired', 'on-hold'],
            'on-hold'        => ['pending', 'cancelled', 'processing', 'success', 'failed', 'expired', 'on-hold'],
            'checkout-draft' => ['pending', 'cancelled', 'processing', 'success', 'failed', 'expired', 'on-hold'],
            'failed'         => ['failed', 'success', 'processing', 'cancelled', 'expired', 'pending'],
            'processing'     => ['processing', 'success', 'failed', 'cancelled', 'expired'],
            'cancelled'      => ['cancelled', 'success', 'processing', 'pending'],
            'expired'        => ['expired', 'success', 'processing', 'pending'],
            'returned'       => ['returned'],
        ];

        return in_array($to, $map[$from] ?? [], true);
    }

    /* =========================================================
     * CURRENT STATE
     * ========================================================= */
    private static function get_state($order)
    {
        $wc_status = $order->get_status();

        // An order cancelled in WooCommerce (admin or customer) is cancelled for the
        // engine too, whatever the last recorded engine state was.
        if ($wc_status === 'cancelled') {
            return 'cancelled';
        }

        if ($wc_status === BANK_TRANSFER_RETURNED_STATUS) {
            return 'returned';
        }

        $meta_state = $order->get_meta('_bank_transfer_state');
        if (!empty($meta_state)) {
            return $meta_state;
        }

        // No engine state recorded yet: fall back to the WooCommerce status so an
        // 'on-hold' or 'checkout-draft' order is still a valid starting point.
        return !empty($wc_status) ? $wc_status : 'pending';
    }

    /**
     * Whether the order's ACH debit is in flight (authorised, not settled).
     */
    public static function is_in_flight($order)
    {
        return $order instanceof WC_Order && self::normalize_state(self::get_state($order)) === 'processing';
    }

    /* =========================================================
     * SUCCESS STATUS
     * ========================================================= */
    private static function get_success_wc_status()
    {
        $settings = bank_transfer_get_settings();
        $status = $settings['order_status'] ?? 'processing';

        return in_array($status, ['processing','completed'], true)
            ? $status
            : 'processing';
    }

    /* =========================================================
     * EVENT ID (webhook dedupe only)
     * ========================================================= */
    private static function generate_event_id($type, $payload)
    {
        return hash('sha256', implode('|', [
            $type,
            $payload['status'] ?? '',
            $payload['payment_token'] ?? ''
        ]));
    }

    private static function is_duplicate_event($order_id, $event_id)
    {
        return get_transient("bank_transfer_event_{$order_id}_{$event_id}") !== false;
    }

    private static function mark_event($order_id, $event_id)
    {
        set_transient("bank_transfer_event_{$order_id}_{$event_id}", 1, self::EVENT_TTL);
    }

    /* =========================================================
     * RESPONSE
     * ========================================================= */
    private static function safe_response($order, $reason, $state = null)
    {
        return [
            'ok' => true,
            'reason' => $reason,
            'order_id' => $order->get_id(),
            'state' => $state ?? self::get_state($order)
        ];
    }

    public static function resolve_final_state($order, $api_status = null)
    {
        // 1. PRIMARY: engine state (validated)
        $state = self::normalize_state(self::get_state($order));

        if (!empty($state) && in_array($state, ['pending','processing','success','failed','cancelled','expired','returned'], true)) {
            return $state;
        }

        // 2. SAFETY: API status (current response)
        if (!empty($api_status) && is_string($api_status)) {
            $mapped = self::map_status($api_status);

            if ($mapped) {
                return $mapped;
            }
        }

        // 3. BACKUP: WooCommerce status
        if ($order->has_status(['processing', 'completed'])) {
            return 'success';
        }

        if ($order->has_status(['failed'])) {
            return 'failed';
        }

        if ($order->has_status(['cancelled'])) {
            return 'cancelled';
        }

        return null;
    }
}
