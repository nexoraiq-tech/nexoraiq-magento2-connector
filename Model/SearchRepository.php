<?php

/**
 * Copyright © 2025 NexorAIQ. All rights reserved.
 */

declare(strict_types=1);

namespace NexorAIQ\Connector\Model;

use NexorAIQ\Connector\Api\SearchRepositoryInterface;
use Magento\Store\Model\StoreManagerInterface;
use NexorAIQ\Connector\Helper\Data as HelperData;
use NexorAIQ\Connector\Model\Analytics\EventLogger;
use NexorAIQ\Connector\Model\Api\Client;
use Psr\Log\LoggerInterface;

class SearchRepository implements SearchRepositoryInterface
{
    /**
     * @param StoreManagerInterface $storeManager
     * @param HelperData $helper
     * @param LoggerInterface $logger
     * @param Client $client
     * @param EventLogger $eventLogger
     */
    public function __construct(
        private readonly StoreManagerInterface $storeManager,
        private readonly HelperData $helper,
        private readonly LoggerInterface $logger,
        private readonly Client $client,
        private readonly EventLogger $eventLogger
    ) {
    }

    /**
     * @inheritdoc
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
    ): string {
        if (!$this->helper->isEnabled()) {
            return json_encode(['hits' => [], 'total' => 0]);
        }

        try {
            $store = $this->storeManager->getStore($storeId);
            $activeStoreId = (int)$store->getId();
            $storeCode = $store->getCode();

            $params = [
                'q' => $query,
                'page' => $currentPage ?? 1,
                'limit' => $pageSize ?? 12,
                'storeView' => $storeCode
            ];

            if ($category !== null) {
                $params['category'] = $category;
            }
            if ($minPrice !== null) {
                $params['minPrice'] = $minPrice;
            }
            if ($maxPrice !== null) {
                $params['maxPrice'] = $maxPrice;
            }
            if ($sortBy !== null) {
                $params['sortBy'] = $sortBy;
            }
            if ($inStockOnly !== null) {
                $params['inStockOnly'] = $inStockOnly ? 'true' : 'false';
            }
            $effectiveSearchType = $searchType ?? $this->helper->getSearchType($activeStoreId);
            if (!empty($effectiveSearchType)) {
                $params['searchType'] = $effectiveSearchType;
            }

            return json_encode($this->client->get('/search', $params, $activeStoreId));
        } catch (\Throwable $e) {
            $this->logger->error('NexoraIQ Search Repository REST error: ' . $e->getMessage());
            return json_encode(['hits' => [], 'total' => 0, 'error' => $e->getMessage()]);
        }
    }

    /**
     * @inheritdoc
     */
    public function autocomplete(
        string $query,
        ?int $storeId = null
    ): string {
        if (!$this->helper->isEnabled()) {
            return json_encode(['suggestions' => [], 'products' => []]);
        }

        try {
            $store = $this->storeManager->getStore($storeId);
            $activeStoreId = (int)$store->getId();
            $storeCode = $store->getCode();

            $params = [
                'q' => $query,
                'page' => 1,
                'limit' => 5,
                'storeView' => $storeCode
            ];

            try {
                $data = $this->client->get('/search', $params, $activeStoreId);
            } catch (Api\ApiException $e) {
                return json_encode(['suggestions' => [], 'products' => []]);
            }

            // Parse suggestions from categories
            $suggestions = [];
            $aggs = $data['aggregations'] ?? null;
            if (is_array($aggs)) {
                $categories = $aggs['categories'] ?? [];
                foreach ($categories as $cat) {
                    if (count($suggestions) >= 5) {
                        break;
                    }
                    $suggestions[] = $query . ' in ' . $cat;
                }
            }

            if (empty($suggestions)) {
                $suggestions = [$query];
            }

            $output = [
                'suggestions' => $suggestions,
                'products' => $data['hits'] ?? []
            ];

            return json_encode($output);
        } catch (\Throwable $e) {
            $this->logger->error('NexoraIQ Autocomplete Repository REST error: ' . $e->getMessage());
            return json_encode(['suggestions' => [], 'products' => [], 'error' => $e->getMessage()]);
        }
    }

    /**
     * @inheritdoc
     */
    public function trackSearch(
        string $query,
        string $sessionId,
        int $resultsCount,
        int $tookMs,
        ?string $userId = null,
        ?string $device = null,
        ?int $storeId = null
    ): bool {
        if (!$this->helper->isEnabled()) {
            return false;
        }

        try {
            $store = $this->storeManager->getStore($storeId);
            return $this->eventLogger->logSearch(
                $query,
                $sessionId,
                $resultsCount,
                $tookMs,
                $userId,
                $device,
                $store
            ) !== null;
        } catch (\Throwable $e) {
            $this->logger->error('NexoraIQ TrackSearch REST error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * @inheritdoc
     */
    public function trackEvent(
        string $eventType,
        string $sessionId,
        ?string $productId = null,
        ?string $query = null,
        ?int $storeId = null,
        ?string $source = null
    ): bool {
        $eventType = strtoupper(trim($eventType));
        if (!in_array($eventType, [EventLogger::CLICK], true)) {
            return false;
        }

        $result = $this->eventLogger->log($eventType, [
            'sessionId' => $sessionId,
            'query'     => $query,
            'productId' => $this->eventLogger->normalizeProductId($productId),
            'source'    => $source,
        ], $storeId);

        return $result !== null;
    }
}
