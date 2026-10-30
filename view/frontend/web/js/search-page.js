/**
 * Copyright © 2026 NexorAIQ. All rights reserved.
 *
 * NexorAIQ search results page (search/page.phtml + search/page/filters.phtml). Data comes from the
 * NexorAIQ GraphQL searchCatalog query; state lives here and in the URL (same parameter names as the
 * category toolbar). The product list and the layered navigation are rendered with mage/template, using
 * the Luma category page markup so the theme styles them.
 *
 * Filters: categories (multi-select), price and stock are applied by the API. Attribute facets the API
 * cannot filter on (color, size, ...) are applied in the browser over the full result set, using the
 * attribute values of each hit; paging and counts are then computed here as well.
 */
define([
    'jquery',
    'mage/template',
    'NexorAIQ_Connector/js/context',
    'NexorAIQ_Connector/js/product',
    'NexorAIQ_Connector/js/cart',
    'NexorAIQ_Connector/js/analytics'
], function ($, mageTemplate, context, product, cartFactory, analytics) {
    'use strict';

    // Attribute filters are applied in the browser over at most this many results.
    var CLIENT_FILTER_LIMIT = 1000,
        SORTS = ['relevance', 'newest', 'price'];

    function toNumber(value) {
        var n;

        if (value === null || value === undefined || String(value).trim() === '') {
            return null;
        }
        n = Number(value);

        return Number.isFinite(n) && n >= 0 ? n : null;
    }

    function splitList(value) {
        return String(value || '').split(',').map(function (item) {
            return item.trim();
        }).filter(Boolean);
    }

    function same(a, b) {
        return String(a).toLowerCase() === String(b).toLowerCase();
    }

    function format(text) {
        var args = Array.prototype.slice.call(arguments, 1);

        return String(text).replace(/%(\d+)/g, function (match, index) {
            return args[index - 1] !== undefined ? args[index - 1] : match;
        });
    }

    function nullish(value, fallback) {
        return value === null || value === undefined ? fallback : value;
    }

    return function (config, element) {
        // URL parameters with a fixed meaning; attribute facets use their code as parameter.
        var RESERVED_PARAMS = [config.queryParam, 'p', 'cat', 'price', 'stock', 'product_list_order',
                'product_list_dir', 'product_list_mode', 'product_list_limit'],
            labels = config.labels,
            cart = cartFactory(config.cart, product),
            listEl = $(element),
            filterBlock = $(config.filtersSelector),
            filterContent = filterBlock.find('[data-role="nxq-filter-content"]'),
            renderList = mageTemplate('#nxq-template-list'),
            renderFilters = filterBlock.length ? mageTemplate('#nxq-template-filters') : null,
            controller = null,
            requestId = 0,
            // All results of the current search (filters except attributes): their attribute values build
            // the attribute filters; with attribute filters active they hold full details and are paged here.
            poolKey = '',
            poolHits = [],
            poolHasDetails = false,
            s;

        function searchQuery(params, fields) {
            var args = [];

            args.push('query: ' + JSON.stringify(params.query || ''));
            if (params.storeView) {
                args.push('storeView: ' + JSON.stringify(params.storeView));
            }
            if (params.storeCode) {
                args.push('storeCode: ' + JSON.stringify(params.storeCode));
            }
            if (params.page !== undefined && params.page !== null) {
                args.push('page: ' + parseInt(params.page, 10));
            }
            if (params.perPage !== undefined && params.perPage !== null) {
                args.push('perPage: ' + parseInt(params.perPage, 10));
            }
            if (params.searchType) {
                args.push('searchType: ' + params.searchType);
            }
            if (params.hasFilter !== undefined && params.hasFilter !== null) {
                args.push('hasFilter: ' + (params.hasFilter ? 'true' : 'false'));
            }
            if (params.category) {
                args.push('category: ' + JSON.stringify(params.category));
            }
            if (params.minPrice !== undefined && params.minPrice !== null) {
                args.push('minPrice: ' + Number(params.minPrice));
            }
            if (params.maxPrice !== undefined && params.maxPrice !== null) {
                args.push('maxPrice: ' + Number(params.maxPrice));
            }
            if (params.inStockOnly) {
                args.push('inStockOnly: true');
            }
            if (params.sortBy) {
                args.push('sortBy: ' + JSON.stringify(params.sortBy));
            }
            if (Array.isArray(params.attributes) && params.attributes.length > 0) {
                args.push('attributes: ' + JSON.stringify(params.attributes));
            }

            var hitFieldsStr = fields || ('id sku url name title price regularPrice specialPrice compareAtPrice discountPercent ' +
                'currencyCode imageUrl image images category tags description externalId inventory storeViewCode inStock stock_status productType attributes');

            return 'query SearchCatalog {\n' +
                '  searchCatalog(\n' +
                '    ' + args.join('\n    ') + '\n' +
                '  ) {\n' +
                '    total_found\n' +
                '    search_time_ms\n' +
                '    responseId\n' +
                '    redirected\n' +
                '    destinationUrl\n' +
                '    hits {\n' +
                '      ' + hitFieldsStr + '\n' +
                '    }\n' +
                '    aggregations {\n' +
                '      key\n' +
                '      label\n' +
                '      type\n' +
                '      order\n' +
                '      data\n' +
                '    }\n' +
                '  }\n' +
                '}';
        }

        // Values ({key: lower-cased, label}) of attribute `code` on a hit; for a configurable product, those
        // of its variations. Two shapes exist: the platform import's [{id, values: [{value: [...]}]}] and the
        // connector sync's {custom: {code: "A, B"}} (Helper\Data::buildProductPayload).
        function hitValues(hit, code) {
            var attributes = hit.attributes,
                meta = config.filterable[code] || {options: [], ids: {}},
                values = [],
                attribute,
                value,
                add = function (raw) {
                    // Multi-select values arrive as "A, B" or as option IDs ("37,38"): split, and map an ID to
                    // its label unless it is a label itself (sizes "28", "29", ...).
                    (raw === null || raw === undefined ? '' : String(raw)).split(',').forEach(function (part) {
                        var label = product.decode(part).trim();

                        if (/^\d+$/.test(label) && meta.options.indexOf(label) === -1 && meta.ids[label]) {
                            label = meta.ids[label];
                        }

                        if (label !== '' && !values.some(function (item) {
                            return item.key === label.toLowerCase();
                        })) {
                            values.push({key: label.toLowerCase(), label: label});
                        }
                    });
                };

            if (Array.isArray(attributes)) {
                attribute = attributes.find(function (item) {
                    return item && item.id === code;
                });
                (attribute && Array.isArray(attribute.values) ? attribute.values : []).forEach(function (entry) {
                    (Array.isArray(entry.value) ? entry.value : [entry.value]).forEach(add);
                });
            } else if (attributes && attributes.custom && attributes.custom[code] !== undefined) {
                value = attributes.custom[code];
                (Array.isArray(value) ? value : [value]).forEach(add);
            }

            return values;
        }

        s = {
            config: config,
            labels: labels,
            query: '',
            page: 1,
            limit: config.perPage,
            mode: 'grid',
            sort: 'relevance',
            dir: 'asc',
            categories: [],
            attributes: {},
            otherParams: [],
            minPrice: null,
            maxPrice: null,
            inStockOnly: false,
            priceFrom: '',
            priceTo: '',
            hits: [],
            total: 0,
            facets: [],
            baseFacets: [],
            loading: false,
            searched: false,
            failed: false,
            cartState: {},
            // Layered navigation block opened like Luma's accordion (one at a time).
            openFacet: null,

            // ----- URL state (same parameter names as the category toolbar) -----
            readUrl: function () {
                var params = new URLSearchParams(window.location.search),
                    limit = parseInt(params.get('product_list_limit'), 10),
                    price = (params.get('price') || '').split('-'),
                    self = this;

                this.query = (params.get(config.queryParam) || '').trim();
                this.page = Math.max(1, parseInt(params.get('p'), 10) || 1);
                this.limit = config.limits.indexOf(limit) !== -1 ? limit : config.perPage;
                this.mode = params.get('product_list_mode') === 'list' ? 'list' : 'grid';
                this.sort = SORTS.indexOf(params.get('product_list_order')) !== -1 ? params.get('product_list_order') : 'relevance';
                this.dir = params.get('product_list_dir') === 'desc' ? 'desc' : 'asc';
                this.categories = splitList(params.get('cat'));
                this.minPrice = toNumber(price[0]);
                this.maxPrice = toNumber(price[1]);
                this.priceFrom = nullish(this.minPrice, '');
                this.priceTo = nullish(this.maxPrice, '');
                this.inStockOnly = params.get('stock') === '1';
                this.attributes = {};
                this.otherParams = [];
                params.forEach(function (value, key) {
                    if (RESERVED_PARAMS.indexOf(key) !== -1) {
                        return;
                    }

                    // Only Magento filterable attributes filter; other parameters (___store, utm_*, ...) are
                    // kept in the URL as they are.
                    if (Object.prototype.hasOwnProperty.call(config.filterable, key)) {
                        if (splitList(value).length) {
                            self.attributes[key] = splitList(value);
                        }
                    } else {
                        self.otherParams.push([key, value]);
                    }
                });
            },

            urlFor: function (changes) {
                var state = Object.assign({
                        page: this.page,
                        limit: this.limit,
                        mode: this.mode,
                        sort: this.sort,
                        dir: this.dir,
                        categories: this.categories,
                        attributes: this.attributes,
                        minPrice: this.minPrice,
                        maxPrice: this.maxPrice,
                        inStockOnly: this.inStockOnly
                    }, changes || {}),
                    params = new URLSearchParams();

                params.set(config.queryParam, this.query);
                this.otherParams.forEach(function (param) {
                    params.append(param[0], param[1]);
                });

                if (state.categories.length) {
                    params.set('cat', state.categories.join(','));
                }
                Object.keys(state.attributes).forEach(function (code) {
                    if (state.attributes[code].length) {
                        params.set(code, state.attributes[code].join(','));
                    }
                });

                if (state.minPrice !== null || state.maxPrice !== null) {
                    params.set('price', nullish(state.minPrice, '') + '-' + nullish(state.maxPrice, ''));
                }

                if (state.inStockOnly) {
                    params.set('stock', '1');
                }

                if (state.sort !== 'relevance') {
                    params.set('product_list_order', state.sort);
                }

                if (state.dir === 'desc') {
                    params.set('product_list_dir', 'desc');
                }

                if (state.mode === 'list') {
                    params.set('product_list_mode', 'list');
                }

                if (state.limit !== config.perPage) {
                    params.set('product_list_limit', state.limit);
                }

                if (state.page > 1) {
                    params.set('p', state.page);
                }

                return window.location.pathname + '?' + params;
            },

            update: function (changes, keepPage) {
                Object.assign(this, changes);

                if (!keepPage) {
                    this.page = 1;
                }
                window.history.pushState({}, '', this.urlFor());
                this.search();
            },

            // ----- data -----
            hasAttributeFilters: function () {
                return (this.selectedAttributes() || []).length > 0;
            },

            selectedAttributes: function () {
                var attrs = [],
                    self = this;

                Object.keys(this.attributes).forEach(function (code) {
                    var values = self.attributes[code];

                    if (Array.isArray(values)) {
                        values.forEach(function (val) {
                            if (val !== null && val !== undefined && String(val).trim() !== '') {
                                attrs.push(code + ':' + String(val).trim());
                            }
                        });
                    }
                });

                return attrs.length ? attrs : null;
            },

            sortBy: function () {
                if (this.sort === 'price') {
                    return this.dir === 'desc' ? 'price_desc' : 'price_asc';
                }

                return this.sort === 'newest' ? 'newest' : null;
            },

            search: function () {
                var self = this,
                    selectedAttrs,
                    hasActiveFilters,
                    filters,
                    baseKey,
                    signal,
                    current,
                    request,
                    page,
                    pool;

                if (controller) {
                    controller.abort();
                    controller = null;
                }

                if (!this.query) {
                    this.hits = [];
                    this.total = 0;
                    this.facets = [];
                    this.searched = true;
                    this.loading = false;
                    render();

                    return;
                }

                selectedAttrs = this.selectedAttributes();
                hasActiveFilters = Boolean(
                    (selectedAttrs && selectedAttrs.length) ||
                    this.categories.length ||
                    this.minPrice !== null ||
                    this.maxPrice !== null ||
                    this.inStockOnly
                );
                filters = {
                    query: this.query,
                    storeView: config.storeView || config.storeCode,
                    storeCode: config.storeCode || config.storeView,
                    searchType: config.searchType,
                    hasFilter: hasActiveFilters,
                    category: this.categories.length ? this.categories.join(',') : null,
                    minPrice: this.minPrice,
                    maxPrice: this.maxPrice,
                    inStockOnly: this.inStockOnly || null,
                    sortBy: this.sortBy(),
                    attributes: selectedAttrs
                };
                baseKey = JSON.stringify({
                    query: this.query,
                    storeView: filters.storeView,
                    category: filters.category,
                    minPrice: filters.minPrice,
                    maxPrice: filters.maxPrice,
                    inStockOnly: filters.inStockOnly
                });

                controller = new AbortController();
                signal = controller.signal;
                current = ++requestId;
                this.loading = true;
                this.failed = false;
                render();

                request = function (params, fields) {
                    return fetch(config.graphqlUrl, {
                        method: 'POST',
                        signal: signal,
                        mode: 'cors',
                        credentials: 'omit',
                        headers: context.headers({'Content-Type': 'application/json', 'x-api-key': config.searchKey}),
                        body: JSON.stringify({query: searchQuery(params, fields)})
                    }).then(function (response) {
                        return response.json().catch(function () {
                            return null;
                        }).then(function (payload) {
                            var data = payload && payload.data && payload.data.searchCatalog;

                            return response.ok && data ? data : Promise.reject(new Error(response.status));
                        });
                    });
                };

                page = request(Object.assign({page: this.page, perPage: this.limit}, filters));
                pool = baseKey === poolKey ?
                    Promise.resolve(null) :
                    request(Object.assign({}, filters, {page: 1, perPage: CLIENT_FILTER_LIMIT, hasFilter: false, attributes: null}))
                        .catch(function (error) {
                            return error.name === 'AbortError' ? Promise.reject(error) : {hits: []};
                        });

                Promise.all([page, pool]).then(function (results) {
                    var data = results[0],
                        poolData = results[1],
                        destination,
                        hits,
                        aggs;

                    if (current !== requestId) {
                        return;
                    }
                    destination = data.redirected ? product.absoluteUrl(data.destinationUrl) : '';

                    if (destination) {
                        window.location.replace(destination);

                        return;
                    }
                    hits = Array.isArray(data.hits) ? data.hits : [];
                    aggs = Array.isArray(data.aggregations) ? data.aggregations : [];
                    if (aggs.length > 0) {
                        self.baseFacets = self.readFacets(aggs);
                    }

                    if (poolData) {
                        poolKey = baseKey;
                        poolHits = Array.isArray(poolData.hits) ? poolData.hits : [];
                    }
                    if (self.hasAttributeFilters() && !hits.length && poolHits.length) {
                        self.applyClientFilters();
                    } else {
                        self.hits = hits;
                        self.total = data.total_found || 0;
                        self.buildFacets();
                        self.loading = false;
                        self.searched = true;
                    }
                    render();
                }).catch(function (error) {
                    if (error.name === 'AbortError' || current !== requestId) {
                        return;
                    }
                    self.hits = [];
                    self.total = 0;
                    self.loading = false;
                    self.searched = true;
                    self.failed = true;
                    render();
                });
            },

            matchesAttributes: function (hit, exceptCode) {
                var attributes = this.attributes;

                return Object.keys(attributes).every(function (code) {
                    var values = attributes[code],
                        own;

                    if (code === exceptCode || !values.length) {
                        return true;
                    }
                    own = hitValues(hit, code).map(function (item) {
                        return item.key;
                    });

                    return values.some(function (value) {
                        return own.indexOf(String(value).toLowerCase()) !== -1;
                    });
                });
            },

            applyClientFilters: function () {
                var self = this,
                    filtered = poolHits.filter(function (hit) {
                        return self.matchesAttributes(hit);
                    }),
                    lastPage;

                this.total = filtered.length;
                lastPage = Math.max(1, Math.ceil(this.total / this.limit));

                if (this.page > lastPage) {
                    this.page = lastPage;
                }
                this.hits = filtered.slice((this.page - 1) * this.limit, this.page * this.limit);
                this.buildFacets();
                this.loading = false;
                this.searched = true;
            },

            // Categories and price come from the searchCatalog aggregations; attribute filters are built from
            // the attribute values of all results (the API only aggregates some of them), for every attribute
            // that is filterable in Magento, with Magento's label and option order.
            buildFacets: function () {
                var self = this,
                    attributeFacets = [];

                Object.keys(config.filterable).forEach(function (code) {
                    var attribute = config.filterable[code],
                        options = new Map(),
                        order = attribute.options || [],
                        position = function (option) {
                            var index = order.indexOf(String(option.label).toLowerCase());

                            return index === -1 ? order.length : index;
                        };

                    if (RESERVED_PARAMS.indexOf(code) !== -1) {
                        return;
                    }
                    // Counts: results that match the other attribute filters and have the value.
                    poolHits.filter(function (hit) {
                        return self.matchesAttributes(hit, code);
                    }).forEach(function (hit) {
                        hitValues(hit, code).forEach(function (item) {
                            var option = options.get(item.key) || {value: item.label, label: item.label, count: 0};

                            option.count++;
                            options.set(item.key, option);
                        });
                    });
                    // Selected values stay visible even when no result has them any more.
                    (self.attributes[code] || []).forEach(function (value) {
                        if (!options.has(String(value).toLowerCase())) {
                            options.set(String(value).toLowerCase(), {value: value, label: value, count: 0});
                        }
                    });

                    if (!options.size) {
                        return;
                    }
                    attributeFacets.push({
                        key: code,
                        kind: 'attribute',
                        label: attribute.label,
                        options: Array.from(options.values()).sort(function (a, b) {
                            return position(a) - position(b) ||
                                String(a.label).localeCompare(String(b.label), undefined, {numeric: true});
                        })
                    });
                });
                this.facets = this.baseFacets.concat(attributeFacets);
            },

            readFacets: function (aggregations) {
                return aggregations
                    .filter(function (agg) {
                        return agg && (agg.key === 'categories' ||
                            agg.data && !Array.isArray(agg.data) && typeof agg.data === 'object' && 'min' in agg.data);
                    })
                    .sort(function (a, b) {
                        return (a.order || 0) - (b.order || 0);
                    })
                    .map(function (agg) {
                        var label = product.decode(agg.label || agg.key),
                            byName = new Map(),
                            options;

                        if (agg.key !== 'categories') {
                            return {
                                key: agg.key,
                                kind: 'price',
                                label: label,
                                min: Math.floor(Number(agg.data.min) || 0),
                                max: Math.ceil(Number(agg.data.max) || 0)
                            };
                        }
                        // Categories filter by name, and names repeat across the tree ("Tops" under Men and
                        // Women): merge options with the same name and add up their counts.
                        (Array.isArray(agg.data) ? agg.data : []).forEach(function (item) {
                            var name,
                                known;

                            if (!item || !(item.label || item.key)) {
                                return;
                            }
                            name = product.decode(item.label || item.key);

                            // Categories the index has no name for come back as their bare ID.
                            if (/^\d+$/.test(name)) {
                                return;
                            }
                            known = byName.get(name.toLowerCase());

                            if (known) {
                                known.count += Number(item.count) || 0;
                            } else {
                                byName.set(name.toLowerCase(), {value: name, label: name, count: Number(item.count) || 0});
                            }
                        });
                        options = Array.from(byName.values());

                        return options.length ? {key: agg.key, kind: 'category', label: label, options: options} : null;
                    })
                    .filter(Boolean);
            },

            // ----- filters -----
            selectedValues: function (facet) {
                return facet.kind === 'category' ? this.categories : this.attributes[facet.key] || [];
            },

            isSelected: function (facet, option) {
                return this.selectedValues(facet).some(function (value) {
                    return same(value, option.value);
                });
            },

            visibleOptions: function (facet) {
                var self = this;

                return facet.options.filter(function (option) {
                    return option.count > 0 || self.isSelected(facet, option);
                });
            },

            toggleChanges: function (facet, option) {
                var values = this.selectedValues(facet),
                    next = this.isSelected(facet, option) ?
                        values.filter(function (value) {
                            return !same(value, option.value);
                        }) :
                        values.concat([option.value]),
                    attributes;

                if (facet.kind === 'category') {
                    return {categories: next};
                }
                attributes = Object.assign({}, this.attributes);
                attributes[facet.key] = next;

                return {attributes: attributes};
            },

            toggleUrl: function (facet, option) {
                return this.urlFor(Object.assign({page: 1}, this.toggleChanges(facet, option)));
            },

            isSwatchFacet: function (facet) {
                return facet.kind === 'attribute' && !!config.swatches[facet.key];
            },

            swatch: function (facet, option) {
                var swatches = config.swatches[facet.key] || {};

                return swatches[String(option.label).toLowerCase()] || swatches[String(option.value).toLowerCase()] ||
                    {type: 'text', value: option.label};
            },

            activeFilters: function () {
                var self = this,
                    active = [],
                    facetLabel = function (key, fallback) {
                        return (self.facets.find(function (facet) {
                            return facet.key === key;
                        }) || {}).label || fallback;
                    },
                    priceFacet;

                this.categories.forEach(function (value) {
                    active.push({
                        name: facetLabel('categories', labels.category),
                        label: value,
                        remove: {
                            categories: self.categories.filter(function (item) {
                                return !same(item, value);
                            })
                        }
                    });
                });
                Object.keys(this.attributes).forEach(function (code) {
                    var values = self.attributes[code],
                        facet = self.facets.find(function (item) {
                            return item.key === code;
                        });

                    values.forEach(function (value) {
                        var option = facet && facet.options.find(function (item) {
                                return same(item.value, value);
                            }),
                            attributes = Object.assign({}, self.attributes);

                        attributes[code] = values.filter(function (item) {
                            return !same(item, value);
                        });
                        active.push({
                            name: facetLabel(code, code),
                            label: option ? option.label : value,
                            remove: {attributes: attributes}
                        });
                    });
                });

                if (this.minPrice !== null || this.maxPrice !== null) {
                    priceFacet = this.facets.find(function (facet) {
                        return facet.kind === 'price';
                    });
                    active.push({
                        name: priceFacet ? priceFacet.label : labels.price,
                        label: this.priceRangeLabel(),
                        remove: {minPrice: null, maxPrice: null, priceFrom: '', priceTo: ''}
                    });
                }

                if (this.inStockOnly) {
                    active.push({
                        name: labels.availability,
                        label: labels.inStockOnly,
                        remove: {inStockOnly: false}
                    });
                }

                return active;
            },

            removeUrl: function (filter) {
                return this.urlFor(Object.assign({page: 1}, filter.remove));
            },

            clearAllChanges: function () {
                return {categories: [], attributes: {}, minPrice: null, maxPrice: null, inStockOnly: false, priceFrom: '', priceTo: ''};
            },

            clearAllUrl: function () {
                return this.urlFor(Object.assign({page: 1}, this.clearAllChanges()));
            },

            applyPrice: function (fromValue, toValue) {
                var from = toNumber(fromValue),
                    to = toNumber(toValue),
                    swap;

                if (from !== null && to !== null && from > to) {
                    swap = from;
                    from = to;
                    to = swap;
                }
                this.update({minPrice: from, maxPrice: to, priceFrom: nullish(from, ''), priceTo: nullish(to, '')});
            },

            priceRangeLabel: function () {
                var currency = this.hits[0] && this.hits[0].currencyCode,
                    from = this.minPrice !== null ? product.formatPrice(this.minPrice, currency) : '',
                    to = this.maxPrice !== null ? product.formatPrice(this.maxPrice, currency) : '';

                if (from && to) {
                    return from + ' - ' + to;
                }

                return from ? format(labels.andAbove, from) : format(labels.andBelow, to);
            },

            // ----- toolbar -----
            totalPages: function () {
                return Math.max(1, Math.ceil(this.total / this.limit));
            },

            pageNumbers: function () {
                var last = this.totalPages(),
                    start = Math.max(1, Math.min(this.page - 2, last - 4)),
                    pages = [],
                    n;

                for (n = start; n <= Math.min(last, start + 4); n++) {
                    pages.push(n);
                }

                return pages;
            },

            goTo: function (page) {
                if (page < 1 || page > this.totalPages() || page === this.page) {
                    return;
                }
                this.update({page: page}, true);
                listEl.get(0).scrollIntoView({behavior: 'smooth', block: 'start'});
            },

            amountLabel: function () {
                if (this.total <= this.limit) {
                    return this.total === 1 ? labels.oneItem : format(labels.items, this.total);
                }

                return format(labels.itemsRange, (this.page - 1) * this.limit + 1,
                    Math.min(this.page * this.limit, this.total), this.total);
            },

            // ----- products -----
            productName: function (hit) {
                return product.name(hit);
            },

            productImage: function (hit) {
                return product.image(hit);
            },

            productUrl: function (hit) {
                return product.url(hit);
            },

            prices: function (hit) {
                return product.prices(hit);
            },

            formatPrice: function (value, hit) {
                return product.formatPrice(value, hit && hit.currencyCode);
            },

            isInStock: function (hit) {
                return product.isInStock(hit);
            },

            description: function (hit) {
                var text = product.decode(String(hit.description || '').replace(/<[^>]*>/g, ' ')).replace(/\s+/g, ' ').trim();

                return text.length > 240 ? text.slice(0, 240) + '…' : text;
            },

            cartMode: function (hit) {
                if (!config.addToCart) {
                    return product.isInStock(hit) ? '' : 'out';
                }

                return cart.mode(hit);
            },

            cartLabel: function (hit) {
                var state = this.cartState[hit.id];

                return state === 'adding' ? labels.adding : state === 'added' ? labels.added : config.cart.label;
            },

            addToCart: function (hit) {
                var self = this;

                if (this.cartState[hit.id] === 'adding') {
                    return;
                }
                this.cartState[hit.id] = 'adding';
                render();
                cart.add(hit, 1, this.query).then(function (outcome) {
                    self.cartState[hit.id] = outcome === 'added' ? 'added' : '';

                    if (outcome === 'added') {
                        setTimeout(function () {
                            if (self.cartState[hit.id] === 'added') {
                                self.cartState[hit.id] = '';
                                render();
                            }
                        }, 2000);
                    }
                    render();
                });
            },

            trackClick: function (hit) {
                analytics.click(config.trackEventUrl, hit, this.query);
            }
        };

        function render() {
            var active;

            listEl.html(renderList({data: s}));

            if (!renderFilters) {
                return;
            }
            active = s.activeFilters();
            filterBlock.prop('hidden', !(s.query && (s.facets.length || active.length)));
            filterBlock.find('.filter-title').attr('data-count', active.length);
            filterContent.html(renderFilters({data: s, active: active}));
        }

        // ----- events: product list -----
        listEl.on('click', '[data-action="mode"]', function (event) {
            event.preventDefault();
            s.update({mode: this.getAttribute('data-value')}, true);
        });
        listEl.on('change', '[data-role="sorter"]', function () {
            s.update({sort: this.value, dir: 'asc'});
        });
        listEl.on('click', '[data-action="direction"]', function (event) {
            event.preventDefault();
            s.update({dir: this.getAttribute('data-value')});
        });
        listEl.on('change', '[data-role="limiter"]', function () {
            s.update({limit: parseInt(this.value, 10)});
        });
        listEl.on('click', '[data-action="page"]', function (event) {
            event.preventDefault();
            s.goTo(Number(this.getAttribute('data-page')));
        });
        listEl.on('click', '[data-action="add-to-cart"]', function () {
            s.addToCart(s.hits[Number(this.getAttribute('data-index'))]);
        });
        listEl.on('click', '[data-action="track"]', function () {
            s.trackClick(s.hits[Number(this.getAttribute('data-index'))]);
        });
        listEl.on('click', '[data-action="clear-all"]', function (event) {
            event.preventDefault();
            s.update(s.clearAllChanges());
        });

        // ----- events: layered navigation -----
        filterContent.on('click', '[data-action="facet-toggle"]', function () {
            var key = this.getAttribute('data-key');

            s.openFacet = s.openFacet === key ? null : key;
            render();
        });
        filterContent.on('keydown', '[data-action="facet-toggle"]', function (event) {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                $(this).trigger('click');
            }
        });
        filterContent.on('click', '[data-action="toggle-option"]', function (event) {
            var facet = s.facets[Number(this.getAttribute('data-facet'))],
                option = facet && s.visibleOptions(facet)[Number(this.getAttribute('data-option'))];

            event.preventDefault();

            if (option) {
                s.update(s.toggleChanges(facet, option));
            }
        });
        filterContent.on('click', '[data-action="stock"]', function (event) {
            event.preventDefault();
            s.update({inStockOnly: !s.inStockOnly});
        });
        filterContent.on('click', '[data-action="remove-filter"]', function (event) {
            var filter = s.activeFilters()[Number(this.getAttribute('data-index'))];

            event.preventDefault();

            if (filter) {
                s.update(filter.remove);
            }
        });
        filterContent.on('click', '[data-action="clear-all"]', function (event) {
            event.preventDefault();
            s.update(s.clearAllChanges());
        });
        filterContent.on('submit', '[data-role="nxq-price"]', function (event) {
            event.preventDefault();
            s.applyPrice(this.elements.from.value, this.elements.to.value);
        });

        window.addEventListener('popstate', function () {
            s.readUrl();
            s.search();
        });

        product.setCurrency(config.currencyCode);
        s.readUrl();
        s.search();
    };
});
