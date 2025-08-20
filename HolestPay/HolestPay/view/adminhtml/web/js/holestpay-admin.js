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

    // Expose
    window.HolestPayAdmin = api;
})();


