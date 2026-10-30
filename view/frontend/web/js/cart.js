/**
 * Copyright © 2026 NexorAIQ. All rights reserved.
 *
 * Add to Cart from the search overlay and the search results page. Posts the SKU to nexoraiq/cart/add,
 * which forwards to Magento's checkout/cart/add. jQuery AJAX on purpose: customer-data then reloads the
 * "cart" and "messages" sections (etc/frontend/sections.xml), so the mini cart and messages update.
 */
define([
    'jquery',
    'Magento_Customer/js/customer-data',
    'NexorAIQ_Connector/js/analytics',
    'mage/cookies'
], function ($, customerData, analytics) {
    'use strict';

    // Only these types can go to the cart without choosing options on the product page.
    var DIRECT_TYPES = ['simple', 'virtual'];

    // Opened once the "cart" section has reloaded: opening it straight away from the click that added the
    // product would close it again (the dropdown closes on clicks outside it).
    function openMinicart() {
        var subscription = customerData.get('cart').subscribe(function () {
                subscription.dispose();
                clearTimeout(fallback);
                $('[data-block="minicart"]').find('[data-role="dropdownDialog"]').dropdownDialog('open');
            }),
            fallback = setTimeout(function () {
                subscription.dispose();
            }, 10000);
    }

    function showError(text) {
        customerData.set('messages', {
            messages: [{type: 'error', text: text}]
        });
    }

    /**
     * @param {Object} config AddToCartConfig::getJsConfig()
     * @param {Object} product js/product
     */
    return function (config, product) {
        return {
            config: config,

            // 'add' (button), 'options' (link to the product page) or 'out' (disabled).
            mode: function (hit) {
                if (!product.isInStock(hit)) {
                    return 'out';
                }

                // A configurable parent can arrive typed "simple"; its variants tell it apart.
                return DIRECT_TYPES.indexOf(product.type(hit)) !== -1 && !hit.hasVariants && !product.variants(hit) &&
                    hit.sku ?
                    'add' :
                    'options';
            },

            /**
             * Resolves to 'added', 'redirect' or 'error'. query / source: the search the product was found with
             * ("" / "trending" for the overlay's trending products).
             */
            add: function (hit, qty, query, source) {
                // Search the product was found with, for the ADD_TO_CART and CONVERSION events.
                analytics.remember(hit, query, source);

                return new Promise(function (resolve) {
                    // Mini cart spinner, as Magento_Catalog/js/catalog-add-to-cart.
                    $('[data-block="minicart"]').trigger('contentLoading');
                    $.ajax({
                        url: config.url,
                        type: 'POST',
                        dataType: 'json',
                        data: {
                            sku: hit.sku,
                            qty: String(qty || 1),
                            'form_key': $.mage.cookies.get('form_key')
                        }
                    }).always(function (data, status, xhr) {
                        var ok = status === 'success',
                            result = (ok ? data : data && data.responseJSON) || {};

                        // Magento asks to go elsewhere (options required, "redirect to cart" enabled, ...).
                        if (result.backUrl) {
                            window.location.href = result.backUrl;
                            resolve('redirect');

                            return;
                        }

                        if (!ok || result.error || result.product && result.product.statusText) {
                            if (result.error) {
                                showError(result.error);
                            }
                            resolve('error');

                            return;
                        }

                        if (config.afterAdd === 'cart') {
                            window.location.href = config.cartUrl;
                            resolve('redirect');

                            return;
                        }

                        if (config.afterAdd === 'drawer') {
                            openMinicart();
                        }
                        resolve('added');
                    });
                });
            }
        };
    };
});
