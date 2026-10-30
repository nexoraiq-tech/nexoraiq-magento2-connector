# Changelog

All notable changes to the NexorAIQ Magento 2 Connector (`NexorAIQ_Connector`) are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [Unreleased]

- Additional telemetry attribution metrics for advanced merchandising campaigns.
- Enhanced multi-warehouse inventory batching for high-throughput enterprise catalogs.

## [1.0.3] - 2026-10-30

Magento Marketplace technical review fixes.

### Fixed
- Catalog indexer in "Update by Schedule" mode: product IDs from the changelog arrived as strings, so every scheduled product sync failed with a TypeError. IDs are now converted to integers.

### Changed
- All calls to the NexorAIQ API use Magento's HTTP client (`Magento\Framework\HTTP\Client\Curl`) instead of raw `curl_*` functions.
- REST `sync-status/reset`, `sync-status/update-batch` and `active-store-views` answer HTTP 401 for a wrong connection key (they returned HTTP 200 before).
- Product repository plugin: dependencies are injected instead of loaded through the object manager; review summaries are read from `review_entity_summary` (product reviews only), so the optional Magento_Review classes are not needed.
- Multi-Source Inventory salable quantity is resolved in one adapter (`Model/Inventory/SalableQtyProvider`), only when MSI is enabled.
- `composer.json` requires `magento/module-cron`, `magento/module-theme` and `laminas/laminas-uri`, which the module uses.
- `parse_url()` replaced with `Laminas\Uri`; `RequestContext::detectDevice()` and `Attribution::normalize()` are no longer static.

### Added
- `i18n/en_US.csv` with all translatable strings.

## [1.0.2] - 2026-10-29

Magento Marketplace readiness and security release. Supports Magento Open Source / Adobe Commerce 2.4.6 - 2.4.9.

### Security
- `GET /V1/nexoraiq/ping` returned the integration access token, product count, store count and Magento version to callers **without a connection key**. It now returns them only for a valid connection key; anonymous calls only confirm the module is installed.
- `GET /V1/nexoraiq/status` no longer exposes the Magento version and edition.
- Connection key checks use constant-time comparison (`hash_equals`).
- All output in the admin product sync panel and the admin plan card is escaped.

### Added
- `etc/csp_whitelist.xml`: allows `https://api.nexoraiq.tech` in `connect-src` for stores using Content Security Policy.
- Analytics: CLICK, ADD_TO_CART and CONVERSION events carry `source` ("trending" for the search overlay's trending products, otherwise blank); trending products are sent with query "trending".
- `composer.json` declares its version, PHP and Magento dependencies; MSI, GraphQL, CSP and Review are optional (`suggest`).

### Changed
- `nexoraiq_sync_block` is declared in `db_schema.xml`; tables are no longer created at runtime with raw SQL.
- Dependencies are injected instead of fetched with the ObjectManager (Helper, admin product sync panel).
- The Hyvä templates work with Hyvä versions without the CSP view model or Lucide icons (Heroicons fallback).
- `StoreInfo` sets its no-cache headers through the REST response instead of `header()`.
- The module version reported by the API is read from `composer.json`.
- Removed `setup_version` from `module.xml` (declarative schema only).
- README: compatibility table for 2.4.6 - 2.4.9, privacy section, theme support described accurately.

## [1.0.1] - 2026-10-28

Maintenance and performance release focusing on inventory batch query optimizations, manual resync override control, stock-aware deduplication, and refined admin plan UI.

### Changed
- **Direct Database Stock Batching in ProductRepositoryPlugin**: Replaced collection-based stock item queries with direct `ResourceConnection` SQL querying against `cataloginventory_stock_item` to eliminate DI adapter instantiation crashes on bulk product requests.
- **Admin Subscription Plan & Usage UI**:
  - Renamed the usage header in the General Settings plan card from `Usage this period` to `Plan / Usage this period`.
  - Hid the redundant static `Plan limits` tile grid in favor of unified visual resource progress meters.
  - Added `'usage'` data mapping in `Controller\Adminhtml\System\PlanInfo` response.
- **Brand Consistency**: Standardized internal log messages and code comments to `NexorAIQ`.

### Added
- **Manual Product Resync Force Flag**: Added `$force` parameter support to `CatalogAction::execute($ids, $force)` and wired it to `Controller\Adminhtml\Product\Sync`, allowing on-demand manual product resyncs from the Magento Admin to immediately bypass the 5-minute deduplication skip window.
- **Stock-Aware Sync Deduplication**: Added `$requireStock = true` check to `SyncStatusManager::isRecentlySynced()` and `getRecentlySyncedProductIds()`, ensuring products are only skipped if their previous sync record contains complete stock/quantity data.

---

## [1.0.0] - 2026-10-28


Initial stable release of the **NexorAIQ Magento 2 Connector**, providing enterprise AI-powered search, multilingual product discovery, automated catalog synchronization, and shopper telemetry for Magento Open Source, Adobe Commerce, and Mage-OS.

### Added

#### AI Search & Storefront Experience
- **Multi-Mode Search Retrieval**: Configurable search mode per store view:
  - `Keyword Search`: High-precision lexical matching with typo tolerance.
  - `Vector Search`: Deep semantic AI embeddings for natural language and multilingual discovery.
  - `Hybrid Search`: Optimized blend of keyword and semantic scoring for maximum relevancy.
- **Storefront UI Takeover**:
  - **Autocomplete Dropdown**: Ultra-fast predictive suggestions, product thumbnails, live pricing, and stock status as shoppers type.
  - **Fullscreen Search Overlay**: Responsive overlay supporting 1-column layout or 2-column discovery view (query suggestions, popular categories, and brand showcases).
  - **Dedicated Search Results Page**: Custom high-performance search page with sorting, pagination, and responsive grid/list views.
  - **Layered Navigation & Facets**: Dynamic multi-select attribute filters, stock availability toggles, and price range sliders/inputs.
  - **Direct Add-to-Cart**: Instant purchase conversion directly from the search dropdown, overlay, and search results list.

#### Theme & Frontend Architecture
- **Unified Multi-Theme Compatibility**:
  - **Hyvä Themes**: Native Alpine.js components and Tailwind CSS integration designed for top Core Web Vitals scores.
  - **Luma & Blank Themes**: Modular RequireJS and Knockout.js components for traditional Magento 2 storefronts.
  - **Headless & PWA**: Full compatibility with PWA Studio, Next.js, React, and Vue Storefront via secure proxy APIs.
  - Automatic runtime theme detection without manual storefront configuration.

#### Real-Time Catalog Synchronization & Indexing
- **Dedicated Magento Indexer**: `nexoraiq_catalog` supporting on-demand and scheduled full catalog synchronization (`bin/magento indexer:reindex nexoraiq_catalog`).
- **Asynchronous Background Queue**: Magento cron job (`nexoraiq_index_queue`) executing every minute to sync catalog changes in lightweight background batches without blocking checkout or admin operations.
- **Real-Time Event Observers**:
  - Product creation and updates (`catalog_product_save_after`).
  - Product deletion (`catalog_product_delete_before`).
  - Traditional stock level adjustments (`cataloginventory_stock_item_save_commit_after`).
- **Multi-Source Inventory (MSI) & Stock Reservations**:
  - Interceptors for source item mutations (`SourceItemsSaveInterface`, `SourceItemsDeleteInterface`) to track warehouse-level changes.
  - Real-time stock reservation capture (`AppendReservationsInterface`) during checkout to maintain exact stock availability in search results.
- **Batching & Reindex Optimization**:
  - Configurable bulk batch sizes (100, 150, 200, or 300 products per API payload).
  - Configurable "Full Reindex Skip Window" to skip recently synced entities and conserve API quota during full reindexing.

#### Headless API Proxies & Security
- **Secure Server-Side Proxy Pattern**: Frontend clients query Magento directly, ensuring private admin API keys (`nxk_live_...`) remain protected on the server.
- **GraphQL API (`/graphql`)**:
  - `nexoraiqSearch`: Comprehensive catalog search query with filtering, sorting, pagination, and timing metrics.
  - `nexoraiqAutocomplete`: High-speed suggestions and product matches.
  - `nexoraiqTrackSearch`: Search impression and query telemetry mutation.
  - `nexoraiqTrackEvent`: Shopper interaction and click event mutation.
- **REST API (`/rest/V1/nexoraiq/`)**:
  - `GET /rest/V1/nexoraiq/search`: Search endpoint with query parameter filtering.
  - `GET /rest/V1/nexoraiq/autocomplete`: Autocomplete suggestion endpoint.
  - `GET /rest/V1/nexoraiq/config`: Dynamic storefront configuration endpoint.
  - `POST /rest/V1/nexoraiq/analytics/search`: Query search analytics ingestion endpoint.
  - `POST /rest/V1/nexoraiq/analytics/event`: Interaction event tracking endpoint.

#### Shopper Telemetry & Analytics
- **Live Search Interaction Tracking**: Captures search query terms, impression counts, response latency, zero-result rates, and user devices.
- **Shopper Behavior & Conversion Tracking**: Captures product clicks, add-to-cart triggers, and order conversions via `sales_order_place_after` observer for continuous AI model training and relevance tuning.

#### Admin Management & Diagnostics
- **Interactive Configuration Panel**: Located under **Stores > Configuration > NexorAIQ**:
  - Live connection verification and real-time subscription plan badge with synchronized product counters.
  - Custom product attribute selection for AI indexing.
  - Brand and manufacturer attribute mapping for visual brand tiles and facet filtering.
- **Product Edit Page Sync Badges**: Per-store view live status badges (`SYNCED`, `IN QUEUE`, `SKIPPED`) with one-click on-demand manual **Resync Product** action.
- **Subsystem-Isolated Diagnostics**: Dedicated structured logging under `var/log/NexoraIQ/`:
  - `indexer.log` (Catalog indexing and queue progress)
  - `cron.log` (Background queue execution)
  - `observer.log` (Real-time product and stock events)
  - `plugin.log` (MSI inventory and stock reservations)
  - `webhook.log` (Outbound synchronization HTTP calls)
  - `api.log` (REST API proxy transactions)
  - `graphql.log` (GraphQL query and mutation resolvers)
  - `admin.log` (Admin configuration actions and manual syncs)

### Compatibility & System Requirements

- **Platform Compatibility**:
  - Magento Open Source 2.4.4 – 2.4.9
  - Adobe Commerce 2.4.4 – 2.4.9
  - Mage-OS 2.4.x
- **PHP Compatibility**:
  - PHP 8.1, 8.2, 8.3, 8.4, and PHP 8.5 (PHP 8.5 recommended for Magento / Adobe Commerce 2.4.9 production)
- **Database / Search Engine**:
  - MariaDB 10.4+ / MySQL 8.0+
  - Compatible alongside OpenSearch 1.3 / 2.x and Elasticsearch 7.x / 8.x
- **Storefront Compatibility**:
  - Hyvä Themes 1.1.x – 1.3.x
  - Magento Luma and Blank Themes
  - Custom Headless Frontends (PWA Studio, Next.js, Nuxt, Vue Storefront)

---

