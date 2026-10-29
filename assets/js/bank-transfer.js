(function ($, window, document) {
    'use strict';

    if (window.BankTransferCheckoutInitialized) {
        return;
    }

    window.BankTransferCheckoutInitialized = true;

    const BankTransferCheckout = {

        PAYMENT_METHOD: bank_transfer_params.payment_method,

        state: {
            submitting: false,
            popup: null,
            popupInterval: null,
            orderId: null,
            orderKey: null,
            button: null,
            buttonText: ''
        },

        /* =========================================================
         * INIT
         * ========================================================= */

        init: function () {

            this.bindClassicCheckout();

            this.bindBlockCheckout();

            this.bindInputSanitization();
        },

        /* =========================================================
         * CLASSIC CHECKOUT
         * ========================================================= */

        bindClassicCheckout: function () {

            const self = this;

            $('form.checkout')
                .off('checkout_place_order_' + self.PAYMENT_METHOD)
                .on(
                    'checkout_place_order_' + self.PAYMENT_METHOD,
                    function () {

                        const $form = $(this);

                        if (self.state.submitting) {
                            return false;
                        }

                        self.clearCheckoutErrors();

                        // Safari popup fix
                        self.openPopupImmediately();

                        // Start custom flow
                        self.handleClassicCheckout($form);

                        // STOP WooCommerce default flow
                        return false;
                    }
                );
        },

        handleClassicCheckout: function ($form) {

            const self = this;

            self.state.submitting = true;

            self.state.button = $form
                .find('button[name="woocommerce_checkout_place_order"]');

            self.state.buttonText = self.state.button.text();

            self.state.button
                .prop('disabled', true)
                .addClass('loading')
                .text('Processing...');

            $.ajax({

                type: 'POST',

                url: wc_checkout_params.checkout_url,

                data: $form.serialize(),

                dataType: 'json',

                success: function (response) {

                    self.handleResponse(response);
                },

                error: function (xhr, status, error) {

                    self.showCheckoutError(
                        'There was an error processing your order.'
                    );

                    self.cleanupPopup();

                    self.reset();
                }
            });
        },

        /* =========================================================
         * BLOCK CHECKOUT
         * ========================================================= */

        bindBlockCheckout: function () {

            const self = this;

            document.addEventListener(
                'click',
                function (e) {

                    const btn = e.target.closest(
                        '.wc-block-components-checkout-place-order-button'
                    );

                    if (!btn) {
                        return;
                    }

                    const $form = $('form.wc-block-checkout__form');

                    if (!$form.length) {
                        return;
                    }

                    const selected = $form
                        .find(
                            'input[name="radio-control-wc-payment-method-options"]:checked'
                        )
                        .val();

                    if (selected !== self.PAYMENT_METHOD) {
                        return;
                    }

                    e.preventDefault();
                    e.stopImmediatePropagation();

                    if (self.state.submitting) {
                        return;
                    }

                    self.clearCheckoutErrors();

                    // Safari popup fix
                    self.openPopupImmediately();

                    // Start block flow
                    self.handleBlockCheckout($form);

                },
                true
            );

            // Prevent Woo block native submit
            document.addEventListener(
                'submit',
                function (e) {

                    const form = e.target;

                    if (!form.classList.contains('wc-block-checkout__form')) {
                        return;
                    }

                    const selected = form.querySelector(
                        'input[name="radio-control-wc-payment-method-options"]:checked'
                    )?.value;

                    if (selected !== self.PAYMENT_METHOD) {
                        return;
                    }

                    e.preventDefault();
                    e.stopImmediatePropagation();

                },
                true
            );
        },

        handleBlockCheckout: function ($form) {

            const self = this;

            self.state.submitting = true;

            self.state.button = $(
                '.wc-block-components-checkout-place-order-button'
            );

            self.state.buttonText = self.state.button.text();

            self.state.button
                .prop('disabled', true)
                .addClass('loading')
                .text('Processing...');

            let data = $form.serialize();

            // Extract email and phone directly to avoid Store API sync race conditions
            let emailField = document.querySelector('input[type="email"], #email');
            if (emailField && emailField.value) {
                data += '&billing_email=' + encodeURIComponent(emailField.value);
            }
            let phoneField = document.querySelector('input[type="tel"], #phone, input[name="phone"]');
            if (phoneField && phoneField.value) {
                data += '&billing_phone=' + encodeURIComponent(phoneField.value);
            }

            data += '&action=bank_transfer_block_gateway_process';
            data += '&nonce=' + encodeURIComponent(bank_transfer_params.bank_transfer_nonce);

            $.ajax({

                type: 'POST',

                url: bank_transfer_params.ajax_url,

                data: data,

                success: function (response) {

                    self.handleResponse(response);
                },

                error: function (xhr, status, error) {

                    self.showCheckoutError(
                        'There was an error processing your order.'
                    );

                    self.cleanupPopup();

                    self.reset();
                }
            });
        },

        /* =========================================================
         * RESPONSE HANDLER
         * ========================================================= */

       handleResponse: function (response) {

        const self = this;

        try {

            if (typeof response === 'string') {

                    try {
                        response = JSON.parse(response);
                    } catch (e) {

                        self.showCheckoutError(
                            'Invalid server response.'
                        );

                        self.cleanupPopup();

                        self.reset();

                        return;
                    }
                }

                const success =
                    response?.result === 'success' ||
                    response?.success === true ||
                    response?.data?.payment_status === 'success' ||
                    response?.data?.payment_status === 'paid';

                const redirect =
                    response.redirect ||
                    response.data?.redirect ||
                    null;

                const orderId =
                    response.order_id ||
                    response.data?.order_id ||
                    null;

                const orderKey =
                    response.order_key ||
                    response.data?.order_key ||
                    null;

                const refreshedNonce =
                    response.bank_transfer_nonce ||
                    response.data?.bank_transfer_nonce ||
                    null;

                const errorMessage =
                    response?.message ||
                    response?.messages ||
                    response?.data?.message ||
                    response?.data?.messages ||
                    response?.data?.error ||
                    response?.error ||
                    'Your payment could not be completed. Please try again.';

                self.state.orderId = orderId;
                self.state.orderKey = orderKey;

                if (refreshedNonce && typeof bank_transfer_params !== 'undefined') {
                    bank_transfer_params.bank_transfer_nonce = refreshedNonce;
                }

                // =====================================================
                // ❌ FAILURE (FIXED - ERROR DISPLAY STABLE)
                // =====================================================
                if (!success) {

                    const msg = errorMessage || 'Your payment could not be completed. Please try again.';

                    self.cleanupPopup();

                    // 🔥 IMPORTANT
                    setTimeout(function () {

                        self.showCheckoutError(msg);

                        // force scroll after Woo rerender
                        const $notice = $('.woocommerce-notices-wrapper');

                        if ($notice.length) {
                            $('html, body').animate({
                                scrollTop: $notice.offset().top - 80
                            }, 300);
                        }

                    }, 50);

                    // 🔥 IMPORTANT
                    setTimeout(function () {
                        self.reset();
                    }, 400);

                    return;
                }

                // =====================================================
                // ✅ SUCCESS — navigate popup WITHOUT sending referrer
                // =====================================================    
                if (redirect && typeof redirect === 'string' && redirect.length > 5) {

                    if (self.state.popup && !self.state.popup.closed) {

                        try {

                            // FIX: Use navigateWithoutReferrer instead of
                            // directly setting location.href, which would
                            // send the WP checkout URL as Referer header
                            // to the Laravel payment page.
                            self.navigateWithoutReferrer(self.state.popup, redirect);

                        } catch (e) {

                            // Safari fallback — re-open popup and use same method
                            if (!self.state.popup || self.state.popup.closed) {
                                self.state.popup = window.open(
                                    '',
                                    '_blank'
                                );
                            }

                            self.navigateWithoutReferrer(self.state.popup, redirect);
                        }

                      self.trackPopupClose();

                    } else {

                        window.location.href = redirect;
                    }

                    self.reset(true);
                    return;
                }

                self.cleanupPopup();

                self.showCheckoutError('Missing redirect URL.');

                self.reset();

            } catch (e) {

                self.cleanupPopup();

                self.showCheckoutError('Unexpected checkout error.');

                self.reset();
            }
        },

        /* =========================================================
         * POPUP
         * ========================================================= */

        openPopupImmediately: function () {

            if (
                this.state.popup &&
                !this.state.popup.closed
            ) {
                return;
            }

            // NOTE: window.open() only takes 3 arguments — the 4th is ignored
            // by all browsers. noopener/noreferrer do NOT go here when opening
            // about:blank because we need to keep the popup reference to write
            // into it. Referrer stripping is handled by navigateWithoutReferrer().
            this.state.popup = window.open(
                '',
                '_blank',
                'width=700,height=700'
            );

            if (!this.state.popup) {

                alert('Popup blocked. Please allow popups.');

                return;
            }

            const logoUrl = bank_transfer_params.bank_transfer_loader
                ? encodeURI(bank_transfer_params.bank_transfer_loader)
                : '';

            // FIX: Add <meta name="referrer" content="no-referrer"> so that
            // even this loading page does not leak referrer on any resource loads.
            this.state.popup.document.write(`
                <!DOCTYPE html>
                <html>
                <head>
                    <title>Secure Payment</title>
                    <meta name="referrer" content="no-referrer">
                </head>

                <body style="
                    margin:0;
                    display:flex;
                    justify-content:center;
                    align-items:center;
                    height:100vh;
                    font-family:sans-serif;
                    background:#fff;
                    text-align:center;
                ">

                    <div>

                        ${
                            logoUrl
                                ? `<img src="${logoUrl}" style="max-width:120px;margin-bottom:20px;" />`
                                : ''
                        }

                        <h3>Connecting to secure payment...</h3>

                        <p>Please do not close this window.</p>

                    </div>

                </body>
                </html>
            `);

            this.state.popup.document.close();
        },

        /* =========================================================
         * NAVIGATE WITHOUT REFERRER
         * ========================================================= */

        navigateWithoutReferrer: function (popup, url) {

            try {
                const parsedUrl = new URL(url, window.location.href);
                if (parsedUrl.protocol !== 'https:' && parsedUrl.hostname !== 'localhost' && parsedUrl.hostname !== '127.0.0.1') {
                    throw new Error('Insecure payment URL');
                }
                const safeUrl = parsedUrl.href;
                const escapedUrl = safeUrl.replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/'/g, '&#39;').replace(/</g, '&lt;').replace(/>/g, '&gt;');

                var logoUrl = bank_transfer_params.bank_transfer_loader ? encodeURI(bank_transfer_params.bank_transfer_loader) : '';
                popup.document.open();
                popup.document.write(`
                <!DOCTYPE html>
                <html>
                <head>
                    <title>Secure Payment</title>
                    <meta name="referrer" content="no-referrer">
                    <meta http-equiv="refresh" content="0;url=` + escapedUrl + `">
                </head>

                <body style="
                    margin:0;
                    display:flex;
                    justify-content:center;
                    align-items:center;
                    height:100vh;
                    font-family:sans-serif;
                    background:#fff;
                    text-align:center;
                ">

                    <div>

                        ${
                            logoUrl
                                ? `<img src="${logoUrl}" style="max-width:120px;margin-bottom:20px;" />`
                                : ''
                        }

                        <h3>Connecting to secure payment...</h3>

                        <p>Please do not close this window.</p>

                    </div>

                </body>
                </html>
            `);
                popup.document.close();

            } catch (e) {
                try {
                    const fallbackUrl = new URL(url, window.location.href);
                    if (fallbackUrl.protocol === 'https:' || fallbackUrl.hostname === 'localhost' || fallbackUrl.hostname === '127.0.0.1') {
                        popup.location.replace(fallbackUrl.href);
                    }
                } catch (err) {}
            }
        },

        cleanupPopup: function () {

            if (
                this.state.popup &&
                !this.state.popup.closed
            ) {

                try {
                    this.state.popup.close();
                } catch (e) {}
            }

            this.state.popup = null;
        },

        trackPopupClose: function () {

            const self = this;

            let redirected = false;
            let pollAttempts = 0;
            const maxAttempts = 60; // 3 minutes maximum polling (60 * 3s)

            clearInterval(self.state.popupInterval);

            self.state.popupInterval = setInterval(function () {

                // Prevent duplicate execution
                if (redirected) {
                    clearInterval(self.state.popupInterval);
                    return;
                }

                pollAttempts++;
                if (pollAttempts > maxAttempts) {
                    clearInterval(self.state.popupInterval);
                    return;
                }

                const popupExists =
                    self.state.popup &&
                    !self.state.popup.closed;

                // =========================================
                // SAFARI + CHROME PAYMENT CHECK
                // =========================================
                $.post(
                    bank_transfer_params.ajax_url,
                    {
                        action: 'bank_transfer_popup_closed_event',
                        order_id: self.state.orderId,
                        order_key: self.state.orderKey,
                        security: bank_transfer_params.bank_transfer_nonce
                    },
                    function (response) {

                        if (redirected) {
                            clearInterval(self.state.popupInterval);
                            return;
                        }

                        // Stop polling if server explicitly indicates stop_polling (e.g. fatal auth / order not found)
                        if (response?.data?.stop_polling || response?.stop_polling) {
                            clearInterval(self.state.popupInterval);
                            const finalState = response?.data?.state || response?.state;
                            if (!popupExists || finalState === 'failed' || finalState === 'cancelled') {
                                redirected = true;
                                self.cleanupPopup();
                                self.showCheckoutError(
                                    response?.message ||
                                    response?.data?.message ||
                                    'Payment verification failed. Please try again.'
                                );
                                self.reset();
                            }
                            return;
                        }

                        const paymentSuccess =
                            response?.success === true ||
                            response?.data?.payment_status === 'success' ||
                            response?.data?.payment_status === 'paid';

                        const redirectUrl =
                            response?.data?.redirect ||
                            response?.redirect;

                        // =========================================
                        // PAYMENT SUCCESS
                        // =========================================
                        if (paymentSuccess && redirectUrl) {

                            redirected = true;

                            clearInterval(self.state.popupInterval);

                            try {

                                if (
                                    self.state.popup &&
                                    !self.state.popup.closed
                                ) {
                                    self.state.popup.close();
                                }

                            } catch (e) {}

                            self.state.popup = null;

                            window.location.replace(redirectUrl);

                            return;
                        }

                        // =========================================
                        // USER MANUALLY CLOSED POPUP
                        // =========================================
                        if (!popupExists) {

                            redirected = true;

                            clearInterval(self.state.popupInterval);

                            const failedMessage =
                                response?.message ||
                                response?.data?.message ||
                                'Your payment could not be completed. Please try again.';

                            self.cleanupPopup();

                            self.showCheckoutError(failedMessage);

                            self.reset();
                        }

                    },
                    'json'
                ).fail(function (xhr, status, error) {
                    // Stop polling if forbidden or bad request
                    if (xhr.status === 403 || xhr.status === 400 || xhr.status === 401 || xhr.status === 429) {
                        clearInterval(self.state.popupInterval);
                    }
                });

            }, 3000);
        },

        /* =========================================================
         * UI
         * ========================================================= */

        showCheckoutError: function (message, fields = []) {

            // Clear previous bank transfer notices first
            $('.woocommerce-notices-wrapper.bank-transfer-error-wrap').remove();

            const $wrapper = $('<div class="woocommerce-notices-wrapper bank-transfer-error-wrap"></div>');
            const $box = $('<div class="woocommerce-error bank-transfer-error-box" role="alert"></div>');
            const $header = $('<div class="bank-transfer-error-header"></div>');
            const $strong = $('<strong></strong>').text(message || '');
            $header.append($strong);
            $box.append($header);

            if (Array.isArray(fields) && fields.length) {
                const $ul = $('<ul class="bank-transfer-error-fields"></ul>');
                fields.forEach(function (field) {
                    $ul.append($('<li></li>').text(field));
                });
                $box.append($ul);
            }

            $wrapper.append($box);

            // Block checkout
            const blockTarget = $('.wc-block-checkout__form');

            if (blockTarget.length) {
                blockTarget.prepend($wrapper);
            } else {
                const classicTarget = $('form.checkout');
                if (classicTarget.length) {
                    classicTarget.prepend($wrapper);
                } else {
                    $('body').prepend($wrapper);
                }
            }

            // Scroll to top notice
            const $notice = $('.woocommerce-notices-wrapper.bank-transfer-error-wrap');

            if ($notice.length) {

                $('html, body').animate({
                    scrollTop: $notice.offset().top - 80
                }, 400);
            }
        },

        clearCheckoutErrors: function () {

            $('.woocommerce-notices-wrapper').remove();

            $('.woocommerce-error').remove();

            $('.wc-block-components-notice-banner').remove();

            $('.woocommerce-message').remove();

            $('.woocommerce-info').remove();
        },

        reset: function (keepDisabled = false) {

            this.state.submitting = false;

            const $blockButton = $(
                '.wc-block-components-checkout-place-order-button'
            );

            const $classicButton = $(
                'button[name="woocommerce_checkout_place_order"]'
            );

            const $button = $blockButton.length
                ? $blockButton
                : $classicButton;

            if (!$button.length) {
                return;
            }

            if (keepDisabled) {

                $button
                    .prop('disabled', true)
                    .addClass('loading')
                    .text('Processing...');

                return;
            }

            $button
                .prop('disabled', false)
                .removeClass('loading')
                .text(
                    this.state.buttonText || 'Place order'
                );
        },

        /* =========================================================
         * SANITIZATION
         * ========================================================= */

        bindInputSanitization: function () {
            const selectors = `
                #billing_first_name,
                #billing-first_name,
                #billing_last_name,
                #billing-last_name,
                #billing_city,
                #billing-city,
                input[name="billing_first_name"],
                input[name="billing_last_name"],
                input[name="billing_city"]
            `;

            const sanitizeInput = (input) => {
                const clean = input.value.replace(
                    /[^A-Za-z\s]/g,
                    ''
                );

                if (input.value !== clean) {
                    Object.getOwnPropertyDescriptor(
                        HTMLInputElement.prototype,
                        'value'
                    ).set.call(input, clean);

                    input.dispatchEvent(
                        new Event('input', { bubbles: true })
                    );
                }
            };

            $(document).on(
                'input keyup blur change paste',
                selectors,
                function () {
                    const input = this;
                    setTimeout(() => {
                        sanitizeInput(input);
                    }, 0);
                }
            );
            

            $('#billing_address_1')
                .on('input', function () {

                    this.value = this.value.replace(
                        /[^A-Za-z0-9\s,.\-#]/g,
                        ''
                    );
                });
        }
    };

    $(document).ready(function () {

        BankTransferCheckout.init();
    });

})(jQuery, window, document);