<?php

/**
 * Copyright © 2025 NexorAIQ. All rights reserved.
 */

declare(strict_types=1);

namespace NexorAIQ\Connector\Api;

interface SearchRepositoryInterface
{
    /**
     * Proxy search query securely to NexoraIQ
     *
     * @param string $query
     * @param int|null $currentPage
     * @param int|null $pageSize
     * @param string|null $category
     * @param float|null $minPrice
     * @param float|null $maxPrice
     * @param string|null $sortBy
     * @param bool|null $inStockOnly
     * @param int|null $storeId
     * @return string Serialized JSON response
     */
    public function search(
        string $query,
        ?int $currentPage = 1,
        ?int $pageSize = 12,
        ?string $category = null,
        ?float $minPrice = null,
        ?float $maxPrice = null,
        ?string $sortBy = null,
        ?bool $inStockOnly = null,
        ?int $storeId = null,
        ?string $searchType = null
    ): string;

    /**
     * Proxy autocomplete query securely to NexoraIQ
     *
     * @param string $query
     * @param int|null $storeId
     * @return string Serialized JSON response
     */
    public function autocomplete(
        string $query,
        ?int $storeId = null
    ): string;

    /**
     * Proxy search query analytics securely to NexoraIQ
     *
     * @param string $query
     * @param string $sessionId
     * @param int $resultsCount
     * @param int $tookMs
     * @param string|null $userId
     * @param string|null $device
     * @param int|null $storeId
     * @return bool
     */
    public function trackSearch(
        string $query,
        string $sessionId,
        int $resultsCount,
        int $tookMs,
        ?string $userId = null,
        ?string $device = null,
        ?int $storeId = null
    ): bool;

    /**
     * Proxy storefront analytics events to the NexorAIQ "logEvent" GraphQL mutation for this store view.
     *
     * Only CLICK (query + productId) is accepted here; ADD_TO_CART is logged server-side when a product found
     * through NexorAIQ search is added to the cart, and CONVERSION (with revenue) when an order is placed.
     *
     * @param string $eventType CLICK
     * @param string $sessionId
     * @param string|null $productId Search hit externalId or "{websiteId}_{storeId}_{entityId}" (CLICK)
     * @param string|null $query
     * @param int|null $storeId
     * @param string|null $source "trending" for the search overlay's trending products, otherwise blank
     * @return bool
     */
    public function trackEvent(
        string $eventType,
        string $sessionId,
        ?string $productId = null,
        ?string $query = null,
        ?int $storeId = null,
        ?string $source = null
    ): bool;
}
