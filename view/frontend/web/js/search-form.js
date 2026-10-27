/**
 * Copyright © 2026 NexoraIQ. All rights reserved.
 *
 * Header search (search/form.phtml): NexorAIQ autocomplete dropdown or full screen overlay, in place of
 * Magento's quickSearch widget. Markup comes from the <script type="text/x-magento-template"> blocks of
 * the template, rendered with mage/template.
 */
define([
    'jquery',
    'underscore',
    'mage/template',
    'NexorAIQ_Connector/js/context',
    'NexorAIQ_Connector/js/product',
    'NexorAIQ_Connector/js/cart',
    'NexorAIQ_Connector/js/analytics'
], function ($, _, mageTemplate, context, product, cartFactory, analytics) {
    'use strict';

    // Same mapping as Controller\Ajax\Autocomplete::mapSuggestions(): plain strings only, and a
    // redirect URL only when it is http(s) or site-relative.
    function safeUrl(url) {
        if (typeof url !== 'string' || url === '') {
            return '';
        }

        if (/^https?:\/\//i.test(url)) {
            return url;
        }

        if (url.indexOf('//') === 0 || /^[a-z][a-z0-9+.-]*:/i.test(url)) {
            return '';
        }

        return url.replace(/^\/+/, '');
    }

    function absoluteUrl(url) {
        if (!url) {
            return '';
        }

        return /^https?:\/\//i.test(url) ? url : window.BASE_URL + String(url).replace(/^\/+/, '');
    }

    function mapSuggestions(data, limit) {
        var items = data && Array.isArray(data.suggestions) ? data.suggestions : [],
            suggestions = [],
            i,
            item,
            parts,
            hasParts;

        for (i = 0; i < items.length && suggestions.length < limit; i++) {
            item = items[i];

            if (!item || typeof item.text !== 'string' || item.text.trim() === '') {
                continue;
            }
            parts = item.parts && typeof item.parts === 'object' ? item.parts : {};
            hasParts = typeof parts.match === 'string';
            suggestions.push({
                text: item.text,
                before: hasParts ? String(parts.before || '') : item.text,
                match: hasParts ? parts.match : '',
                after: hasParts ? String(parts.after || '') : '',
                url: item.redirected ? safeUrl(item.url) : ''
            });
        }

        return suggestions;
    }

    // Categories and brands come back as JSON scalars: either plain names or {name, url} objects.
    function mapFacets(items) {
        return (Array.isArray(items) ? items : []).map(function (item) {
            if (typeof item === 'string') {
                return {label: item, count: '', url: ''};
            }

            return item && (item.name || item.label) ?
                {label: String(item.name || item.label), count: item.count || '', url: absoluteUrl(safeUrl(item.url))} :
                null;
        }).filter(Boolean);
    }

    function overlayQuery() {
        return 'query Overlay($query: String!, $storeCode: String!, $limit: Int, $suggestion: Boolean, $category: Boolean, $brand: Boolean, $suggestionLimit: Int, $categoryLimit: Int, $brandLimit: Int) {' +
            ' overlay(query: $query, storeCode: $storeCode, limit: $limit, suggestion: $suggestion, category: $category, brand: $brand, suggestionLimit: $suggestionLimit, categoryLimit: $categoryLimit, brandLimit: $brandLimit) {' +
            ' products { id title sku price compareAtPrice regularPrice specialPrice discountPercent currencyCode imageUrl url externalId inStock stockStatus productType ' + product.variantFields() + ' }' +
            ' total' +
            ' suggestions { text parts { before match after } redirected destinationUrl }' +
            ' categories' +
            ' brands' +
            ' } }';
    }

    function format(text) {
        var args = Array.prototype.slice.call(arguments, 1);

        return String(text).replace(/%(\d+)/g, function (match, index) {
            return args[index - 1] !== undefined ? args[index - 1] : match;
        });
    }

    return function (config, element) {
        var root = $(element),
            form = root.find('#search_mini_form'),
            input = root.find('#search'),
            label = root.find('[data-role="minisearch-label"]'),
            submitButton = form.find('button[type="submit"]'),
            dropdown = root.find('[data-role="nxq-dropdown"]'),
            overlay = root.find('[data-role="nxq-overlay"]'),
            overlayInput = overlay.find('[data-role="nxq-overlay-input"]'),
            productsEl = overlay.find('[data-role="nxq-products"]'),
            asideEl = overlay.find('[data-role="nxq-aside"]'),
            // Desktop and mobile can use different layouts; the screen width decides which one.
            mobileMedia = window.matchMedia(config.mobileMedia || '(max-width: 767px)'),
            labels = config.labels,
            cart = cartFactory(config.cart, product),
            renderAutocomplete = mageTemplate('#nxq-template-autocomplete'),
            renderProducts = $('#nxq-template-products').length ? mageTemplate('#nxq-template-products') : null,
            renderAside = config.twoColumns && $('#nxq-template-aside').length ? mageTemplate('#nxq-template-aside') : null,
            // Responses already fetched in this page view, keyed by search term.
            autocompleteCache = new Map(),
            overlayCache = new Map(),
            controller = null,
            requestId = 0,
            state = {
                query: String(input.val() || ''),
                open: false,
                loading: false,
                searched: false,
                products: [],
                total: 0,
                suggestions: [],
                categories: [],
                brands: [],
                autocomplete: [],
                activeIndex: -1,
                cartState: {}
            };

        function currentLayout() {
            var layout = mobileMedia.matches ? config.mobileLayout || config.layout : config.layout;

            // Fall back to a layout whose markup is on the page.
            if (layout === 'overlay' && !overlay.length) {
                return 'autocomplete';
            }

            return layout === 'autocomplete' && !dropdown.length && overlay.length ? 'overlay' : layout;
        }

        function isOverlay() {
            return currentLayout() === 'overlay';
        }

        function productsLimit() {
            return (isOverlay() ? config.overlayLimit : config.autocompleteLimit) || config.productsLimit;
        }

        product.setCurrency(config.currencyCode);

        // Out of the header's stacking context, so the overlay covers the whole page.
        if (overlay.length) {
            overlay.appendTo(document.body);
        }

        function resultsUrl(term) {
            var params = new URLSearchParams();

            params.set(config.queryParam, term || state.query.trim());

            return config.resultUrl + '?' + params;
        }

        function trackClick(hit) {
            analytics.click(config.trackEventUrl, hit, state.query);
        }

        function abort() {
            if (controller) {
                controller.abort();
                controller = null;
            }
        }

        // ----- autocomplete dropdown -----
        function setExpanded(expanded) {
            input.attr('aria-expanded', expanded ? 'true' : 'false');
        }

        function drawAutocomplete() {
            dropdown.html(renderAutocomplete({
                data: {
                    loading: state.loading,
                    searched: state.searched,
                    activeIndex: state.activeIndex,
                    items: state.autocomplete.map(function (item) {
                        return Object.assign({}, item, {href: item.url || resultsUrl(item.text)});
                    }),
                    labels: labels
                }
            }));
            dropdown.prop('hidden', !state.open);
            setExpanded(state.open);
        }

        function setActive(index) {
            state.activeIndex = index;
            dropdown.find('[data-index]').each(function () {
                var active = Number(this.getAttribute('data-index')) === index;

                $(this).toggleClass('is-active', active).closest('li').attr('aria-selected', active ? 'true' : 'false');
            });
            input.attr('aria-activedescendant', index >= 0 ? 'nxq-ac-' + index : null);
        }

        function closeDropdown() {
            state.open = false;
            dropdown.prop('hidden', true);
            setExpanded(false);
        }

        function fetchAutocomplete(term) {
            var current,
                cacheKey = term.toLowerCase(),
                signal,
                direct,
                params;

            abort();
            current = ++requestId;

            if (autocompleteCache.has(cacheKey)) {
                showAutocomplete(autocompleteCache.get(cacheKey));

                return;
            }
            controller = new AbortController();
            signal = controller.signal;
            state.loading = true;
            drawAutocomplete();

            // Call the NexorAIQ autocomplete API directly with the public search key; fall back to
            // the Magento proxy (nexoraiq/ajax/autocomplete) if the direct call fails. Both send the
            // x-session-id / x-user-id / x-device headers.
            if (config.autocompleteApiUrl && config.searchKey) {
                params = new URLSearchParams({
                    q: term,
                    type: 'query',
                    limit: productsLimit(),
                    storeView: config.storeView,
                    apiKey: config.searchKey
                });
                direct = fetch(config.autocompleteApiUrl + '?' + params, {
                    signal: signal,
                    mode: 'cors',
                    credentials: 'omit',
                    headers: context.headers()
                }).then(function (response) {
                    return response.ok ? response.json() : Promise.reject(new Error(response.status));
                }).then(function (data) {
                    return {suggestions: mapSuggestions(data, productsLimit())};
                });
            } else {
                direct = Promise.reject(new Error('direct autocomplete not configured'));
            }

            direct.catch(function (error) {
                if (error.name === 'AbortError') {
                    throw error;
                }

                return fetch(config.autocompleteUrl + '?' + new URLSearchParams({q: term}), {
                    signal: signal,
                    headers: context.headers({'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'})
                }).then(function (response) {
                    return response.ok ? response.json() : Promise.reject(new Error(response.status));
                });
            }).then(function (data) {
                var suggestions = (data && Array.isArray(data.suggestions) ? data.suggestions : [])
                    .filter(function (item) {
                        return item && item.text;
                    })
                    .map(function (item) {
                        return Object.assign({}, item, {url: absoluteUrl(item.url)});
                    });

                autocompleteCache.set(cacheKey, suggestions);

                if (current === requestId) {
                    showAutocomplete(suggestions);
                }
            }).catch(function (error) {
                if (error.name === 'AbortError' || current !== requestId) {
                    return;
                }
                state.autocomplete = [];
                state.loading = false;
                state.searched = true;
                drawAutocomplete();
            });
        }

        function showAutocomplete(suggestions) {
            state.autocomplete = suggestions;
            state.activeIndex = -1;
            state.loading = false;
            state.searched = true;
            drawAutocomplete();
        }

        // ----- overlay -----
        function sectionItems(section) {
            var source = section.type === 'suggestions' ? state.suggestions :
                section.type === 'categories' ? state.categories : state.brands;

            return source.slice(0, section.limit);
        }

        function cartLabel(hit) {
            var cartState = state.cartState[hit.id];

            return cartState === 'adding' ? labels.adding : cartState === 'added' ? labels.added : config.cart.label;
        }

        function drawProducts() {
            var term = state.query.trim();

            productsEl.html(renderProducts({
                data: {
                    loading: state.loading,
                    searched: state.searched,
                    totalLabel: state.searched && state.total ? format(labels.results, state.total) : '',
                    noResultsLabel: term ? format(labels.noResultsFor, term) : labels.noResults,
                    viewAll: state.products.length && term ?
                        {url: resultsUrl(), label: format(labels.viewAll, state.total)} :
                        null,
                    showAddToCart: config.addToCart,
                    labels: labels,
                    products: state.products.map(function (hit, index) {
                        return {
                            index: index,
                            title: hit.title,
                            url: absoluteUrl(hit.url),
                            image: hit.imageUrl,
                            price: product.priceLabel(hit),
                            oldPrice: product.oldPriceLabel(hit),
                            inStock: hit.inStock !== false,
                            cartMode: cart.mode(hit),
                            cartLabel: cartLabel(hit),
                            cartState: state.cartState[hit.id] || ''
                        };
                    })
                }
            }));
        }

        function drawAside() {
            if (!renderAside) {
                return;
            }
            asideEl.html(renderAside({
                data: {
                    sections: config.sections.map(function (section) {
                        return {
                            label: section.label,
                            items: sectionItems(section).map(function (item) {
                                return Object.assign({}, item, {href: item.url || resultsUrl(item.label)});
                            })
                        };
                    })
                }
            }));
        }

        function drawOverlay() {
            drawProducts();
            drawAside();
        }

        function showOverlay(result) {
            state.products = result.products;
            state.total = result.total || result.products.length;
            state.suggestions = result.suggestions;
            state.categories = result.categories;
            state.brands = result.brands;
            state.loading = false;
            state.searched = true;
            drawOverlay();
        }

        function fetchOverlay(term) {
            var current,
                cacheKey = term.toLowerCase(),
                section = function (type) {
                    return config.sections.find(function (item) {
                        return item.type === type;
                    });
                },
                suggestion = section('suggestions'),
                category = section('categories'),
                brand = section('brands'),
                signal,
                request;

            abort();
            current = ++requestId;

            if (overlayCache.has(cacheKey)) {
                showOverlay(overlayCache.get(cacheKey));

                return;
            }
            controller = new AbortController();
            signal = controller.signal;
            state.loading = true;
            drawProducts();

            // 1 column asks for products only; 2 columns adds the blocks enabled in the admin.
            request = function () {
                return fetch(config.graphqlUrl, {
                    method: 'POST',
                    signal: signal,
                    mode: 'cors',
                    credentials: 'omit',
                    headers: context.headers({'Content-Type': 'application/json', 'x-api-key': config.searchKey}),
                    body: JSON.stringify({
                        query: overlayQuery(),
                        variables: {
                            query: term,
                            storeCode: config.storeView,
                            limit: productsLimit(),
                            suggestion: !!suggestion,
                            category: !!category,
                            brand: !!brand,
                            suggestionLimit: suggestion ? suggestion.limit : 5,
                            categoryLimit: category ? category.limit : 5,
                            brandLimit: brand ? brand.limit : 5
                        }
                    })
                }).then(function (response) {
                    return response.json().catch(function () {
                        return null;
                    }).then(function (payload) {
                        // Older APIs reject priceMin / priceMax / hasVariants: retry without them.
                        if (product.rejectsVariantFields(payload)) {
                            return request();
                        }

                        return response.ok ? payload : Promise.reject(new Error(response.status));
                    });
                });
            };

            request().then(function (payload) {
                var data = payload && payload.data && payload.data.overlay || null,
                    result;

                if (!data) {
                    return Promise.reject(new Error('overlay query failed'));
                }
                result = {
                    products: (Array.isArray(data.products) ? data.products : []).slice(0, productsLimit()),
                    total: data.total || 0,
                    suggestions: mapSuggestions(data, suggestion ? suggestion.limit : 0).map(function (item) {
                        return {label: item.text, count: '', url: item.url ? absoluteUrl(item.url) : ''};
                    }),
                    categories: mapFacets(data.categories),
                    brands: mapFacets(data.brands)
                };
                overlayCache.set(cacheKey, result);

                if (current === requestId) {
                    showOverlay(result);
                }
            }).catch(function (error) {
                if (error.name === 'AbortError' || current !== requestId) {
                    return;
                }
                showOverlay({products: [], total: 0, suggestions: [], categories: [], brands: []});
            });
        }

        function openOverlay() {
            var field = overlayInput.get(0);

            if (state.open) {
                return;
            }
            state.open = true;
            overlay.prop('hidden', false);
            setExpanded(true);
            document.documentElement.classList.add('nxq-locked');
            overlayInput.val(state.query);
            fetchOverlay(state.query.trim());
            field.focus();
            field.setSelectionRange(field.value.length, field.value.length);
        }

        function closeOverlay() {
            state.open = false;
            overlay.prop('hidden', true);
            setExpanded(false);
            document.documentElement.classList.remove('nxq-locked');
            input.val(state.query);
            // Focus the submit button, not the input: focusing the input would reopen the overlay.
            submitButton.trigger('focus');
        }

        function addToCart(hit) {
            if (state.cartState[hit.id] === 'adding') {
                return;
            }
            state.cartState[hit.id] = 'adding';
            drawProducts();
            cart.add(hit, 1, state.query).then(function (outcome) {
                state.cartState[hit.id] = outcome === 'added' ? 'added' : '';

                if (outcome === 'added') {
                    // Close the overlay so the mini cart is visible.
                    if (config.cart.afterAdd === 'drawer') {
                        closeOverlay();
                    }
                    setTimeout(function () {
                        if (state.cartState[hit.id] === 'added') {
                            state.cartState[hit.id] = '';
                            drawProducts();
                        }
                    }, 2000);
                }
                drawProducts();
            });
        }

        // ----- input handling -----
        function runSearch() {
            var term = state.query.trim();

            if (isOverlay()) {
                // Every keystroke (and the empty query) goes to the GraphQL overlay query.
                fetchOverlay(term);

                return;
            }

            // Autocomplete: query the NexorAIQ API on every keystroke, from the first character.
            if (!term.length) {
                abort();
                state.loading = false;
                state.searched = false;
                state.autocomplete = [];
                state.activeIndex = -1;
                closeDropdown();

                return;
            }
            state.open = true;
            fetchAutocomplete(term);
        }

        function submit(event) {
            var chosen = !isOverlay() && state.open ? state.autocomplete[state.activeIndex] : null,
                term = state.query.trim(),
                field = input.get(0);

            event.preventDefault();

            if (chosen) {
                window.location.href = chosen.url || resultsUrl(chosen.text);

                return;
            }

            if (isOverlay() && !state.open) {
                // Search icon: open the overlay (it loads results for the current query, even "").
                openOverlay();

                return;
            }

            if (term.length < config.minQueryLength) {
                field.setCustomValidity(labels.minQueryLength);
                field.reportValidity();

                return;
            }
            field.setCustomValidity('');
            window.location.href = resultsUrl(term);
        }

        // Mobile: the search label expands the field, as Magento_Search/js/form-mini does.
        function setActiveState(active) {
            form.toggleClass('active', active);
            label.toggleClass('active', active);
        }

        input.on('input', _.debounce(function () {
            state.query = String(input.val() || '');
            input.get(0).setCustomValidity('');

            if (isOverlay()) {
                openOverlay();

                return;
            }
            runSearch();
        }, 80));

        input.on('focus', function () {
            setActiveState(true);

            if (isOverlay()) {
                // Opening the overlay loads the default products (query "") straight away.
                openOverlay();

                return;
            }

            if (state.query.trim().length && state.autocomplete.length) {
                state.open = true;
                drawAutocomplete();
            }
        });

        input.on('blur', function () {
            setTimeout(function () {
                if (!state.open) {
                    setActiveState(false);
                }
            }, 250);
        });

        label.on('click', function (event) {
            if (label.hasClass('active')) {
                event.preventDefault();
            }
        });

        form.on('submit', submit);

        input.on('keydown', function (event) {
            var count = state.autocomplete.length;

            if (isOverlay()) {
                return;
            }

            if (event.key === 'Escape') {
                closeDropdown();
            } else if (event.key === 'ArrowDown' && state.open && count) {
                event.preventDefault();
                setActive((state.activeIndex + 1) % count);
            } else if (event.key === 'ArrowUp' && state.open && count) {
                event.preventDefault();
                setActive(state.activeIndex <= 0 ? count - 1 : state.activeIndex - 1);
            }
        });

        dropdown.on('mouseenter', '[data-index]', function () {
            setActive(Number(this.getAttribute('data-index')));
        });

        $(document).on('click', function (event) {
            if (!isOverlay() && state.open && !$.contains(element, event.target)) {
                closeDropdown();
            }
        });

        if (overlay.length) {
            overlayInput.on('input', _.debounce(function () {
                state.query = String(overlayInput.val() || '');
                runSearch();
            }, 300));

            overlay.on('submit', 'form', function (event) {
                state.query = String(overlayInput.val() || '');
                submit(event);
            });

            overlay.on('click', '[data-action="close"]', closeOverlay);

            overlay.on('keydown', function (event) {
                if (event.key === 'Escape') {
                    closeOverlay();
                }
            });

            overlay.on('click', '[data-action="add-to-cart"]', function () {
                addToCart(state.products[Number(this.getAttribute('data-index'))]);
            });

            overlay.on('click', '[data-action="track"]', function () {
                trackClick(state.products[Number(this.getAttribute('data-index'))]);
            });
        }

        // ARIA attributes follow the active layout.
        function syncLayout() {
            var overlayMode = isOverlay();

            input.attr({
                'aria-haspopup': overlayMode ? 'dialog' : 'listbox',
                'aria-controls': overlayMode ? 'nxq-overlay' : 'nxq-autocomplete'
            });
        }

        // Crossing the mobile breakpoint (rotation, window resize): close whatever is open and
        // switch layout. Results are re-fetched with the new layout's limit on the next search.
        function onLayoutChange() {
            if (overlay.length && !overlay.prop('hidden')) {
                state.open = false;
                overlay.prop('hidden', true);
                document.documentElement.classList.remove('nxq-locked');
                input.val(state.query);
            }
            closeDropdown();
            autocompleteCache.clear();
            overlayCache.clear();
            syncLayout();
        }

        if (mobileMedia.addEventListener) {
            mobileMedia.addEventListener('change', onLayoutChange);
        } else if (mobileMedia.addListener) {
            mobileMedia.addListener(onLayoutChange);
        }
        syncLayout();

        // Ctrl+K / ⌘K focuses the search field.
        $(document).on('keydown', function (event) {
            if ((event.ctrlKey || event.metaKey) && String(event.key).toLowerCase() === 'k') {
                event.preventDefault();
                input.trigger('focus');
                input.get(0).select();
            }
        });
    };
});
