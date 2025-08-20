(function () {
    var obj = window.HolestPayCheckout || {
        // Place your global frontend JavaScript here. This object is available on all pages.
        version: '1.0.0',
        init: function () {
            // Initialization placeholder
            // console.log('HolestPayCheckout initialized');
        }
    };
    // Expose only the correctly spelled global
    window.HolestPayCheckout = obj;

    if (typeof window.HolestPayCheckout.init === 'function') {
        try { window.HolestPayCheckout.init(); } catch (e) { /* no-op */ }
    }
})();


