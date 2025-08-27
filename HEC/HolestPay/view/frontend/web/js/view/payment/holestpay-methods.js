define(
    [
		'uiComponent',
        'Magento_Checkout/js/model/payment/renderer-list'
    ],
    function (
		Component, 
        rendererList
    ) {
        'use strict';
        rendererList.push(
            {
                type: 'holestpay',
                component: 'HEC_HolestPay/js/view/payment/method-renderer/holestpay'
		    }
        );
	    /** Add view logic here if needed */
        return Component.extend({});
    }
);