define([
    'Magento_Checkout/js/view/payment/default'
], function (Component) {
    'use strict';
    return Component.extend({
        defaults: {
            template: 'HolestPay_HolestPay/payment/holestpay'
        },
        getCode: function () { return 'holestpay'; },
        getTitle: function () { return 'HolestPay'; },
        getSubOptions: function () {
            return [
                { code: 'option_a', label: 'Option A' },
                { code: 'option_b', label: 'Option B' },
                { code: 'option_c', label: 'Option C' }
            ];
        }
    });
});


