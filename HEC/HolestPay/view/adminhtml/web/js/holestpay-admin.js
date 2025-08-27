(function () {
    var api = window.HolestPayAdmin || {};
    // Base utilities
    api.getPageContext = function () {
        var body = document.body;
        return body ? body.className : '';
    };

    // Explicit page detection helpers
    api.isOrderViewPage = function () {
        var ctx = api.getPageContext();
        return /sales-order-view/.test(ctx);
    };

    api.isOrderGridPage = function () {
        var ctx = api.getPageContext();
        return /sales-order-index/.test(ctx) || /sales-order-grid/.test(ctx);
    };

    api.isPaymentConfigPage = function () {
        // Payment methods config lives under system config; check for these markers
        var ctx = api.getPageContext();
        var url = location.href;
        return /system_config/.test(ctx) || /section%3Dpayment/.test(url) || /section=payment/.test(url);
    };

    // Display HolestPay order information in the order view box
    api.displayOrderInfo = function () {
        try {
            var orderBox = document.getElementById('holestpay-admin-order-box-content');
            if (!orderBox) {
                return;
            }

            // Get order information from the page
            var orderId = this.getOrderIdFromPage();
            if (!orderId) {
                orderBox.innerHTML = '<p>Order ID not found</p>';
                return;
            }

            // Create order info display
            var orderInfo = document.createElement('div');
            orderInfo.innerHTML = '<h3>HolestPay Payment Information</h3>';
            
            // Add HPay Status if available (this is the important information)
            var hpayStatus = this.getHPayStatusFromPage();
            if (hpayStatus) {
                orderInfo.innerHTML += '<p><strong>HPay Status:</strong> <span class="hpay-status">' + hpayStatus + '</span></p>';
            } else {
                orderInfo.innerHTML += '<p><em>HPay Status: Not yet received</em></p>';
            }

            orderBox.appendChild(orderInfo);
        } catch (e) {
            console.error('Error displaying order info:', e);
        }
    };

    // Get order ID from the page
    api.getOrderIdFromPage = function () {
        try {
            // Try to get from URL parameter
            var urlParams = new URLSearchParams(window.location.search);
            var orderId = urlParams.get('order_id');
            if (orderId) {
                return orderId;
            }

            // Try to get from page elements
            var orderIdElement = document.querySelector('[data-order-id]');
            if (orderIdElement) {
                return orderIdElement.getAttribute('data-order-id');
            }

            return null;
        } catch (e) {
            console.error('Error getting order ID:', e);
            return null;
        }
    };

    // Get HPay Status from the page
    api.getHPayStatusFromPage = function () {
        try {
            // This would need to be populated by the backend or retrieved via AJAX
            // For now, we'll return null and this can be enhanced later
            return null;
        } catch (e) {
            console.error('Error getting HPay Status:', e);
            return null;
        }
    };

    document.addEventListener('DOMContentLoaded', function() {
        setTimeout(function(){
            var enabled = document.querySelector("input[name='groups[holestpay][fields][active][value]'],select[name='groups[holestpay][fields][active][value]']");
            if(enabled){
                var configurationObject = HolestPayAdmin.settings || {};
                
                let info = document.createElement('p');
                info.innerHTML = "On https://" + (HolestPayAdmin.settings.environment == "production" ? "" : "sandbox.") + "pay.holest.com set <i>Notifications I|P|F|S|N url</i> to: <b>" + HolestPayAdmin.frontend_base_url + "holestpay/result/webhook</b> for webhooks to work.";
                
                if(configurationObject[HolestPayAdmin.settings.environment + "POS"]){
                    info.innerHTML += ("<br/>-- configuration set --");
                    info.style.color = "#045404";
                }else{
                    info.style.color = "#FF0000";
                }
                
                enabled.parentElement.appendChild(info);
            }

            let skey_input = document.querySelector("input[name='groups[holestpay][fields][secret_key][value]']");
            if(skey_input){
                skey_input.setAttribute("type", "password");
            }
        },1200);

        // Note: HolestPay initialization is now handled directly in the order view template
        // to provide better integration with the admin commands functionality
    });

    window.HolestPayAdmin = api;
})();


