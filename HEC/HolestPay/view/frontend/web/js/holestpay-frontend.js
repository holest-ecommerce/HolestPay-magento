(function () {
    // Global utility functions for HolestPay
    window.hpay_isTruthy = function(value) {
        if (value === null || value === undefined) return false;
        if (typeof value === 'boolean') return value === true;
        if (typeof value === 'string') {
            var str = value.toLowerCase().trim();
            return str === 'true' || str === '1' || str === 'yes' || str === 'on';
        }
        if (typeof value === 'number') return value === 1;
        return false;
    };
    
    window.hpay_isFalsy = function(value) {
        if (value === null || value === undefined) return false;
        if (typeof value === 'boolean') return value === false;
        if (typeof value === 'string') {
            var str = value.toLowerCase().trim();
            return str === 'false' || str === '0' || str === 'no' || str === 'off';
        }
        if (typeof value === 'number') return value === 0;
        return false;
    };
    
    var obj = window.HolestPayCheckout || {
        // Place your global frontend JavaScript here. This object is available on all pages.
        version: '1.0.0',
        POS: null,
        hpaylang: 'en',
        init: function () {
            // Initialize POS data from window.HolestPayCheckout.POS if available
            if (window.HolestPayCheckout && window.HolestPayCheckout.POS) {
                this.POS = window.HolestPayCheckout.POS;
                console.log('HolestPayCheckout POS data loaded:', this.POS);
            }
            
            // Set language
            if (window.HolestPayCheckout && window.HolestPayCheckout.hpaylang) {
                this.hpaylang = window.HolestPayCheckout.hpaylang;
            }
            
            // Initialize footer logotypes if enabled
            this.initFooterLogotypes();
        },
        
        /**
         * Initialize footer logotypes if enabled
         */
        initFooterLogotypes: function() {
            // Check if footer logotypes are enabled in checkout config
            if (window.checkoutConfig && 
                window.checkoutConfig.payment && 
                window.checkoutConfig.payment.holestpay && 
                window.checkoutConfig.payment.holestpay.insertFooterLogotypes) {
                
                console.log('HolestPay: Footer logotypes enabled');
                this.setupFooterLogotypes();
            } else {
                console.log('HolestPay: Footer logotypes disabled or not configured');
            }
        },
        
        /**
         * Setup footer logotypes display
         */
        setupFooterLogotypes: function() {
            // This will be handled by the FooterLogotypes block in PHP
            // The block automatically renders the logotypes when enabled
            console.log('HolestPay: Footer logotypes setup complete');
        },
        
        /**
         * Get available payment methods from POS data
         */
        getPaymentMethods: function() {
            if (!this.POS || !this.POS.payment) {
                return [];
            }
            
            return this.POS.payment.filter(function(method) {
                // Only show enabled methods that are not hidden
                return window.hpay_isTruthy(method.Enabled) && !window.hpay_isFalsy(method.Hidden);
            });
        },
        
        /**
         * Get localized text for payment method
         */
        getLocalizedText: function(method, field) {
            if (method.localized && method.localized[this.hpaylang]) {
                return method.localized[this.hpaylang][field] || method[field] || '';
            }
            return method[field] || '';
        },
        
        /**
         * Check if method supports Card-on-File
         */
        supportsCOF: function(method) {
            return method.SubsciptionsType && 
                   /mit|cof/.test(method.SubsciptionsType);
        },
        
        /**
         * Get customer tokens for logged-in users
         */
        getCustomerTokens: function() {
            if (!this.context.customerEmail) {
                return [];
            }
            
            // This would be populated by the payment method renderer
            return this.context.customerTokens || [];
        },
        
        /**
         * Remove customer token
         */
        removeToken: function(tokenValue, callback) {
            if (!this.context.customerEmail) {
                if (callback) callback(false, 'Customer not logged in');
                return;
            }
            
            fetch('/holestpay/ajax/user?action=remove_token&token_value=' + encodeURIComponent(tokenValue), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                }
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Remove from local context
                    this.context.customerTokens = this.context.customerTokens.filter(function(token) {
                        return token.token_value !== tokenValue;
                    });
                }
                if (callback) callback(data.success, data.message);
            })
            .catch(error => {
                if (callback) callback(false, 'Error removing token: ' + error.message);
            });
        },
        
        /**
         * Set token as default
         */
        setDefaultToken: function(tokenValue, callback) {
            if (!this.context.customerEmail) {
                if (callback) callback(false, 'Customer not logged in');
                return;
            }
            
            fetch('/holestpay/ajax/user?action=set_default_token&token_value=' + encodeURIComponent(tokenValue), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                }
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Update local context
                    this.context.customerTokens.forEach(function(token) {
                        token.is_default = (token.token_value === tokenValue);
                    });
                }
                if (callback) callback(data.success, data.message);
            })
            .catch(error => {
                if (callback) callback(false, 'Error setting default token: ' + error.message);
            });
        },
        

    };
    
    // Ensure a mutable context to store order/customer info
    obj.context = obj.context || { 
        orderId: null, 
        customerEmail: null,
        customerTokens: []
    };
    
    // Expose only the correctly spelled global
    window.HolestPayCheckout = obj;

    if (typeof window.HolestPayCheckout.init === 'function') {
        try { window.HolestPayCheckout.init(); } catch (e) { /* no-op */ }
    }

    let adapted_checkout_destroy = null;
	let prev_hpay_shipping_method = null;

   

    window.setup_checkout_address_input = (is_script_loaded) => {
        if(!is_script_loaded && window.setup_checkout_address_input_done) return;
        window.setup_checkout_address_input_done = true;

        if(typeof HPayInit !== 'undefined'){
            
            HPayInit().then(client =>{
                client.loadHPayUI().then(ui_loaded =>{
                    window.doAdaptCheckout = async() => {
                        if(HolestPayCheckout.cart.shipping_method){
                            if(prev_hpay_shipping_method && prev_hpay_shipping_method.HPaySiteMethodId == window.hpay_selected_shipping_method){
                                return;	
                            }

                            let smethod = HPay.POS.shipping.find(s => s.HPaySiteMethodId == HolestPayCheckout.cart.shipping_method);
                            if(smethod && smethod.AdaptCheckout){
                                try{
                                    adapted_checkout_destroy = smethod.AdaptCheckout({
                                        billing: {
                                            postcode: "*[class*='billing'] input[name='postcode']",
                                            phone: "*[class*='billing'] input[name='telephone']",
                                            country: "*[class*='billing'] input[name='country_id'],*[class*='billing'] select[name='country_id']",
                                            city: "*[class*='billing'] input[name='city']",
                                            address: "*[class*='billing'] input[name='street[0]']",
                                            address_num: "*[class*='billing'] input[name='street[1]']"	
                                        },
                                        shipping:{
                                            postcode: "*[class*='shipping'] input[name='postcode']",
                                            phone: "*[class*='shipping'] input[name='telephone']",
                                            country: "*[class*='shipping'] input[name='country_id'],*[class*='shipping'] select[name='country_id']",
                                            city: "*[class*='shipping'] input[name='city']",
                                            address: "*[class*='shipping'] input[name='street[0]']",
                                            address_num: "*[class*='shipping'] input[name='street[1]']"
                                        }
                                    }) || null;
                                }catch(ex){
                                    console.log(ex)
                                }
                            }
                            prev_hpay_shipping_method = smethod
                        }else{
                            if(adapted_checkout_destroy && (typeof adapted_checkout_destroy === 'function' || adapted_checkout_destroy.then)){
                                try{
                                    if(adapted_checkout_destroy.then){
                                        adapted_checkout_destroy = await adapted_checkout_destroy;
                                    }
                                    if(typeof adapted_checkout_destroy === 'function')
                                        adapted_checkout_destroy();
                                    
                                    adapted_checkout_destroy = null;
                                }catch(ex){
                                    
                                }
                            }
                            prev_hpay_shipping_method = null;
                        }
                    };
                    window.doAdaptCheckout();
                });
            });

        }else{
            let scriptUrl = 'https://' + (window.HolestPayCheckout.environment == 'sandbox' ? 'sandbox.' : '') + 'pay.holest.com/clientpay/cscripts/hpay.js';
            let script = document.createElement('script');
            script.src = scriptUrl;
            script.async = true;
            script.onload = function() {
                window.setup_checkout_address_input(true);
            };
            document.head.appendChild(script);
        }
    };

    window._hpay_selected_shipping_method = () => {
        let candidate = document.querySelector("div[class*='shipping'] input[value^='holestpay_']:checked");
        if(candidate && HolestPayCheckout.POS.shipping){
                let m = HolestPayCheckout.POS.shipping.find(function(s) {
                    return ("holestpay_" + s.HPaySiteMethodId) === candidate.value;
                });
                if(m){
                    if(!candidate.getAttribute("sm_options_added")){
                        candidate.setAttribute("sm_options_added", "1");
                        let sm_opt = document.createElement("span");
                        sm_opt.setAttribute("class", "hpay_sm_options");
                        sm_opt.setAttribute("hpay_site_shipping_method", candidate.value);
                        sm_opt.setAttribute("hpay_shipping_method_id", m.HPaySiteMethodId);
                        candidate.parentNode.appendChild(sm_opt);
                    }
                    window.hpay_selected_shipping_method = parseInt(String(candidate.value).replace('holestpay_', ''));
                    HolestPayCheckout.cart.shipping_method = window.hpay_selected_shipping_method;
                    sessionStorage.hpay_selected_shipping_method = window.hpay_selected_shipping_method;
                    if(window.doAdaptCheckout){
                        window.doAdaptCheckout();
                    }else{
                        window.setup_checkout_address_input();
                    }
                    return;
                }
        }
        window.hpay_selected_shipping_method = '';
        if(HolestPayCheckout.cart && HolestPayCheckout.cart.shipping_method){
            delete HolestPayCheckout.cart.shipping_method;
        }
        sessionStorage.hpay_selected_shipping_method = '';
    };

    let doshook = () => { 
        window._hpay_selected_shipping_method();
        document.querySelectorAll("div[class*='shipping']").forEach(function(spanel) {      
            if(spanel.getAttribute('data-hpay-shipping-method-hooked') == '1'){
                return;
            }
            spanel.setAttribute('data-hpay-shipping-method-hooked', '1');
            spanel.addEventListener('change', function(e) {
                _hpay_selected_shipping_method();
            });
        });  
    };

    document.addEventListener('DOMContentLoaded', function() {
        window._hpay_selected_shipping_method();

        [500,1000,1500,2500,5500,10500].forEach(function(delay) {
            setTimeout(doshook, delay);
        });


      });
})();


