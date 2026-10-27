# NexorAIQ | AI Commerce Search - Magento 2.4.x Connector (Hyvä + Luma / Blank / other themes)

`NexorAIQ_Connector` is the single connector for every storefront theme. It replaces the two older
single-theme editions, `NexorAIQ_HyvaConnector` and `NexorAIQ_MagentoConnector` (keep those disabled:
they share configuration paths, database tables, routes and API endpoints).

## Theme switch

The storefront UI follows the theme assigned to each store view in **Content > Design > Configuration**:

| Theme assigned | Layout handles | Storefront UI |
| --- | --- | --- |
| Hyvä (`Hyva/*` or a child of it) | `hyva_*` (added by `Hyva_Theme`) | `view/frontend/templates/hyva/**` (Alpine.js), `web/css/hyva/*` |
| Any other theme (Luma, Blank, ...) | `nexoraiq_luma_*` (added by `Observer/AddLumaLayoutHandles`) | `view/frontend/templates/luma/**` + `web/js/*` (RequireJS), `web/css/luma/*` |

`Model/Theme/ThemeDetector` makes the decision (it uses Hyvä's own theme check when `Hyva_Theme` is
installed). Everything else (admin settings, catalog sync, indexer, REST / GraphQL APIs, analytics) is shared.
After changing the theme, flush the cache (`bin/magento cache:flush`).

Storefront takeover:
* **Header search** (Luma: `top.search`, `luma/search/form.phtml` + `js/search-form.js`;
  Hyvä: `header-search`, `hyva/search/form.phtml` + `hyva/search/shared.phtml`):
  NexorAIQ autocomplete dropdown or full screen overlay (1 or 2 columns), Add to Cart on the overlay.
* **Search results page** (`nexoraiq_search_index`; Luma: `luma/search/page.phtml`, `luma/search/page/filters.phtml`
  + `js/search-page.js`; Hyvä: `hyva/search/page.phtml`, `hyva/search/page/filters.phtml`): category page
  markup (toolbar, grid / list, pager, layered navigation with swatches), multi-select filters, price range,
  availability, Add to Cart.
* Every NexorAIQ API call (browser and server) sends `x-session-id`, `x-user-id`
  (`user_` + base64(email), or `guest_1`) and `x-device` (`web_desktop`, `web_mobile`, `web_tablet`).

The **NexorAIQ Connector** is a secure, headless-first Magento 2 extension that synchronizes catalog information in real-time and acts as a secure reverse proxy for storefront search, autocomplete, and shopper behavior tracking (telemetry).

By proxying all frontend search queries and analytics tracking events through Magento, the headless frontend (React, Next.js, etc.) never connects directly to the NexorAIQ SaaS backend, protecting your private API credentials and search keys from exposure on the client side.

---

## Features

1. **Real-time Catalog Indexing:** Hooks into product save/delete events. Automatically flags disabled products as deleted.
2. **Multi-Source Inventory (MSI) Support:** Syncs warehouse inventory updates in real-time when ERP or admin updates warehouse quantities.
3. **Checkout Stock Reservations:** Captures order placement checkout reservations, cancellations, shipments, and refunds to update salable stock immediately.
4. **Secure Search Proxy:** Proxy searches and autocomplete suggestions via GraphQL & REST.
5. **Store-View Scoped Search Types:** Configure keyword, vector semantic, or hybrid AI search modes per store view.
6. **Shopper Telemetry Tracking:** Proxy shopper search query logs, clicks, cart adds, and purchase event conversions to the SaaS analytics service.

---

## Installation & Setup

1. Copy the code into the directory `app/code/NexorAIQ/Connector/` on your Magento 2.4.x server
   (or `composer require nexoraiq/module-connector`). If `NexorAIQ_HyvaConnector` or
   `NexorAIQ_MagentoConnector` is installed, disable and remove it first.
2. Run standard Magento compilation and upgrade scripts:
   ```bash
   php bin/magento setup:upgrade
   php bin/magento setup:di:compile
   php bin/magento cache:flush
   ```

---

## Configuration

In the Magento Admin Dashboard, navigate to **Stores > Configuration > NexorAIQ | AI Commerce Search > General Settings**:
* **Enabled:** Enable/disable the integration.
* **API URL:** The URL of the NexorAIQ API instance (e.g. `https://api.nexoraiq.tech`).
* **Connection Key:** Your secure administrator key (encrypted in database).
* **Search Key:** Storefront public search key used automatically by the search proxies.
* **Search Type:** Search mode (`keyword`, `vector`, `hybrid`) configured per store view.

---

## Headless API Reference

### 1. GraphQL API Specifications

All GraphQL endpoints are accessible at `/graphql`.

#### A. Retrieve search configuration & keys
Use this query to fetch storefront credentials, facets, column structures, and autocomplete configs dynamically.

**Query:**
```graphql
query {
  nexoraiqSearchConfig(storeId: 1) {
    general {
      enabled
      apiUrl
      publicSearchKey
    }
  }
}
```

---

#### B. Storefront Search
Proxy storefront product searches securely through Magento.

**Query:**
```graphql
query {
  nexoraiqSearch(
    query: "backpack"
    currentPage: 1
    pageSize: 12
    category: "Gear"
    minPrice: 10.0
    maxPrice: 150.0
    sortBy: "price_asc"
    inStockOnly: true
  ) {
    total
    currentPage
    totalPage
    searchTimeMs
    hits {
      id
      title
      sku
      price
      compareAtPrice
      imageUrl
      inStock
    }
  }
}
```

---

#### C. Autocomplete & Suggestions
Retrieve search queries suggestions and matching products.

**Query:**
```graphql
query {
  nexoraiqAutocomplete(query: "back") {
    suggestions
    products {
      id
      title
      sku
      price
      imageUrl
    }
  }
}
```

---

#### D. Telemetry: Track Search Query Logs
Log shopper search query interactions to train relevance algorithms.

**Mutation:**
```graphql
mutation {
  nexoraiqTrackSearch(
    query: "backpack"
    sessionId: "session_user_99a8c"
    resultsCount: 24
    tookMs: 45
    device: "Desktop"
  )
}
```
*Returns `true` on success, `false` otherwise.*

---

#### E. Telemetry: Track Shopper Click & Conversion Events
Track shopper clicks, cart actions, and purchases.

**Mutation:**
```graphql
mutation {
  nexoraiqTrackEvent(
    eventType: "CLICK"  # Options: CLICK, ADD_TO_CART, CONVERSION
    productId: "2039"
    query: "backpack"
    sessionId: "session_user_99a8c"
    revenue: 45.00      # Optional (only for purchase conversion events)
  )
}
```
*Returns `true` on success, `false` otherwise.*

---

### 2. REST API Specifications

All REST endpoints accept and return JSON payloads.

#### A. Search Products
* **Endpoint:** `GET /rest/V1/nexoraiq/search`
* **Query Parameters:**
  * `query` (string, required)
  * `currentPage` (int, default 1)
  * `pageSize` (int, default 12)
  * `category` (string, optional)
  * `minPrice` (float, optional)
  * `maxPrice` (float, optional)
  * `sortBy` (string, optional)
  * `inStockOnly` (boolean, optional)

**Example Call:**
```bash
curl -s "https://yourmagento.com/rest/V1/nexoraiq/search?query=backpack&currentPage=1&pageSize=5"
```

---

#### B. Autocomplete & Suggestions
* **Endpoint:** `GET /rest/V1/nexoraiq/autocomplete`
* **Query Parameters:**
  * `query` (string, required)

**Example Call:**
```bash
curl -s "https://yourmagento.com/rest/V1/nexoraiq/autocomplete?query=back"
```

---

#### C. Telemetry: Track Search Query Logs
* **Endpoint:** `POST /rest/V1/nexoraiq/analytics/search`
* **JSON Body Parameters:**
  * `query` (string, required)
  * `sessionId` (string, required)
  * `resultsCount` (int, required)
  * `tookMs` (int, required)
  * `userId` (string, optional)
  * `device` (string, optional)

**Example Call:**
```bash
curl -X POST -H "Content-Type: application/json" \
  -d '{"query": "backpack", "sessionId": "sess_123", "resultsCount": 24, "tookMs": 35}' \
  https://yourmagento.com/rest/V1/nexoraiq/analytics/search
```

---

#### D. Telemetry: Track Shopper Events
* **Endpoint:** `POST /rest/V1/nexoraiq/analytics/event`
* **JSON Body Parameters:**
  * `eventType` (string, required) - `CLICK`, `ADD_TO_CART`, `CONVERSION`
  * `productId` (string, required)
  * `query` (string, required)
  * `sessionId` (string, required)
  * `revenue` (float, optional)

**Example Call:**
```bash
curl -X POST -H "Content-Type: application/json" \
  -d '{"eventType": "CLICK", "productId": "2039", "query": "backpack", "sessionId": "sess_123"}' \
  https://yourmagento.com/rest/V1/nexoraiq/analytics/event
```
