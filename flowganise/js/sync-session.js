/**
 * Flowganise Session Sync
 *
 * Syncs localStorage fgan_sessionId/fgan_visitorId and device info to a server-side transient.
 * Also sets a cookie with the visitor ID so the woocommerce_checkout_order_processed
 * hook can look up the transient for server-side purchase tracking.
 */
(function() {
    'use strict';

    var sessionId = localStorage.getItem('fgan_sessionId');
    var visitorId = localStorage.getItem('fgan_visitorId');

    // Only sync if we have both IDs and the flowganiseSync config is available
    if (sessionId && visitorId && typeof flowganiseSync !== 'undefined') {
        var formData = new FormData();
        formData.append('action', 'flowganise_sync_session');
        formData.append('nonce', flowganiseSync.nonce);
        formData.append('session_id', sessionId);
        formData.append('visitor_id', visitorId);

        // Capture device info (matches Shopify Web Pixel format)
        formData.append('viewport_width', window.innerWidth || 0);
        formData.append('viewport_height', window.innerHeight || 0);
        formData.append('screen_width', window.screen ? window.screen.width : 0);
        formData.append('screen_height', window.screen ? window.screen.height : 0);
        formData.append('user_agent', navigator.userAgent || '');
        formData.append('language', navigator.language || 'en-US');

        // Include referrer and UTM data from the tracking script's session
        var initialReferrer = localStorage.getItem('fgan_initial_referrer_' + sessionId);
        if (initialReferrer) {
            formData.append('initial_referrer', initialReferrer);
        }
        var utmParams = localStorage.getItem('fgan_utm_params_' + sessionId);
        if (utmParams) {
            formData.append('utm_params', utmParams);
        }

        // Store visitor ID in a cookie so the server-side purchase hook can find the transient.
        // WC sessions are unreliable (temporary IDs change per request), but cookies persist.
        document.cookie = 'flowganise_visitor_id=' + encodeURIComponent(visitorId) + ';path=/;max-age=3600;SameSite=Lax';

        fetch(flowganiseSync.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            body: formData
        }).catch(function() {});
    }
})();
