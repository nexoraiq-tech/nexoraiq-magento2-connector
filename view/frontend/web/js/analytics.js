/**
 * Copyright © 2026 NexoraIQ. All rights reserved.
 *
 * Storefront analytics, sent through the Magento proxy (rest/{store}/V1/nexoraiq/analytics/event, the
 * "trackEventUrl" setting) to the NexorAIQ "logEvent" mutation for this store view:
 * - click(url, hit, query): CLICK on a search result; also remembers the search for that product;
 * - remember(hit, query): keeps {sku: {q: query, p: productId}} in the "nexoraiq_attribution" cookie, so the
 *   server can log ADD_TO_CART with the search query (Model\Analytics\Attribution).
 */
define([
    'NexorAIQ_Connector/js/context'
], function (context) {
    'use strict';

    var COOKIE = 'nexoraiq_attribution',
        MAX_ENTRIES = 20;

    // Search hits carry "{tenant}_{websiteId}_{storeId}_{entityId}" as externalId; NexorAIQ events use the
    // last three parts.
    function productId(hit) {
        var match = String(hit && hit.externalId || '').match(/(\d+_\d+_\d+)$/);

        return match ? match[1] : String(hit && (hit.id || hit.sku) || '');
    }

    function readCookie() {
        try {
            var raw = (document.cookie.match('(?:^|; )' + COOKIE + '=([^;]*)') || [])[1],
                entries = raw ? JSON.parse(decodeURIComponent(raw)) : {};

            return entries && typeof entries === 'object' ? entries : {};
        } catch (e) {
            return {};
        }
    }

    function remember(hit, query) {
        var sku = hit && hit.sku ? String(hit.sku) : '',
            entries,
            skus;

        query = String(query || '').trim();

        if (!sku || !query) {
            return;
        }
        entries = readCookie();
        delete entries[sku];
        entries[sku] = {q: query.slice(0, 100), p: productId(hit)};
        skus = Object.keys(entries);
        skus.slice(0, Math.max(0, skus.length - MAX_ENTRIES)).forEach(function (key) {
            delete entries[key];
        });
        document.cookie = COOKIE + '=' + encodeURIComponent(JSON.stringify(entries)) +
            ';path=/;max-age=' + 30 * 86400 + ';SameSite=Lax' +
            (window.location.protocol === 'https:' ? ';Secure' : '');
    }

    return {
        productId: productId,
        remember: remember,

        click: function (url, hit, query) {
            remember(hit, query);

            try {
                fetch(url, {
                    method: 'POST',
                    headers: context.headers({'Content-Type': 'application/json'}),
                    body: JSON.stringify({
                        eventType: 'CLICK',
                        productId: productId(hit),
                        query: String(query || '').trim(),
                        sessionId: context.sessionId
                    }),
                    keepalive: true
                }).catch(function () {});
            } catch (e) {}
        }
    };
});
