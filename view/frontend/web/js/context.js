/**
 * Copyright © 2026 NexorAIQ. All rights reserved.
 *
 * Shopper context sent as headers with every NexorAIQ API call (GraphQL, search, autocomplete):
 * x-session-id, x-user-id ("user_" + base64(email) or "guest_1") and x-device. The Magento proxies
 * get the same headers and forward them (Model\Api\RequestContext).
 */
define([
    'Magento_Customer/js/customer-data'
], function (customerData) {
    'use strict';

    var SESSION_KEY = 'nexoraiq_session_id',
        GUEST = 'guest_1',
        // Private content section "nexoraiq" (CustomerData\Shopper).
        shopper = customerData.get('nexoraiq'),
        sessionId,
        device;

    sessionId = (function () {
        var id = '';

        try {
            id = window.localStorage.getItem(SESSION_KEY) || '';

            if (!id) {
                id = window.crypto && window.crypto.randomUUID ?
                    window.crypto.randomUUID() :
                    Date.now().toString(36) + '-' + Math.random().toString(36).slice(2);
                window.localStorage.setItem(SESSION_KEY, id);
            }
        } catch (e) {
            id = id || 'anon-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2);
        }
        // Session cookie so server-side calls (GraphQL resolvers, proxies) send the same id.
        document.cookie = SESSION_KEY + '=' + encodeURIComponent(id) + ';path=/;SameSite=Lax' +
            (window.location.protocol === 'https:' ? ';Secure' : '');

        return id;
    })();

    device = (function () {
        var ua = navigator.userAgent || '',
            // iPadOS reports itself as a Mac; tell it apart by touch support.
            iPadOs = /Macintosh/i.test(ua) && navigator.maxTouchPoints > 1;

        if (iPadOs || /iPad|Tablet|PlayBook|Silk|Kindle|Nexus (7|9|10)|Android(?!.*Mobile)/i.test(ua)) {
            return 'web_tablet';
        }

        if (/Mobi|iPhone|iPod|Android|BlackBerry|IEMobile|Opera Mini|Windows Phone/i.test(ua)) {
            return 'web_mobile';
        }

        return 'web_desktop';
    })();

    return {
        sessionId: sessionId,
        device: device,

        userId: function () {
            var data = shopper();

            return data && data.userId || GUEST;
        },

        headers: function (extra) {
            return Object.assign({
                'x-session-id': sessionId,
                'x-user-id': this.userId(),
                'x-device': device
            }, extra || {});
        }
    };
});
