<?php
if (!defined('ABSPATH')) exit;

class BANK_TRANSFER_PAYMENT_ENGINE
{
    const LOCK_TTL  = 12;
    const EVENT_TTL = 86400;


    /* =========================================================
     * ENTRY POINT
     * ========================================================= */
    public static function handle_event($order_id, $event_type, $payload = [])
    {
        $order = wc_get_order($order_id);
        if (!$order || !is_a($order, 'WC_Order')) return false;

        if ($order->get_payment_method() !== 'bank_transfer') {
            Bank_Transfer_Payment_Gateway_Logger::warning('Rejected event: order payment method is not bank_transfer.', [
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

            if ($current_state === 'success') {
                if ($new_state === 'returned' && $event_type === 'webhook_update') {
                    $can_transition = true;
                } elseif ($new_state === 'success') {
                    $incoming_token = $payload['payment_token'] ?? ($payload['pay_id'] ?? '');
                    $stored_token = $order->get_transaction_id() ?: $order->get_meta('_bank_transfer_pay_id');
                    if (!empty($incoming_token) && !empty($stored_token) && !hash_equals((string) $stored_token, (string) $incoming_token)) {
                        $order->add_order_note(sprintf(
                            /* translators: 1: incoming pay_id, 2: stored pay_id */
                            __('Warning: Duplicate payment success received for token %1$s. Order was already settled with token %2$s.', 'bank-transfer-payment-gateway'),
                            esc_html($incoming_token),
                            esc_html($stored_token)
                        ));
                        $order->save();
                    }
                    return self::safe_response($order, 'final_success_locked', 'success');
                } else {
                    return self::safe_response($order, 'final_success_locked', 'success');
                }
            } else {
                $can_transition = self::can_transition($current_state, $new_state);
            }

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
            !($state === 'success' && $order->has_status(['pending', 'on-hold', 'failed', 'cancelled', 'checkout-draft'])) &&
            $state !== 'returned'
        ) {
            return;
        }

        $order->update_meta_data('_bank_transfer_state_lock', $state_lock_key);

        $wc_status = match ($state) {
            'success'    => self::get_success_wc_status(),
            'returned'   => 'on-hold',
            'failed'     => 'failed',
            'cancelled'  => 'cancelled',
            'expired'    => 'failed',
            default      => null
        };

        if ($wc_status) {
            if ($state === 'success') {
                // payment_complete() records the transaction id and paid date,
                // reduces stock and moves the order to processing/completed the
                // WooCommerce way. Then honour the configured success status.
                $order->payment_complete($payment_token);
                if ($order->get_status() !== $wc_status) {
                    $order->update_status($wc_status, '');
                }
            } elseif ($state === 'returned') {
                $reason = !empty($payload['reason']) ? $payload['reason'] : (!empty($payload['return_reason']) ? $payload['return_reason'] : 'ACH Return / Reversal');
                $note = sprintf(
                    /* translators: %s: return reason */
                    __('ACH Payment Returned / Reversed by bank (%s). Order moved to on-hold. Attention required.', 'bank-transfer-payment-gateway'),
                    esc_html($reason)
                );
                $order->update_status('on-hold', $note);
                $order->update_meta_data('_bank_transfer_returned', 'yes');
                $order->update_meta_data('_bank_transfer_returned_reason', sanitize_text_field($reason));
                $order->update_meta_data('_bank_transfer_returned_time', current_time('mysql'));

                Bank_Transfer_Payment_Gateway_Logger::warning('ACH Payment Returned / Reversed for Order #' . $order_id, [
                    'order_id'      => $order_id,
                    'payment_token' => $payment_token,
                    'reason'        => $reason,
                ]);

                do_action('bank_transfer_payment_returned', $order, $payload);
            } else {
                $order->update_status($wc_status, '');
            }
        }

        $order->update_meta_data('_bank_transfer_state', $state);
        $order->update_meta_data('_bank_transfer_last_event', $event_type);
        $order->update_meta_data('_bank_transfer_last_event_time', current_time('mysql'));

        if (!empty($payment_token)) {
            $order->update_meta_data('_bank_transfer_pay_id', $payment_token);
        }

        if ($state === 'success') {
            $order->update_meta_data('_bank_transfer_payment_success', 'yes');
            $order->update_meta_data('_bank_transfer_payment_finalized', true);
            $order->update_meta_data('_bank_transfer_paid_amount', (float) $order->get_total());
        }

        $order->save();
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
    private static function resolve_state($payload)
    {
        $status = $payload['status']
            ?? $payload['payment_status']
            ?? $payload['transaction_status']
            ?? $payload['order_status']
            ?? null;

        $status = is_string($status) && $status !== '' ? strtolower(trim($status)) : null;

        return match ($status) {
            'success', 'paid', 'completed', 'settled', 'succeeded' => 'success',
            'returned', 'reversed', 'nsf', 'chargeback', 'disputed', 'dispute', 'refunded' => 'returned',
            'failed'                        => 'failed',
            'cancelled', 'canceled'        => 'cancelled',
            'expired'                      => 'expired',
            'pending', 'processing', 'submitted', 'authorized' => 'processing',
            default                        => null
        };
    }

    /* =========================================================
     * NORMALIZATION
     * ========================================================= */
    private static function normalize_state($state)
    {
        return match ($state) {
            'completed' => 'success',
            'canceled'  => 'cancelled',
            'reversed', 'nsf', 'chargeback', 'disputed', 'refunded' => 'returned',
            default     => $state
        };
    }

    /* =========================================================
     * TRANSITIONS
     * ========================================================= */
    private static function can_transition($from, $to)
    {
        if ($from === 'success') return $to === 'returned';
        if ($from === $to) return true;

        // 'expired' and 'cancelled' are reported by the RT backend webhook once
        // a link dies unpaid or the payer abandons an open attempt. 'processing'
        // here also covers a backend 'pending' attempt (see resolve_state), so
        // both may legitimately follow it. A bank transfer can still settle after
        // the attempt was closed on the portal, so 'success' stays reachable from
        // every non-final state (handle_event enforces the same rule).
        $map = [
            'pending'        => ['pending', 'cancelled', 'processing', 'success', 'failed', 'expired', 'on-hold', 'returned'],
            'on-hold'        => ['pending', 'cancelled', 'processing', 'success', 'failed', 'expired', 'on-hold', 'returned'],
            'checkout-draft' => ['pending', 'cancelled', 'processing', 'success', 'failed', 'expired', 'on-hold', 'returned'],
            'failed'         => ['failed', 'success', 'processing', 'cancelled', 'expired', 'pending'],
            'processing'     => ['processing', 'success', 'failed', 'cancelled', 'expired', 'on-hold', 'returned'],
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

        $meta_state = $order->get_meta('_bank_transfer_state');
        if (!empty($meta_state)) {
            return $meta_state;
        }

        // No engine state recorded yet: fall back to the WooCommerce status so an
        // 'on-hold' or 'checkout-draft' order is still a valid starting point.
        return !empty($wc_status) ? $wc_status : 'pending';
    }

    /* =========================================================
     * SUCCESS STATUS
     * ========================================================= */
    private static function get_success_wc_status()
    {
        $settings = get_option('woocommerce_bank_transfer_settings', []);
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
            $mapped = match (strtolower(trim($api_status))) {
                'success', 'paid', 'completed', 'settled', 'succeeded' => 'success',
                'returned', 'reversed', 'nsf', 'chargeback', 'disputed', 'dispute', 'refunded' => 'returned',
                'failed' => 'failed',
                'cancelled', 'canceled' => 'cancelled',
                'expired' => 'expired',
                'processing', 'pending', 'submitted', 'authorized' => 'processing',
                default => null
            };

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
