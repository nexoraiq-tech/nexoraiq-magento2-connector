<?php
/**
 * Copyright © 2026 NexorAIQ. All rights reserved.
 */

declare(strict_types=1);

namespace NexorAIQ\Connector\Plugin;

use Magento\Catalog\Api\Data\ProductExtensionFactory;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Api\Data\ProductSearchResultsInterface;
use Magento\CatalogInventory\Api\Data\StockItemInterfaceFactory;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Store\Model\StoreManagerInterface;
use NexorAIQ\Connector\Api\Data\ReviewSummaryInterfaceFactory;
use NexorAIQ\Connector\Model\Inventory\SalableQtyProvider;
use Psr\Log\LoggerInterface;

class ProductRepositoryPlugin
{
    private static bool $isEnriching = false;

    public function __construct(
        private readonly StockRegistryInterface $stockRegistry,
        private readonly StoreManagerInterface $storeManager,
        private readonly ReviewSummaryInterfaceFactory $reviewSummaryFactory,
        private readonly LoggerInterface $logger,
        private readonly ResourceConnection $resourceConnection,
        private readonly StockItemInterfaceFactory $stockItemFactory,
        private readonly ProductExtensionFactory $productExtensionFactory,
        private readonly SalableQtyProvider $salableQtyProvider
    ) {
    }

    /**
     * Review summaries (rating_summary, reviews_count) per product ID for one store view.
     *
     * Read from the review_entity_summary table so the optional Magento_Review module's classes
     * are not needed; returns an empty array when the review tables are not installed.
     *
     * @param int[] $productIds
     * @param int $storeId
     * @return array<int, array{rating_summary: float, reviews_count: int}>
     */
    private function loadReviewSummaries(array $productIds, int $storeId): array
    {
        $conn = $this->resourceConnection->getConnection();
        $summaryTable = $this->resourceConnection->getTableName('review_entity_summary');
        $entityTable = $this->resourceConnection->getTableName('review_entity');
        if (!$productIds || !$conn->isTableExists($summaryTable) || !$conn->isTableExists($entityTable)) {
            return [];
        }
        $productEntityType = $conn->select()
            ->from($entityTable, ['entity_id'])
            ->where('entity_code = ?', 'product');
        $select = $conn->select()
            ->from($summaryTable, ['entity_pk_value', 'rating_summary', 'reviews_count'])
            ->where('entity_pk_value IN (?)', array_map('intval', $productIds))
            ->where('store_id = ?', $storeId)
            ->where('entity_type IN (?)', $productEntityType);
        $summaries = [];
        foreach ($conn->fetchAll($select) as $row) {
            $summaries[(int)$row['entity_pk_value']] = [
                'rating_summary' => (float)$row['rating_summary'],
                'reviews_count'  => (int)$row['reviews_count'],
            ];
        }
        return $summaries;
    }

    /**
     * Guards against stale generated ProductExtension code (e.g. mid-deploy on production
     * before setup:di:compile has regenerated the class for this attribute).
     */
    private function setSalableQty(\Magento\Catalog\Api\Data\ProductExtensionInterface $ext, float $qty): void
    {
        if (method_exists($ext, 'setSalableQty')) {
            $ext->setSalableQty($qty);
        }
    }

    /**
     * Same stale-generated-code guard as setSalableQty() above — review_summary is also a
     * custom extension attribute, so setReviewSummary() may not exist yet on ProductExtension
     * until setup:di:compile / code generation has regenerated it for this attribute.
     */
    private function setReviewSummary(
        \Magento\Catalog\Api\Data\ProductExtensionInterface $ext,
        \NexorAIQ\Connector\Api\Data\ReviewSummaryInterface $reviewSummary
    ): void {
        if (method_exists($ext, 'setReviewSummary')) {
            $ext->setReviewSummary($reviewSummary);
        }
    }

    public function afterGet(
        ProductRepositoryInterface $subject,
        ProductInterface $product
    ): ProductInterface {
        if (self::$isEnriching) {
            return $product;
        }
        self::$isEnriching = true;
        try {
            $productId = $product->getId();
            $ext = $product->getExtensionAttributes();
            if (!$ext) {
                $ext = $this->productExtensionFactory->create();
            }

            // 1. Load Stock Item
            $stockItem = null;
            try {
                $stockItem = $this->stockRegistry->getStockItem($productId, $product->getStoreId());
                if ($stockItem && $stockItem->getItemId()) {
                    $ext->setStockItem($stockItem);
                }
            } catch (\Throwable $e) {

            }

            // 2. Load Salable Qty
            try {
                $salableQty = $this->salableQtyProvider->get(
                    (string)$product->getSku(),
                    $this->storeManager->getWebsite()
                );
                if ($salableQty !== null) {
                    $this->setSalableQty($ext, $salableQty);
                } elseif ($stockItem) {
                    $this->setSalableQty($ext, (float)$stockItem->getQty());
                }
            } catch (\Throwable $e) {
                if ($stockItem) {
                    $this->setSalableQty($ext, (float)$stockItem->getQty());
                }
            }

            // 3. Load Review Summary
            try {
                $storeId = (int)($product->getStoreId() ?: $this->storeManager->getStore()->getId());
                $summary = $this->loadReviewSummaries([(int)$productId], $storeId)[(int)$productId] ?? null;
                $reviewSummary = $this->reviewSummaryFactory->create();
                $reviewSummary->setRatingSummary($summary['rating_summary'] ?? 0.0);
                $reviewSummary->setReviewsCount($summary['reviews_count'] ?? 0);
                $this->setReviewSummary($ext, $reviewSummary);
            } catch (\Throwable $e) {
                $this->logger->debug('NexorAIQ ProductRepositoryPlugin review summary failed: ' . $e->getMessage());
            }

            $product->setExtensionAttributes($ext);
        } finally {
            self::$isEnriching = false;
        }
        return $product;
    }

    public function afterGetList(
        ProductRepositoryInterface $subject,
        ProductSearchResultsInterface $searchResults
    ): ProductSearchResultsInterface {
        if (self::$isEnriching) {
            return $searchResults;
        }
        self::$isEnriching = true;

        try {
            $products = $searchResults->getItems();
            if (empty($products)) {
                return $searchResults;
            }

            $productIds = [];
            $skus = [];
            foreach ($products as $product) {
                $productIds[] = (int)$product->getId();
                $skus[] = $product->getSku();
            }

            $storeId = (int)$this->storeManager->getStore()->getId();

            // 1. Batch Load Stock Items via ResourceConnection (direct query to avoid DI adapter instantiation crash)
            $stockItemsMap = [];
            try {
                $res = $this->resourceConnection;
                $conn = $res->getConnection();
                $stockTable = $res->getTableName('cataloginventory_stock_item');
                $select = $conn->select()
                    ->from($stockTable, ['product_id', 'qty', 'is_in_stock'])
                    ->where('product_id IN (?)', $productIds);
                $rows = $conn->fetchAll($select);
                foreach ($rows as $row) {
                    $stockItemsMap[(int)$row['product_id']] = [
                        'qty'         => (float)$row['qty'],
                        'is_in_stock' => (bool)$row['is_in_stock'],
                    ];
                }
            } catch (\Throwable $e) {
                $this->logger->warning('NexorAIQ ProductRepositoryPlugin batch stock query failed: ' . $e->getMessage());
            }

            // 2. Batch Load Review Summaries
            $summariesMap = [];
            try {
                $summariesMap = $this->loadReviewSummaries($productIds, $storeId);
            } catch (\Throwable $e) {
                $this->logger->debug('NexorAIQ ProductRepositoryPlugin review summaries failed: ' . $e->getMessage());
            }

            // 3. Batch Load MSI Salable Qty
            $salableQtyMap = [];
            if ($this->salableQtyProvider->isAvailable()) {
                $website = $this->storeManager->getWebsite();
                foreach ($skus as $sku) {
                    try {
                        $salableQtyMap[$sku] = (float)$this->salableQtyProvider->get((string)$sku, $website);
                    } catch (\Throwable $e) {
                        // Composite products have no salable qty of their own.
                        continue;
                    }
                }
            }

            // 4. Enrich products using preloaded maps
            $itemFactory = $this->stockItemFactory;
            foreach ($products as $product) {
                $productId = (int)$product->getId();
                $sku = $product->getSku();

                $ext = $product->getExtensionAttributes();
                if (!$ext) {
                    $ext = $this->productExtensionFactory->create();
                }

                // Set Stock Item
                $stockData = $stockItemsMap[$productId] ?? null;
                $stockItem = null;
                if ($stockData !== null) {
                    try {
                        $stockItem = $itemFactory->create();
                        $stockItem->setProductId($productId);
                        $stockItem->setQty($stockData['qty']);
                        $stockItem->setIsInStock($stockData['is_in_stock']);
                        $ext->setStockItem($stockItem);
                    } catch (\Throwable $e) {
                    }
                }

                // Fallback to stock registry if batch collection didn't find the item
                if (!$stockItem) {
                    try {
                        $stockItem = $this->stockRegistry->getStockItem($productId, $product->getStoreId());
                        if ($stockItem && $stockItem->getItemId()) {
                            $ext->setStockItem($stockItem);
                        }
                    } catch (\Throwable $e) {
                    }
                }

                // Set Salable Qty
                if (isset($salableQtyMap[$sku])) {
                    $this->setSalableQty($ext, $salableQtyMap[$sku]);
                } else {
                    if ($stockData !== null) {
                        $this->setSalableQty($ext, (float)$stockData['qty']);
                    } elseif ($stockItem) {
                        $this->setSalableQty($ext, (float)$stockItem->getQty());
                    }
                }

                // Set Review Summary
                $summary = $summariesMap[$productId] ?? null;
                $reviewSummary = $this->reviewSummaryFactory->create();
                $reviewSummary->setRatingSummary($summary['rating_summary'] ?? 0.0);
                $reviewSummary->setReviewsCount($summary['reviews_count'] ?? 0);
                $this->setReviewSummary($ext, $reviewSummary);

                $product->setExtensionAttributes($ext);
            }
        } finally {
            self::$isEnriching = false;
        }

        return $searchResults;
    }
}
