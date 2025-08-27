define([
    'Magento_Checkout/js/view/payment/default',
    'ko'
], function (Component, ko) {
    'use strict';
    
    console.log('HolestPay payment method renderer loaded');
    
    return Component.extend({
        defaults: {
            template: 'HEC_HolestPay/payment/holestpay'
        },

         /**
         * Get selected token for a payment method
         */
        selectedTokenForMethod: ko.observable('new'),
        selectedSubMethod: ko.observable(null),
        initObservable: function () {
            console.log('HolestPay payment method initObservable called');
            this._super();
           
            this.selectedSubMethod.subscribe(function(newValue) {
                if (newValue) {
                    this.selectPaymentMethod();
                    this.selectedTokenForMethod('new');
                    
                    // Set payment method dock when sub-method is selected
                    this.setPaymentMethodDockOnSelection(newValue);
                }
            }, this); 

            this.isChecked.subscribe(function(newValue) {  
                if(newValue != 'holestpay'){
                    this.selectedSubMethod(null);
                }
            },this);     

            // Clear any existing order IDs to start fresh
            this.clearStoredOrderId();
            
            // Initialize customer tokens
            this.initCustomerTokens();
            
            // Initialize default translations
            this.initDefaultTranslations();
            
            // Load HolestPay script based on environment
            this.loadHolestPayScript();
            
            // Set up HolestPay event listeners
            this.setupHolestPayEventListeners();
            
            // Debug quote structure
            this.debugQuoteStructure();
            
            console.log('HolestPay payment method initialized');
            return this;
        },
        
        getCode: function () { 
            console.log('HolestPay getCode called, returning: holestpay');
		    return 'holestpay'; 
        },
        
        getTitle: function () { 
            console.log('HolestPay getTitle called, returning: HolestPay');
            return 'HolestPay'; 
        },
        
        getEnvironment: function () { 
            return HolestPayCheckout.environment;
        },
        
        getMerchantUid: function () { 
            return HolestPayCheckout.merchant_site_uid;
        },
        
        getSubOptions: function () {
            console.log('HolestPay getSubOptions called');
            
            // Get payment methods from HolestPayCheckout.POS
            var paymentMethods = [];
            if (window.HolestPayCheckout && window.HolestPayCheckout.getPaymentMethods) {
                paymentMethods = window.HolestPayCheckout.getPaymentMethods();
                console.log('Raw payment methods:', paymentMethods);
            } else {
                console.warn(this.getTranslatedText('Payment system unavailable'));
            }
            
            // Convert to sub-options format
            var subOptions = [];
            paymentMethods.forEach(function(method, index) {
                var localizedName = window.HolestPayCheckout.getLocalizedText(method, 'Name') || method.Name || method.SystemTitle;
                var localizedDesc = window.HolestPayCheckout.getLocalizedText(method, 'Description') || method.Description || '';
                
                subOptions.push({
                    code: method.HPaySiteMethodId.toString(),
                    magentoId: 'holestpay_' + method.HPaySiteMethodId.toString(),
                    label: localizedName,
                    description: localizedDesc,
                    method: method,
                    supportsCOF: window.HolestPayCheckout.supportsCOF(method)
                });
            });
            
            console.log('HolestPay sub options:', subOptions);
            return subOptions;
        },
        isVisible:function(){
            return true;
        },
        /**
         * Get selected sub method (HolestPay ID)
         */
        getSelectedSubMethod: function () {
            var method = this.selectedSubMethod();
            return method;
        },
        
        /**
         * Get selected sub method Magento ID
         */
        getSelectedSubMethodMagentoId: function () {
            var methodId = this.selectedSubMethod();
            var subOptions = this.getSubOptions();
            var methodData = subOptions.find(function(option) {
                return option.code === methodId;
            });

            //var methodData = this.selectedSubMethodData();
            if (methodData && methodData.magentoId) {
                return methodData.magentoId;
            }
            return null;
        },
        
        /**
         * Check if selected payment method has PayInputUrl
         */
        getSelectedPaymentMethodPayInputUrl: function () {
            var selectedMethod = this.selectedSubMethod();
            if (!selectedMethod || !window.HolestPayCheckout || !window.HolestPayCheckout.POS || !window.HolestPayCheckout.POS.payment) {
                return false;
            }
            
            try {
                var paymentMethod = window.HolestPayCheckout.POS.payment.find(function(pm) {
                    return pm.HPaySiteMethodId == selectedMethod;
                });
                
                return paymentMethod && paymentMethod.PayInputUrl;
            } catch (e) {
                console.warn(this.getTranslatedText('Validation error') + ':', e);
                return false;
            }
        },
        
        afterPlaceOrder: function(){
            console.log('HolestPay afterPlaceOrder called');
            // This is a debug alert, should not be shown in production
            if (window.HolestPayCheckout && window.HolestPayCheckout.debug) {
                alert(this.getTranslatedText('Order placed successfully'));
            }
            return true;
        },
        /**
         * Get payment method data
         */
        getData: function () {
            var selectedMethod = this.getSelectedSubMethod();
            var selectedToken = this.selectedTokenForMethod();
            //var selectedMethodData = this.selectedSubMethodData();
            var subOptions = this.getSubOptions();
            var selectedMethodData = subOptions.find(function(option) {
                return option.code === selectedMethod;
            });
            
            var data = {
                'method': this.item.method,
                'additional_data': {
                    'holestpay_submethod': selectedMethod,
                    'holestpay_token': selectedToken !== 'new' ? selectedToken : null
                }
            };
            
            // Add payment method details from stored data
            if (selectedMethodData && selectedMethodData.method) {
                data.additional_data.holestpay_method_uid = selectedMethodData.method.Uid;
                data.additional_data.holestpay_payment_type = selectedMethodData.method.PaymentType;
                data.additional_data.holestpay_supports_cof = selectedMethodData.supportsCOF;
            }
            
            console.log('HolestPay getData called, returning:', data);
            return data;
        },
        
        /**
         * Validate payment method
         */
        validate: function () {
            // Basic validation - ensure a sub method is selected
            if (!this.getSelectedSubMethod()) {
                console.log('HolestPay validation failed: no sub method selected');
                return false;
            }
            
            // Ensure the master method is checked when a submethod is selected
            if (!this.isChecked()) {
                this.selectPaymentMethod();
            }
            
            console.log('HolestPay validation passed');
            return true;
        },
        
        /**
         * Check if payment method is available
         */
        isAvailable: function () {
            var subOptions = this.getSubOptions();
            return subOptions && subOptions.length > 0;
        },
        
        /**
         * Check if submethods should be visible (always true)
         */
        areSubmethodsVisible: function () {
            return true;
        },
        
        /**
         * Check if customer is logged in
         */
        isCustomerLoggedIn: function() {
            return window.checkoutConfig.isCustomerLoggedIn || false;
        },
        
        /**
         * Get customer tokens for a specific payment method
         */
        getCustomerTokensForMethod: function(methodCode) {
            if (!this.isCustomerLoggedIn()) {
                return [];
            }
            
            try {
                var tokens = window.HolestPayCheckout.getCustomerTokens();
                if (tokens && Array.isArray(tokens)) {
                    return tokens.filter(function(token) {
                        return token.payment_method_id === methodCode;
                    });
                }
            } catch (e) {
                console.warn(this.getTranslatedText('General error') + ':', e);
            }
            
            return [];
        },
        
       
        
        /**
         * Get selected token for a specific payment method
         */
        getSelectedTokenForMethod: function(methodCode) {
            return this.selectedTokenForMethod();
        },
        
        /**
         * Remove customer token
         */
        removeToken: function(tokenValue) {
            window.HolestPayCheckout.removeToken(tokenValue, function(success, message) {
                if (success) {
                    // Refresh the page or update the UI
                    location.reload();
                } else {
                    var errorMsg = this.getTranslatedText('Error') + ': ' + message;
                    alert(errorMsg);
                }
            }.bind(this));
        },
        
        /**
         * Set token as default
         */
        setDefaultToken: function(tokenValue) {
            window.HolestPayCheckout.setDefaultToken(tokenValue, function(success, message) {
                if (success) {
                    // Refresh the page or update the UI
                    location.reload();
                } else {
                    var errorMsg = this.getTranslatedText('Error') + ': ' + message;
                    alert(errorMsg);
                }
            }.bind(this));
        },
        
        /**
         * Initialize payment with HolestPay
         */
        initPayment: function() {
            if (this.validate()) {
                console.log('HolestPay initPayment called');
                
                // Create pending order first to get proper order ID
                this.createPendingOrder().then(function(orderData) {
                    if (!orderData || !orderData.order_id) {
                        console.error('HolestPay: Failed to create pending order');
                        return;
                    }
                    
                    console.log('HolestPay: Pending order created with ID:', orderData.order_id);
                    
                    // Get checkout data with the real order ID
                    var checkoutData = this.getCheckoutData(orderData.order_id);
                    if (!checkoutData) {
                        console.error('HolestPay: Unable to get checkout data');
                        return;
                    }
                    
                    // Initialize HolestPay client and process payment
                    this.processHolestPayPayment(checkoutData);
                    
                }.bind(this)).catch(function(error) {
                    console.error('HolestPay: Error creating pending order:', error);
                    // Show error to user
                    this.showPaymentError('Failed to create order. Please try again.', { status: 'ERROR' });
                }.bind(this));
            }
        },
        
        /**
         * Create a pending order to get a proper order ID
         */
        createPendingOrder: function() {
            var self = this;
            
            return new Promise(function(resolve, reject) {
                // Check if we already have a pending order ID stored
                var existingOrderId = self.getStoredOrderId();
                if (existingOrderId) {
                    console.log('HolestPay: Using existing order ID:', existingOrderId);
                    resolve({ order_id: existingOrderId });
                    return;
                }
                
                console.log('HolestPay: Creating new pending order...');
                
                // Create order via AJAX
                self.createOrderViaAjax().then(resolve).catch(reject);
            });
        },
        
        /**
         * Get stored order ID from multiple sources
         */
        getStoredOrderId: function() {
            // Try component instance first (most reliable)
            if (this.currentOrderId) {
                console.log('HolestPay: Found order ID in component instance:', this.currentOrderId);
                return this.currentOrderId;
            }
            
            // Try sessionStorage (primary storage)
            try {
                var sessionOrderId = sessionStorage.getItem('holestpay_order_id');
                if (sessionOrderId) {
                    console.log('HolestPay: Found order ID in sessionStorage:', sessionOrderId);
                    // Also update component instance for consistency
                    this.currentOrderId = sessionOrderId;
                    return sessionOrderId;
                }
            } catch (e) {
                console.warn('HolestPay: sessionStorage not available:', e.message);
            }
            
            // Try localStorage as fallback
            try {
                var localOrderId = localStorage.getItem('holestpay_order_id');
                if (localOrderId) {
                    console.log('HolestPay: Found order ID in localStorage:', localOrderId);
                    // Also update component instance for consistency
                    this.currentOrderId = localOrderId;
                    return localOrderId;
                }
            } catch (e) {
                console.warn('HolestPay: localStorage not available:', e.message);
            }
            
            // Try to get from checkout session if available
            if (window.checkout && window.checkout.quote && window.checkout.quote.quote_id) {
                var quoteOrderId = window.checkout.quote.quote_id;
                console.log('HolestPay: Found order ID from checkout quote:', quoteOrderId);
                // Store this in our component instance
                this.currentOrderId = quoteOrderId;
                return quoteOrderId;
            }
            
            return null;
        },
        
        /**
         * Store order ID in multiple locations for persistence
         */
        storeOrderId: function(orderId) {
            if (!orderId) {
                console.warn('HolestPay: Cannot store empty order ID');
                return;
            }
            
            // Always store in component instance (most reliable)
            this.currentOrderId = orderId;
            
            // Try to store in sessionStorage (primary storage)
            try {
                sessionStorage.setItem('holestpay_order_id', orderId);
                console.log('HolestPay: Stored order ID in sessionStorage:', orderId);
            } catch (e) {
                console.warn('HolestPay: Could not store order ID in sessionStorage:', e.message);
            }
            
            // Try to store in localStorage as backup
            try {
                localStorage.setItem('holestpay_order_id', orderId);
                console.log('HolestPay: Stored order ID in localStorage:', orderId);
            } catch (e) {
                console.warn('HolestPay: Could not store order ID in localStorage:', e.message);
            }
            
            console.log('HolestPay: Order ID stored successfully in component instance:', orderId);
        },
        
        /**
         * Create order via AJAX using Magento's standard order placement
         */
        createOrderViaAjax: function() {
            var self = this;
            
            return new Promise(function(resolve, reject) {
                // Make AJAX call to create order - Magento will handle everything from the session
                fetch('/holestpay/ajax/createorder', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: JSON.stringify({
                        // Minimal data - Magento will get everything else from the checkout session
                        create_pending: true
                    })
                })
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Network response was not ok: ' + response.status);
                    }
                    return response.json();
                })
                .then(data => {
                    if (data.success && data.order_id) {
                        console.log('HolestPay: Order created via AJAX with ID:', data.order_id);
                        // Store the order ID in session storage
                        self.storeOrderId(data.order_id);
                        resolve({ order_id: data.order_id });
                    } else {
                        console.error('HolestPay: AJAX order creation failed:', data.error || 'Unknown error');
                        reject(new Error(data.error || 'Failed to create order'));
                    }
                })
                .catch(error => {
                    console.error('HolestPay: Error creating order via AJAX:', error);
                    reject(error);
                });
            });
        },
        

        
        /**
         * Get checkout data for payment request
         */
        getCheckoutData: function(orderUid, fordock) {
            try {

                if(!orderUid){
                    orderUid = "";
                }

                var quote = window.checkoutConfig.quoteData;
                var selectedMethod = this.getSelectedSubMethod();
                
                // Debug: Log the full quote data and checkout config
                console.log('HolestPay: Full quote data:', quote);
                console.log('HolestPay: Full checkout config:', window.checkoutConfig);
                console.log('HolestPay: Selected method:', selectedMethod);
                console.log('HolestPay: Using order ID:', orderUid);
                
                if (!quote || !selectedMethod || (!fordock && !orderUid)) {
                    console.error('HolestPay: Missing quote, selected method, or order ID');
                    return null;
                }
                
                // Get payment method details
                var paymentMethod = null;
                if (window.HolestPayCheckout && window.HolestPayCheckout.POS && window.HolestPayCheckout.POS.payment) {
                    paymentMethod = window.HolestPayCheckout.POS.payment.find(function(pm) {
                        return pm.HPaySiteMethodId == selectedMethod;
                    });
                }
                
                if (!paymentMethod) {
                    console.error('HolestPay: Payment method not found');
                    return null;
                }
                
                // Get vault token UID if method supports COF and token is selected
                var vaultTokenUid = '';
                var selectedToken = this.selectedTokenForMethod();
                var supportsCOF = window.HolestPayCheckout.supportsCOF(paymentMethod);
                
                if (supportsCOF && selectedToken && selectedToken !== 'new') {
                    vaultTokenUid = selectedToken;
                }
                
                var checkoutData = {
                    merchant_site_uid: window.HolestPayCheckout.merchant_site_uid,
                    hpaylang: window.HolestPayCheckout.hpaylang || 'en',
                    order_uid: orderUid, // Use the provided order ID
                    order_name: 'Order #' + orderUid,
                    order_amount: parseFloat(quote.grand_total), // Use amount as-is (in dollars)
                    order_currency: quote.quote_currency_code || 'USD',
                    order_user_url: window.HolestPayCheckout.site_base_url + 'holestpay/result?order_uid=' + orderUid, // Use baseUrl instead of checkoutUrl
                    payment_method: selectedMethod,
                    vault_token_uid: vaultTokenUid
                };

                if(window.HolestPayCheckout.cart){
                    if(window.HolestPayCheckout.cart.order_items){
                        checkoutData.order_items = window.HolestPayCheckout.cart.order_items;
                    }
                    if(window.HolestPayCheckout.cart.order_billing){
                        checkoutData.order_billing = window.HolestPayCheckout.cart.order_billing;
                    }
                    if(window.HolestPayCheckout.cart.order_shipping){
                        checkoutData.order_shipping = window.HolestPayCheckout.cart.order_shipping;
                    }
                }

                let h_shipping_method_id = "";
                try{
                    if(window._hpay_selected_shipping_method)
                        window._hpay_selected_shipping_method();
                    
                    h_shipping_method_id = sessionStorage.hpay_selected_shipping_method;
                }catch(e){
                  //  
                }

                if(!h_shipping_method_id){
                    h_shipping_method_id = window.hpay_selected_shipping_method || "";
                }
                if(h_shipping_method_id){
                    checkoutData.shipping_method = h_shipping_method_id;
                }

                // Debug: Log the generated checkout data
                console.log('HolestPay: Generated checkout data:', checkoutData);
                console.log('HolestPay: Using order_uid:', orderUid);
                console.log('HolestPay: order_uid in checkout data:', checkoutData.order_uid);
                
                return checkoutData;
            } catch (e) {
                console.error('HolestPay: Error getting checkout data:', e);
                return null;
            }
        },
        
        /**
         * Process HolestPay payment
         */
        processHolestPayPayment: function(payRequest) {
            var self = this;
            
            // Check if HPay is available
            if (typeof HPayInit === 'undefined') {
                console.error('HolestPay: HPayInit not available, script may not be loaded');
                return;
            }
            
            // Get signed payment request from server
            self.getSignedPaymentRequest(payRequest).then(function(signedRequest) {
                if (!signedRequest) {
                    console.error('HolestPay: Failed to get signed payment request from server');
                    return;
                }
                
                // Initialize HolestPay client with signed request
                HPayInit(signedRequest.merchant_site_uid, signedRequest.hpaylang, self.getEnvironment()).then(function(client) {
                    console.log('HolestPay client initialized');
                    
                    // Process the payment with signed request
                    self.executePayment(client, signedRequest);
                    
                }).catch(function(error) {
                    console.error('HolestPay: Failed to initialize client:', error);
                });
                
            }).catch(function(error) {
                console.error('HolestPay: Failed to get signed payment request:', error);
            });
        },
        
        /**
         * Set payment method dock when sub-method is selected
         */
        setPaymentMethodDockOnSelection: function(selectedMethod) {
            var self = this;
            
            // Check if HPay is available
            if (typeof HPayInit === 'undefined') {
                console.log('HolestPay: HPayInit not available yet, script may still be loading');
                return;
            }
            
            // Check if Docked Input is enabled
            var dockedInputEnabled = false;
            if (window.HolestPayCheckout && window.HolestPayCheckout.POS && window.HolestPayCheckout.POS.pos_parameters) {
                var dockedInput = window.HolestPayCheckout.POS.pos_parameters["Docked Input"];
                dockedInputEnabled = dockedInput === 1 || dockedInput === true || dockedInput === "1" || dockedInput === "true";
            }
            
            if (!dockedInputEnabled) {
                console.log('HolestPay: Docked Input is not enabled, skipping dock setup');
                return;
            }
            
            // Get payment method details
            var paymentMethod = null;
            if (window.HolestPayCheckout && window.HolestPayCheckout.POS && window.HolestPayCheckout.POS.payment) {
                paymentMethod = window.HolestPayCheckout.POS.payment.find(function(pm) {
                    return pm.HPaySiteMethodId == selectedMethod;
                });
            }
            
            if (!paymentMethod || !paymentMethod.PayInputUrl) {
                console.log('HolestPay: Payment method does not have PayInputUrl, skipping dock setup');
                return;
            }
            
            // Get checkout data for dock
            var checkoutData = this.getCheckoutData(null, true);
            if (!checkoutData) {
                console.error('HolestPay: Unable to get checkout data for dock setup');
                return;
            }
            
            // Initialize HolestPay client and set dock
            HPayInit(checkoutData.merchant_site_uid, checkoutData.hpaylang, this.getEnvironment()).then(function(client) {
                console.log('HolestPay client initialized for dock setup');
                
                // Get vault token UID if method supports COF and token is selected
                var vaultTokenUid = null;
                var selectedToken = self.selectedTokenForMethod();
                var supportsCOF = window.HolestPayCheckout.supportsCOF(paymentMethod);
                
                if (supportsCOF && selectedToken && selectedToken !== 'new') {
                    vaultTokenUid = selectedToken;
                }
                
                // Set payment method dock
                client.setPaymentMethodDock(selectedMethod, {
                    order_amount: checkoutData.order_amount, // may be element, selector or actual value
                    order_currency: checkoutData.order_currency, // may be element, selector or actual value
                    monthly_installments: null, // may be element, selector or actual value
                    vault_token_uid: vaultTokenUid, // may be element, selector or actual value
                    hpaylang: checkoutData.hpaylang
                }, '.hpay-dock'); // cnt - element or selector, defaults to first visible div with data-hpay-dock-payment attribute
                
                console.log('HolestPay payment method dock set for method:', selectedMethod);
            }).catch(function(error) {
                console.error('HolestPay: Failed to initialize client for dock setup:', error);
            });
        },
        
        /**
         * Set payment method dock
         */
        setPaymentMethodDock: function(client, payRequest) {
            try {
                // Get vault token UID if method supports COF and token is selected
                var vaultTokenUid = null;
                var selectedToken = this.selectedTokenForMethod();
                var selectedMethod = this.getSelectedSubMethod();
                var supportsCOF = false;
                
                if (window.HolestPayCheckout && selectedMethod) {
                    var paymentMethod = window.HolestPayCheckout.POS.payment.find(function(pm) {
                        return pm.HPaySiteMethodId == selectedMethod;
                    });
                    if (paymentMethod) {
                        supportsCOF = window.HolestPayCheckout.supportsCOF(paymentMethod);
                    }
                }
                
                if (supportsCOF && selectedToken && selectedToken !== 'new') {
                    vaultTokenUid = selectedToken;
                }
                
                client.setPaymentMethodDock(payRequest.payment_method, {
                    order_amount: payRequest.order_amount,
                    order_currency: payRequest.order_currency,
                    monthly_installments: null,
                    vault_token_uid: vaultTokenUid,
                    hpaylang: payRequest.hpaylang
                }, '.hpay-dock');
                
                console.log('HolestPay payment method dock set');
            } catch (e) {
                console.warn('HolestPay: Error setting payment method dock:', e);
            }
        },
        
        /**
         * Get signed payment request from server
         */
        getSignedPaymentRequest: function(payRequest) {
            var self = this;
            
            return new Promise(function(resolve, reject) {
                // Add additional fields needed for signature
                var requestData = {
                    ...payRequest,
                    transaction_uid: '', // Will be set by server
                    status: '', // Will be set by server
                    subscription_uid: '', // Will be set by server
                    rand: Math.random().toString(36).substring(2, 15) // Random string for signature
                };
                
                // Debug: Log what we're sending
                console.log('HolestPay: Sending payment request data:', requestData);
                console.log('HolestPay: order_uid in request:', requestData.order_uid);
                
                // Make AJAX call to server to get signed request
                fetch('/holestpay/ajax/payment', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: JSON.stringify(requestData)
                })
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Network response was not ok: ' + response.status);
                    }
                    return response.json();
                })
                .then(data => {
                    if (data.success && data.signed_request) {
                        console.log('HolestPay: Got signed payment request from server');
                        resolve(data.signed_request);
                    } else {
                        console.error('HolestPay: Server returned error:', data.error || 'Unknown error');
                        reject(new Error(data.error || 'Failed to get signed request'));
                    }
                })
                .catch(error => {
                    console.error('HolestPay: Error getting signed payment request:', error);
                    reject(error);
                });
            });
        },
        
        /**
         * Execute the payment
         */
        executePayment: function(client, payRequest) {
            var self = this;
            
            console.log('HolestPay: Executing payment with signed request:', payRequest);
            
            // Check if we have a verification hash from the server
            if (payRequest.verificationhash) {
                console.log('HolestPay: Using presentHPayPayForm with server-generated signature');
                
                // Use the HolestPay presentHPayPayForm function with the signed request
                if (typeof presentHPayPayForm === 'function') {
                    presentHPayPayForm(payRequest);
                } else {
                    console.error('HolestPay: presentHPayPayForm function not available');
                    // Fallback to standard Magento place order
                    self.triggerStandardPlaceOrder();
                }
            } else {
                console.log('HolestPay: No verification hash, using standard Magento place order');
                // Fallback to standard Magento place order
                self.triggerStandardPlaceOrder();
            }
        },
        
        /**
         * Trigger standard Magento place order as fallback
         */
        triggerStandardPlaceOrder: function() {
            if (typeof this._super === 'function') {
                this._super();
            } else if (window.checkout && window.checkout.placeOrder) {
                window.checkout.placeOrder();
            }
        },
        
        /**
         * Handle unsuccessful payment response
         */
        handleUnsuccessfulPayment: function(response) {
            console.log('HolestPay: Handling unsuccessful payment:', response);
            
            // Set default status if not provided
            if (!response.status) {
                response.status = "ERROR";
            }
            
            // Determine error message based on status
            var errorMessage = this.getErrorMessageByStatus(response.status);
            
            // Show error message to user
            this.showPaymentError(errorMessage, response);
            
            // Redirect to failure URL if available
            if (response.order_user_url_failure) {
                console.log('HolestPay: Redirecting to failure URL:', response.order_user_url_failure);
                window.location.href = response.order_user_url_failure;
            }
        },
        
        /**
         * Handle successful payment and update order status
         */
        handleSuccessfulPayment: function(response) {
            var self = this;
            var orderId = this.getStoredOrderId();
            
            if (!orderId) {
                console.warn('HolestPay: No order ID found for successful payment');
                return;
            }
            
            console.log('HolestPay: Handling successful payment for order:', orderId);
            
            // Update order status via AJAX
            fetch('/holestpay/ajax/updateorderstatus', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify({
                    order_id: orderId,
                    status: 'success',
                    transaction_uid: response.transaction_uid,
                    hpay_response: response
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    console.log('HolestPay: Order status updated successfully');
                    // Clear stored order ID since payment is complete
                    this.clearStoredOrderId();
                } else {
                    console.warn('HolestPay: Failed to update order status:', data.error);
                }
            })
            .catch(error => {
                console.error('HolestPay: Error updating order status:', error);
            });
        },
        
        /**
         * Get error message based on payment status
         */
        getErrorMessageByStatus: function(status) {
            var statusUpper = status.toUpperCase();
            
            // Try to get translated message from HolestPayCheckout.labels if available
            if (window.HolestPayCheckout && window.HolestPayCheckout.labels) {
                if (/REFUSED/i.test(statusUpper)) {
                    return window.HolestPayCheckout.labels["Payment refused"] || 'Payment refused. You can try again.';
                } else if (/FAILED/i.test(statusUpper)) {
                    return window.HolestPayCheckout.labels["Payment failed"] || 'Payment failed. You can try again.';
                } else if (/CANCELED/i.test(statusUpper)) {
                    return window.HolestPayCheckout.labels["Payment canceled"] || 'Payment canceled. You can try again.';
                } else if (/ERROR/i.test(statusUpper)) {
                    return window.HolestPayCheckout.labels["Payment error"] || 'Payment error occurred. Please try again.';
                } else {
                    return window.HolestPayCheckout.labels["Payment error"] || 'Payment was not successful. Please try again.';
                }
            }
            
            // Fallback to English messages if no translations available
            if (/REFUSED/i.test(statusUpper)) {
                return 'Payment refused. You can try again.';
            } else if (/FAILED/i.test(statusUpper)) {
                return 'Payment failed. You can try again.';
            } else if (/CANCELED/i.test(statusUpper)) {
                return 'Payment canceled. You can try again.';
            } else if (/ERROR/i.test(statusUpper)) {
                return 'Payment error occurred. Please try again.';
            } else {
                return 'Payment was not successful. Please try again.';
            }
        },
        
        /**
         * Show payment error to user
         */
        showPaymentError: function(message, response) {
            // Try to use Magento's message manager if available
            if (window.checkout && window.checkout.messageContainer) {
                window.checkout.messageContainer.addErrorMessage({
                    message: message
                });
            } else {
                // Fallback: show alert
                var errorMsg = this.getTranslatedText('Payment Error') + ': ' + message;
                alert(errorMsg);
            }
            
            // Log error details for debugging
            console.error('HolestPay payment error details:', {
                message: message,
                status: response.status,
                error_code: response.error_code,
                transaction_uid: response.transaction_uid
            });
        },
        
        /**
         * Get translated text from HolestPayCheckout.labels
         */
        getTranslatedText: function(key, fallback) {
            if (window.HolestPayCheckout && window.HolestPayCheckout.labels && window.HolestPayCheckout.labels[key]) {
                return window.HolestPayCheckout.labels[key];
            }
            return fallback || key;
        },
        
        /**
         * Set up HolestPay event listeners
         */
        setupHolestPayEventListeners: function() {
            var self = this;
            
            // Listen for HolestPay panel close
            document.addEventListener('onHPayPanelClose', function(e) {
                console.log('HolestPay panel closed:', e);
                
                setTimeout(function() {
                    if (e && e.hpay_response && e.hpay_response.reason === "") {
                        // Panel closed without reason, might need retry
                        console.log('HolestPay panel closed without reason, considering retry');
                    } else {
                        // Panel closed with reason
                        console.log('HolestPay panel closed with reason:', e.hpay_response ? e.hpay_response.reason : 'unknown');
                    }
                }, 150);
            });
            
            // Listen for HolestPay result
            document.addEventListener('onHPayResult', function(e) {
                console.log('HolestPay result received:', e);
                
                var response = e.hpay_response || null;
                if (!response) {
                    console.error('HolestPay: No response received');
                    return;
                }
                
                // Check if we have a transaction UID
                if (!response.transaction_uid) {
                    console.warn('HolestPay: No transaction UID in response');
                    return;
                }
                
                // Handle successful payment
                if (/SUCCESS|PAID|PAYING|RESERVED|AWAITING|OBLIGATED/i.test(response.status)) {
                    console.log('HolestPay payment successful:', response.status);
                    
                    // Handle successful payment and update order status
                    self.handleSuccessfulPayment(response);
                    
                    // Forward response to site (like WooCommerce pattern)
                    if (response.order_user_url) {
                        self.forwardPaymentResponse(response);
                    } else {
                        // Fallback: trigger standard Magento place order
                        console.log('HolestPay: No success URL, triggering standard place order');
                        self.triggerStandardPlaceOrder();
                    }
                    
                } else {
                    // Handle unsuccessful payment
                    console.error('HolestPay payment failed:', response.status);
                    self.handleUnsuccessfulPayment(response);
                }
            });
        },
        
        /**
         * Load HolestPay script based on environment
         */
        loadHolestPayScript: function() {
            var environment = this.getEnvironment();
            var scriptUrl = '';
            
            if (environment === 'sandbox') {
                scriptUrl = 'https://sandbox.pay.holest.com/clientpay/cscripts/hpay.js';
            } else if (environment === 'production') {
                scriptUrl = 'https://pay.holest.com/clientpay/cscripts/hpay.js';
            } else {
                console.warn('HolestPay: Unknown environment, using sandbox as default');
                scriptUrl = 'https://sandbox.pay.holest.com/clientpay/cscripts/hpay.js';
            }
            
            // Check if script is already loaded
            if (document.querySelector('script[src="' + scriptUrl + '"]')) {
                console.log('HolestPay script already loaded:', scriptUrl);
                return;
            }
            
            // Create and load script
            var script = document.createElement('script');
            script.src = scriptUrl;
            script.async = true;
            script.onload = function() {
                console.log('HolestPay script loaded successfully:', scriptUrl);
                
                // After script loads, check if we need to set up dock for already selected method
                var self = this;
                if (self.selectedSubMethod() && typeof HPayInit !== 'undefined') {
                    // Small delay to ensure script is fully initialized
                    setTimeout(function() {
                        self.setPaymentMethodDockOnSelection(self.selectedSubMethod());
                    }, 100);
                }
            }.bind(this);
            script.onerror = function() {
                console.error(this.getTranslatedText('Script loading failed') + ':', scriptUrl);
            }.bind(this);
            
            // Append to head
            document.head.appendChild(script);
        },
        
        /**
         * Initialize customer tokens
         */
        initCustomerTokens: function() {
            if (this.isCustomerLoggedIn() && window.checkoutConfig.customerData) {
                var customerEmail = window.checkoutConfig.customerData.email;
                if (customerEmail) {
                    window.HolestPayCheckout.context.customerEmail = customerEmail;
                    
                    // Load customer tokens
                    fetch('/holestpay/ajax/user?action=get_tokens', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        }
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            window.HolestPayCheckout.context.customerTokens = data.tokens || [];
                        }
                    })
                    .catch(function(error) {
                        console.error(this.getTranslatedText('General error') + ':', error);
                    }.bind(this));
                }
            }
        },
        
        /**
         * Forward payment response to site (like WooCommerce pattern)
         *
         * @param {Object} response - HolestPay payment response
         */
        forwardPaymentResponse: function(response) {
            var self = this;
            
            console.log('HolestPay: ' + this.getTranslatedText('Forwarding payment response'));
            
            // Get the current order ID for the order_id parameter
            var orderId = this.getStoredOrderId();
            if (!orderId) {
                console.warn('HolestPay: No order ID found for forwarding payment response');
                orderId = '';
            }
            
            // Forward response to site via POST (like WooCommerce hpay_forwarded_payment_response)
            fetch(window.location.origin + '/holestpay/result', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'
                },
                body: new URLSearchParams({
                    'hpay_forwarded_payment_response': JSON.stringify(response),
                    'hpay_local_request': '1',
                    'order_id': orderId
                })
            })
            .then(function(r) {
                return r.json();
            })
            .then(function(data) {
                console.log('HolestPay: ' + self.getTranslatedText('Site response received'), data);
                
                if (data.received === 'OK' && data.accept_result === 'ACCEPTED') {
                    // Site accepted the payment result
                    if (data.order_user_url) {
                        console.log('HolestPay: ' + self.getTranslatedText('Redirecting to success URL') + ' from site:', data.order_user_url);
                        window.location.href = data.order_user_url;
                        return;
                    }
                }
                
                // Fallback: use original order_user_url if available
                if (response.order_user_url) {
                    console.log('HolestPay: ' + self.getTranslatedText('Using original order URL') + ':', response.order_user_url);
                    window.location.href = response.order_user_url;
                    return;
                }
                
                // Final fallback: trigger standard place order
                console.log('HolestPay: ' + self.getTranslatedText('No success URL available') + ', triggering standard place order');
                self.triggerStandardPlaceOrder();
                
            }).catch(function(err) {
                console.error('HolestPay: ' + self.getTranslatedText('Error forwarding payment response') + ':', err);
                
                // Fallback: use original order_user_url if available
                if (response.order_user_url) {
                    console.log('HolestPay: ' + self.getTranslatedText('Using original order URL') + ' as fallback:', response.order_user_url);
                    window.location.href = response.order_user_url;
                    return;
                }
                
                // Final fallback: trigger standard place order
                console.log('HolestPay: ' + self.getTranslatedText('No success URL available') + ', triggering standard place order');
                self.triggerStandardPlaceOrder();
            });
        },
        
        /**
         * Initialize default translations if not available
         */
        initDefaultTranslations: function() {
            // Ensure HolestPayCheckout.labels exists
            if (!window.HolestPayCheckout) {
                window.HolestPayCheckout = {};
            }
            if (!window.HolestPayCheckout.labels) {
                window.HolestPayCheckout.labels = {};
            }
            
            // Set default English translations if not already set
            var defaultLabels = {
                // Payment status messages
                'Payment refused': 'Payment refused. You can try again.',
                'Payment failed': 'Payment failed. You can try again.',
                'Payment canceled': 'Payment canceled. You can try again.',
                'Payment error': 'Payment error occurred. Please try again.',
                'Try to pay again': 'Try to pay again',
                'No payment response': 'No payment response',
                'Payment has failed': 'Payment has failed',
                
                // Error messages
                'Error': 'Error',
                'Payment Error': 'Payment Error',
                'General error': 'An error occurred. Please try again.',
                'Network error': 'Network error occurred. Please check your connection.',
                'Server error': 'Server error occurred. Please try again later.',
                'Validation error': 'Please check your input and try again.',
                
                // Token management
                'Token removed successfully': 'Card removed successfully',
                'Token set as default': 'Card set as default successfully',
                'Failed to remove token': 'Failed to remove card',
                'Failed to set default token': 'Failed to set card as default',
                
                // Script loading
                'Script loading failed': 'Failed to load payment system. Please refresh the page.',
                'Payment system unavailable': 'Payment system is currently unavailable. Please try again later.',
                
                // Validation messages
                'No payment method selected': 'Please select a payment method',
                'Payment method not available': 'Selected payment method is not available',
                'Invalid payment data': 'Invalid payment information provided',
                
                // Success messages
                'Payment successful': 'Payment completed successfully',
                'Order placed successfully': 'Your order has been placed successfully',
                
                // Information messages
                'Loading payment methods': 'Loading available payment methods...',
                'Initializing payment': 'Initializing payment system...',
                'Processing payment': 'Processing your payment...',
                'Redirecting to payment': 'Redirecting to payment gateway...',
                
                // Response forwarding messages
                'Forwarding payment response': 'Forwarding payment response to site',
                'Site response received': 'Site response received',
                'Redirecting to success URL': 'Redirecting to success URL',
                'Using original order URL': 'Using original order URL',
                'No success URL available': 'No success URL available',
                'Error forwarding payment response': 'Error forwarding payment response'
            };
            
            // Only set defaults if they don't already exist
            for (var key in defaultLabels) {
                if (!window.HolestPayCheckout.labels[key]) {
                    window.HolestPayCheckout.labels[key] = defaultLabels[key];
                }
            }
            
            console.log('HolestPay: Default translations initialized');
        },

        /**
         * Debug quote structure to identify the correct quote ID field
         */
        debugQuoteStructure: function() {
            var quote = window.checkoutConfig.quoteData;
            console.log('HolestPay: Debugging quote structure:');
            console.log('HolestPay: quoteData:', quote);
            console.log('HolestPay: quote_id:', quote ? quote.quote_id : 'N/A');
            console.log('HolestPay: entity_id:', quote ? quote.entity_id : 'N/A');
            console.log('HolestPay: id:', quote ? quote.id : 'N/A');
            console.log('HolestPay: quoteId:', window.checkoutConfig.quoteId);
            console.log('HolestPay: quote_id:', window.checkoutConfig.quote_id);
        },

        /**
         * Clear any existing order IDs to start fresh
         */
        clearStoredOrderId: function() {
            // Clear component instance (most reliable)
            this.currentOrderId = null;
            
            // Try to clear sessionStorage
            try {
                sessionStorage.removeItem('holestpay_order_id');
                console.log('HolestPay: Cleared order ID from sessionStorage');
            } catch (e) {
                console.warn('HolestPay: Could not clear order ID from sessionStorage:', e.message);
            }
            
            // Try to clear localStorage
            try {
                localStorage.removeItem('holestpay_order_id');
                console.log('HolestPay: Cleared order ID from localStorage');
            } catch (e) {
                console.warn('HolestPay: Could not clear order ID from localStorage:', e.message);
            }
            
            console.log('HolestPay: Cleared stored order IDs from all locations');
        }
    });
});


