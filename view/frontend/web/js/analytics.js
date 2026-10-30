/**
 * Copyright © 2026 NexorAIQ. All rights reserved.
 *
 * Storefront analytics, sent through the Magento proxy (rest/{store}/V1/nexoraiq/analytics/event, the
 * "trackEventUrl" setting) to the NexorAIQ "logEvent" mutation for this store view:
 * - click(url, hit, query, source): CLICK on a search result; also remembers the search for that product;
 * - remember(hit, query, source): keeps {sku: {q: query, p: productId, s: source}} in the "nexoraiq_attribution"
 *   cookie, so the server can log ADD_TO_CART / CONVERSION with the search query and source
 *   (Model\Analytics\Attribution).
 * source is "trending" for the overlay's trending products (shown before anything is typed); their query is then
 * "trending" as well. Typed searches send the keyword and a blank source.
 */
define([
    'NexorAIQ_Connector/js/context'
], function (context) {
    'use strict';

    var COOKIE = 'nexoraiq_attribution',
        MAX_ENTRIES = 20,
        TRENDING = 'trending';

    // {query, source} sent with the events: source "trending" forces query "trending", any other source is blank.
    function context(query, source) {
        return source === TRENDING ?
            {query: TRENDING, source: TRENDING} :
            {query: String(query || '').trim(), source: ''};
    }

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

    function remember(hit, query, source) {
        var sku = hit && hit.sku ? String(hit.sku) : '',
            ctx = context(query, source),
            entries,
            skus;

        if (!sku || !ctx.query) {
            return;
        }
        entries = readCookie();
        delete entries[sku];
        entries[sku] = {q: ctx.query.slice(0, 100), p: productId(hit)};

        if (ctx.source) {
            entries[sku].s = ctx.source;
        }
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
        context: context,

        click: function (url, hit, query, source) {
            var ctx = context(query, source);

            remember(hit, query, source);

            try {
                fetch(url, {
                    method: 'POST',
                    headers: context.headers({'Content-Type': 'application/json'}),
                    body: JSON.stringify({
                        eventType: 'CLICK',
                        productId: productId(hit),
                        query: ctx.query,
                        source: ctx.source,
                        sessionId: context.sessionId
                    }),
                    keepalive: true
                }).catch(function () {});
            } catch (e) {}
        }
    };
});
