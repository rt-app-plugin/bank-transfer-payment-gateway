(function () {
    const { registerPaymentMethod } = window.wc?.wcBlocksRegistry || {};
    const { createElement, RawHTML } = window.wp?.element || {};

    if (typeof registerPaymentMethod !== 'function') {
        return;
    }

    const settings =
        window.wc?.wcSettings?.getPaymentMethodData?.('bank_transfer') || {};

    const label = settings.title || 'Bank Transfer';
    const description = settings.description || '';

    const methodConfig = {
        name: settings.id || 'bank_transfer',
        label,
        ariaLabel: label,

        content: createElement(
            'div',
            { className: 'bank-transfer-description' },
            createElement(RawHTML, {}, description)
        ),

        edit: createElement(
            'div',
            { className: 'bank-transfer-edit' },
            label
        ),

        canMakePayment: async () => {
            return settings.can_pay !== false;
        },

        supports: {
            features: settings.supports || ['products'],
        },
    };
    if(settings.title && settings.isActive){
        registerPaymentMethod(methodConfig);
    }
    
})();


// Call this function whenever you want to refresh payment methods in the block checkout
function refreshBlockPaymentMethods() {
    if (window.wc && window.wc.blocksCheckout) {
        // For WC Blocks 8.x+ (newer API)
        document.body.dispatchEvent(new CustomEvent('wc-blocks_checkout_update_payment_methods'));
    } else {
        // Fallback for older versions
        $(document.body).trigger('update_checkout');
    }
}