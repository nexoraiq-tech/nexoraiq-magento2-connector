# NexorAIQ Magento 2 Connector — Release Notes

## Version 1.0.0 (GA)

**Release Date:** October 28, 2026  
**Module Name:** `NexorAIQ_Connector`  
**Package:** `nexoraiq/connector`  
**Target Platforms:** Magento Open Source 2.4.4–2.4.9, Adobe Commerce 2.4.4–2.4.9, Mage-OS 2.4.x  
**PHP Versions:** PHP 8.1, 8.2, 8.3, 8.4, 8.5 (PHP 8.5 recommended for Magento 2.4.9 production)  

---

### Executive Summary

NexorAIQ is pleased to announce the General Availability (GA) of the **NexorAIQ Magento 2 Connector v1.0.0**. This extension connects your Magento catalog with NexorAIQ’s high-performance AI Search and Discovery platform, delivering sub-second semantic search, intelligent autocomplete, automated real-time inventory synchronization, and shopper telemetry.

Whether your storefront runs on modern **Hyvä Themes** (Alpine.js + Tailwind CSS), traditional **Luma / Blank Themes** (RequireJS + Knockout), or **Headless architectures** (Next.js, React, PWA Studio, Vue Storefront), the connector delivers seamless drop-in search functionality with zero breaking changes to your existing catalog structure.

---

### Key Features & Enhancements

#### 1. AI Search & Storefront Discovery
- **Multi-Mode Search Engine**:
  - **Keyword**: High-speed lexical search with advanced typo-tolerance and synonym mapping.
  - **Vector Semantic**: Deep vector embedding search allowing natural language, intent-based, and multilingual queries.
  - **Hybrid**: Dynamic blend of lexical and semantic retrieval for optimal conversion and relevance.
- **Storefront UI Takeover**:
  - **Autocomplete Dropdown**: Instant search-as-you-type with query recommendations, product thumbnail previews, prices, and stock indicators.
  - **Fullscreen Search Overlay**: Responsive overlay available in 1-column layout or 2-column layout (including query suggestions, category tiles, and brand carousels).
  - **Custom Search Results Page**: Dedicated high-speed search page with sorting, pagination, and grid/list views.
  - **Layered Navigation & Facets**: Instant attribute filtering, stock status toggle (`inStockOnly`), and price range sliders/inputs.
  - **Quick Add-to-Cart**: Convert shoppers faster with direct "Add to Cart" buttons inside search overlays and search result cards.

#### 2. Native Multi-Theme Support
- **Hyvä Themes**: Built with pure Alpine.js and Tailwind CSS for top Core Web Vitals and 100/100 Lighthouse performance.
- **Luma / Blank Themes**: AMD / RequireJS and Knockout.js components with responsive fallback styling.
- **Automatic Theme Detection**: Automatically loads the matching theme renderer without requiring manual admin toggles.

#### 3. Real-Time Catalog Synchronization & Indexing
- **Dedicated Magento Indexer**:
  - `nexoraiq_catalog` indexer supporting CLI full reindexing (`bin/magento indexer:reindex nexoraiq_catalog`).
  - Batching support with configurable batch sizes (100, 150, 200, 300 products per API payload).
  - "Full Reindex Skip Window" setting to prevent redundant indexing of recently synced products.
- **Asynchronous Background Queue**:
  - Scheduled Magento cron job (`nexoraiq_index_queue`) executing every minute to process queued product updates without impacting checkout or admin performance.
- **Real-Time Catalog Observers**:
  - Instant synchronization on product creation, updates, and deletion.
  - Traditional stock level tracking (`cataloginventory_stock_item_save_commit_after`).
- **Multi-Source Inventory (MSI) & Reservations**:
  - Interceptors for source item mutations (`SourceItemsSaveInterface`, `SourceItemsDeleteInterface`) for multi-warehouse accuracy.
  - Real-time stock reservation capture (`AppendReservationsInterface`) during checkout to prevent overselling.

#### 4. Headless & Secure Proxy Architecture
- **Protected Credentials**: Client-side storefronts interact with Magento proxy endpoints, keeping private admin API keys (`nxk_live_...`) securely hidden on the server.
- **GraphQL Storefront API (`/graphql`)**:
  - Queries: `nexoraiqSearch`, `nexoraiqAutocomplete`
  - Mutations: `nexoraiqTrackSearch`, `nexoraiqTrackEvent`
- **REST Storefront API (`/rest/V1/nexoraiq/`)**:
  - Endpoints: `/search`, `/autocomplete`, `/config`, `/analytics/search`, `/analytics/event`

#### 5. Shopper Telemetry & Conversion Analytics
- Continuous AI relevance training through automated telemetry:
  - Query logging (terms, impressions, latency, zero-result queries, device types).
  - Shopper engagement tracking (clicks, facet selections, quick add-to-cart).
  - Conversion attribution tracked via `sales_order_place_after` observer.

#### 6. Admin Experience & Diagnostics
- **Live Admin Header Banner**: Real-time status display of connection health, active subscription tier, live synced product count, and active store views.
- **Product Edit Page Sync Badges**: Visual indicator (`SYNCED`, `IN QUEUE`, `SKIPPED`) per store view with one-click **Resync Product** button.
- **Subsystem-Isolated Logging**: Structured logs stored under `var/log/NexoraIQ/`:
  - `indexer.log`, `cron.log`, `observer.log`, `plugin.log`, `webhook.log`, `api.log`, `graphql.log`, `admin.log`.

---

### Platform & Environment Compatibility

| Component | Supported Versions | Notes |
| :--- | :--- | :--- |
| **Magento Open Source** | 2.4.4, 2.4.5, 2.4.6, 2.4.7, 2.4.8, 2.4.9 | Full support across 2.4.x release line |
| **Adobe Commerce** | 2.4.4, 2.4.5, 2.4.6, 2.4.7, 2.4.8, 2.4.9 | On-premise and Adobe Commerce on Cloud |
| **Mage-OS** | 2.4.x | Compatible with Mage-OS distributions |
| **PHP** | 8.1, 8.2, 8.3, 8.4, 8.5 | PHP 8.5 recommended for Magento 2.4.9 in production |
| **Frontend Themes** | Hyvä 1.1–1.3+, Luma, Blank, PWA Studio | Native Alpine.js, Tailwind, RequireJS |
| **Database** | MariaDB 10.4+, MySQL 8.0+ | Compatible alongside OpenSearch & Elasticsearch |

---

### Installation & Deployment Steps

```bash
# 1. Enable extension
bin/magento module:enable NexorAIQ_Connector

# 2. Upgrade database schema and data
bin/magento setup:upgrade

# 3. Compile dependency injection
bin/magento setup:di:compile

# 4. Deploy static content (production mode)
bin/magento setup:static-content:deploy -f

# 5. Flush cache
bin/magento cache:flush

# 6. Execute initial catalog reindex
bin/magento indexer:reindex nexoraiq_catalog
```

---

### Verification Checklist

- [x] Configure Connection Key (`nxk_live_...`) in **Stores > Configuration > NexorAIQ > General Settings**.
- [x] Verify that the header banner displays **Active Plan** and **Connected** status.
- [x] Run `bin/magento indexer:reindex nexoraiq_catalog` and confirm products are indexed.
- [x] Ensure Magento cron is active (`bin/magento cron:run`).
- [x] Test search bar on frontend: verify autocomplete dropdown, overlay, and search results page.
- [x] Inspect `var/log/NexoraIQ/` to confirm clean execution without errors.
