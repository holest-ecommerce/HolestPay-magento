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
    
    var obj = Object.assign({
        // Place your global frontend JavaScript here. This object is available on all pages.
        version: '1.0.0',
        POS: null,
        hpaylang: 'en',
        init: function () {
            if(this.__init_done) return;
            // Initialize POS data from window.HolestPayCheckout.POS if available
            if (window.HolestPayCheckout && window.HolestPayCheckout.POS) {
                this.POS = window.HolestPayCheckout.POS;
                if(this.POS){
                    this.__init_done = true;
                }
                console.log('HolestPayCheckout POS data loaded:', this.POS);
            }
            
            // Set language
            if (window.HolestPayCheckout && window.HolestPayCheckout.hpaylang) {
                this.hpaylang = window.HolestPayCheckout.hpaylang;
            }
            
            // Initialize footer logotypes if enabled
            if(window.HolestPayCheckout && window.HolestPayCheckout.insert_footer_logotypes){
                this.initFooterLogotypes();
            }   
        },
        
        /**
         * Initialize footer logotypes if enabled
         */
        initFooterLogotypes: function() {
            // Check if footer logotypes are enabled in checkout config
            if (window.HolestPayCheckout && 
                window.HolestPayCheckout.insert_footer_logotypes) {
                
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
            setTimeout(function(){
                if(typeof HolestPayCheckout !== 'undefined' && HolestPayCheckout && HolestPayCheckout.POS && HolestPayCheckout.POS.pos_parameters){
                    
                    let card_images_html = '';
                    let banks_html = '';
                    let threes_html = '';
                
                    if(HolestPayCheckout.POS.pos_parameters['Logotypes Card Images']){
                        let card_images = HolestPayCheckout.POS.pos_parameters['Logotypes Card Images'].split("\n");
                        for(let i = 0; i < card_images.length; i++){
                            card_images_html += '<img style="height:22px;" src="' + card_images[i] + '" alt="Card" />';
                        }
                    }
                    
                    if(HolestPayCheckout.POS.pos_parameters['Logotypes Banks']){
                        let banks = HolestPayCheckout.POS.pos_parameters['Logotypes Banks'].split("\n");
                        for(let i = 0; i < banks.length; i++){
                            let t = banks[i].replace(/https:/gi,'-PS-').replace(/http:/gi,'-P-').split(":").map(r=>r.replace("-P-","http:").replace("-PS-","https:"));
                            if(t.length > 1){
                                banks_html += '<a href="' + t[1] + '" target="_blank"><img style="height:22px;" src="' + t[0] + '" alt="Bank" /></a>';
                            }else{
                                banks_html += '<img style="height:22px;" src="' + t[0] + '" alt="Bank" />';
                            }
                        }
                    }
                
                    if(HolestPayCheckout.POS.pos_parameters['Logotypes 3DS']){
                        let threes = HolestPayCheckout.POS.pos_parameters['Logotypes 3DS'].split("\n");
                        for(let i = 0; i < threes.length; i++){
                            let t = threes[i].replace(/https:/gi,'-PS-').replace(/http:/gi,'-P-').split(":").map(r=>r.replace("-P-","http:").replace("-PS-","https:"));
                            if(t.length > 1){
                                threes_html += '<a href="' + t[1] + '" target="_blank"><img style="height:22px;" src="' + t[0] + '" alt="3DS" /></a>';
                            }else{
                                threes_html += '<img style="height:22px;" src="' + t[0] + '" alt="3DS" />';
                            }
                        }
                    }		
                    
                    let logotypes_footer_html = '<div class="hpay-footer-branding-cards">' + card_images_html + '</div><div style="padding: 0 30px;" class="hpay-footer-branding-bank">' + banks_html + '</div><div class="hpay-footer-branding-3ds">' + threes_html + '</div>';
                    let logotypes_div = document.createElement("div");
                    logotypes_div.style.display = 'flex';
                    logotypes_div.style.justifyContent = 'center';
                    logotypes_div.style.padding = '4px 0';
                    logotypes_div.style.background = '#ededed';
                    logotypes_div.className = 'hpay_footer_branding';
                    logotypes_div.innerHTML = logotypes_footer_html;
                    
                    (document.querySelector('footer') || document.querySelector('body')).appendChild(logotypes_div); 
                        
                }
            },150);
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
        

    }, window.HolestPayCheckout || {}); 
    
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


