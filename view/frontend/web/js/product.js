/**
 * Copyright © 2026 NexorAIQ. All rights reserved.
 *
 * Product hit helpers shared by the search overlay and the search results page: prices (special price,
 * variant ranges), URLs, stock, and GraphQL variant field support.
 */
define([], function () {
    'use strict';

    // priceMin / priceMax / hasVariants are newer API fields; an API without them rejects the whole
    // query ("Cannot query field"), so callers retry without them and it is remembered for the session.
    var VARIANT_FIELDS = 'priceMin priceMax hasVariants',
        STORAGE_KEY = 'nexoraiq_no_variant_fields',
        currencyCode = '',
        variantFieldsSupported = (function () {
            try {
                return window.sessionStorage.getItem(STORAGE_KEY) !== '1';
            } catch (e) {
                return true;
            }
        })();

    function number(value) {
        var n = Number(value);

        return Number.isFinite(n) ? n : 0;
    }

    return {
        /**
         * Store currency, used when a hit has no currencyCode.
         */
        setCurrency: function (code) {
            currencyCode = code || currencyCode;
        },

        variantFields: function () {
            return variantFieldsSupported ? VARIANT_FIELDS : '';
        },

        /**
         * True when the response rejected the variant fields; later queries leave them out.
         */
        rejectsVariantFields: function (payload) {
            var rejected = variantFieldsSupported && Array.isArray(payload && payload.errors) &&
                payload.errors.some(function (error) {
                    return /Cannot query field "(priceMin|priceMax|hasVariants)"/.test(String(error && error.message));
                });

            if (rejected) {
                variantFieldsSupported = false;

                try {
                    window.sessionStorage.setItem(STORAGE_KEY, '1');
                } catch (e) {}
            }

            return rejected;
        },

        absoluteUrl: function (url) {
            if (typeof url !== 'string' || url === '' || url.indexOf('//') === 0 ||
                /^(?!https?:)[a-z][a-z0-9+.-]*:/i.test(url)) {
                return '';
            }

            return /^https?:\/\//i.test(url) ? url : window.BASE_URL + url.replace(/^\/+/, '');
        },

        // Names can arrive HTML-encoded ("Lumaflex&trade;"); decode without executing anything.
        decode: function (text) {
            var value = String(text || '');

            return value.indexOf('&') !== -1 ?
                new DOMParser().parseFromString(value, 'text/html').documentElement.textContent || value :
                value;
        },

        name: function (product) {
            return this.decode(product.name || product.title);
        },

        image: function (product) {
            return this.absoluteUrl(product.imageUrl || product.image);
        },

        url: function (product) {
            return this.absoluteUrl(product.url);
        },

        type: function (product) {
            return String(product.productType || product.product_type || 'simple').toLowerCase();
        },

        /**
         * Variants of a configurable hit ({id: inStock}), read from its attribute values, or null for a
         * product without variants. The API marks configurable parents out of stock (inventory 0) even when
         * their variants are in stock; each variant's own stock is only on these attribute values.
         */
        variants: function (product) {
            var ownId = String(product.externalId || '').split('_').pop(),
                variants = {},
                found = false;

            (Array.isArray(product.attributes) ? product.attributes : []).forEach(function (attribute) {
                (attribute && Array.isArray(attribute.values) ? attribute.values : []).forEach(function (entry) {
                    var id = entry && entry.variationId !== undefined && entry.variationId !== null ?
                        String(entry.variationId) : '';

                    if (id === '' || id === ownId || id === String(product.externalId)) {
                        return;
                    }
                    found = true;
                    variants[id] = variants[id] || entry.inStock !== false;
                });
            });

            return found ? variants : null;
        },

        isInStock: function (product) {
            var variants = this.variants(product);

            if (variants) {
                return Object.keys(variants).some(function (id) {
                    return variants[id];
                });
            }

            return product.inStock !== false && product.stock_status !== 'out_of_stock' &&
                product.stockStatus !== 'out_of_stock';
        },

        formatPrice: function (value, currency) {
            if (value === null || value === undefined || value === '') {
                return '';
            }

            try {
                if (currency || currencyCode) {
                    return new Intl.NumberFormat(document.documentElement.lang || undefined, {
                        style: 'currency',
                        currency: currency || currencyCode
                    }).format(value);
                }
            } catch (e) {}

            return Number(value).toFixed(2);
        },

        /**
         * {final, old, min, max, isRange, isSpecial} for a hit:
         * - variant ranges (configurable / bundle / grouped) from priceMin / priceMax;
         * - special price: the final price with the regular price struck through.
         */
        prices: function (product) {
            var min = number(product.priceMin),
                max = number(product.priceMax),
                special,
                regular,
                final,
                old;

            if (min > 0 && max > min) {
                return {final: min, old: 0, min: min, max: max, isRange: true, isSpecial: false};
            }
            special = number(product.specialPrice !== undefined && product.specialPrice !== null ?
                product.specialPrice : product.special_price);
            regular = number(product.regularPrice !== undefined && product.regularPrice !== null ?
                product.regularPrice : product.regular_price) || number(product.compareAtPrice);
            final = number(product.price) || min;

            if (special > 0 && (!final || special < final)) {
                final = special;
            }
            old = regular > final ? regular : 0;

            return {final: final, old: old, min: final, max: final, isRange: false, isSpecial: old > 0};
        },

        priceLabel: function (product) {
            var price = this.prices(product);

            return price.isRange ?
                this.formatPrice(price.min, product.currencyCode) + ' – ' + this.formatPrice(price.max, product.currencyCode) :
                this.formatPrice(price.final, product.currencyCode);
        },

        oldPriceLabel: function (product) {
            var price = this.prices(product);

            return price.old ? this.formatPrice(price.old, product.currencyCode) : '';
        }
    };
});
