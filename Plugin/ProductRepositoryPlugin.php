<?php
declare(strict_types=1);

namespace NexorAIQ\Connector\Plugin;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Api\Data\ProductSearchResultsInterface;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Store\Model\StoreManagerInterface;
use NexorAIQ\Connector\Api\Data\ReviewSummaryInterfaceFactory;
use Psr\Log\LoggerInterface;

class ProductRepositoryPlugin
{
    private static bool $isEnriching = false;

    public function __construct(
        private readonly StockRegistryInterface $stockRegistry,
        private readonly StoreManagerInterface $storeManager,
        private readonly ReviewSummaryInterfaceFactory $reviewSummaryFactory,
        private readonly \Magento\Framework\ObjectManagerInterface $objectManager,
        private readonly LoggerInterface $logger,
        private readonly ?\Magento\Framework\App\ResourceConnection $resourceConnection = null,
        private readonly ?\Magento\CatalogInventory\Api\Data\StockItemInterfaceFactory $stockItemFactory = null
    ) {}

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
                $extFactory = $this->objectManager->get(\Magento\Catalog\Api\Data\ProductExtensionFactory::class);
                $ext = $extFactory->create();
            }

            // 1. Load Stock Item
            $stockItem = null;
            try {
                $stockItem = $this->stockRegistry->getStockItem($productId, $product->getStoreId());
                if ($stockItem && $stockItem->getItemId()) {
                    $ext->setStockItem($stockItem);
                }
            } catch (\Throwable $e) {}

            // 2. Load Salable Qty
            try {
                if (interface_exists('Magento\InventorySalesApi\Api\GetProductSalableQtyInterface')) {
                    $getSalableQty = $this->objectManager->get('Magento\InventorySalesApi\Api\GetProductSalableQtyInterface');
                    $stockResolver = $this->objectManager->get('Magento\InventorySalesApi\Api\StockResolverInterface');
                    $websiteCode = $this->storeManager->getWebsite()->getCode();
                    $stock = $stockResolver->execute(\Magento\InventorySalesApi\Api\Data\SalesChannelInterface::TYPE_WEBSITE, $websiteCode);
                    $salableQty = $getSalableQty->execute($product->getSku(), $stock->getStockId());
                    $this->setSalableQty($ext, (float)$salableQty);
                } else {
                    if ($stockItem) {
                        $this->setSalableQty($ext, (float)$stockItem->getQty());
                    }
                }
            } catch (\Throwable $e) {
                if ($stockItem) {
                    $this->setSalableQty($ext, (float)$stockItem->getQty());
                }
            }

            // 3. Load Review Summary
            try {
                $storeId = $product->getStoreId() ?: $this->storeManager->getStore()->getId();
                $reviewSummary = $this->reviewSummaryFactory->create();
                
                $summaryCollection = $this->objectManager->create(\Magento\Review\Model\ResourceModel\Review\Summary\Collection::class);
                $summaryCollection->addFieldToFilter('entity_pk_value', $productId);
                $summaryCollection->addFieldToFilter('store_id', $storeId);
                $summary = $summaryCollection->getFirstItem();
                
                $ratingValue = $summary ? $summary->getRatingSummary() : 0;
                $reviewsCount = $summary ? $summary->getReviewsCount() : 0;

                $reviewSummary->setRatingSummary((float)$ratingValue);
                $reviewSummary->setReviewsCount((int)$reviewsCount);
                $this->setReviewSummary($ext, $reviewSummary);
            } catch (\Throwable $e) {}

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
                $productIds[] = $product->getId();
                $skus[] = $product->getSku();
            }

            $storeId = (int)$this->storeManager->getStore()->getId();

            // 1. Batch Load Stock Items via ResourceConnection (direct query to avoid DI adapter instantiation crash)
            $stockItemsMap = [];
            try {
                $res = $this->resourceConnection ?? $this->objectManager->get(\Magento\Framework\App\ResourceConnection::class);
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
                $summaryCollection = $this->objectManager->create(\Magento\Review\Model\ResourceModel\Review\Summary\Collection::class);
                $summaryCollection->addFieldToFilter('entity_pk_value', ['in' => $productIds]);
                $summaryCollection->addFieldToFilter('store_id', $storeId);
                foreach ($summaryCollection as $summary) {
                    $summariesMap[$summary->getEntityPkValue()] = $summary;
                }
            } catch (\Throwable $e) {
            }

            // 3. Batch Load MSI Salable Qty
            $salableQtyMap = [];
            try {
                if (interface_exists('Magento\InventorySalesApi\Api\GetProductSalableQtyInterface')) {
                    $getSalableQty = $this->objectManager->get('Magento\InventorySalesApi\Api\GetProductSalableQtyInterface');
                    $stockResolver = $this->objectManager->get('Magento\InventorySalesApi\Api\StockResolverInterface');
                    $websiteCode = $this->storeManager->getWebsite()->getCode();
                    $stock = $stockResolver->execute(\Magento\InventorySalesApi\Api\Data\SalesChannelInterface::TYPE_WEBSITE, $websiteCode);
                    $stockId = $stock->getStockId();
                    
                    foreach ($skus as $sku) {
                        try {
                            $salableQtyMap[$sku] = (float)$getSalableQty->execute($sku, $stockId);
                        } catch (\Throwable $e) {
                        }
                    }
                }
            } catch (\Throwable $e) {
            }

            // 4. Enrich products using preloaded maps
            $itemFactory = $this->stockItemFactory ?? $this->objectManager->get(\Magento\CatalogInventory\Api\Data\StockItemInterfaceFactory::class);
            foreach ($products as $product) {
                $productId = (int)$product->getId();
                $sku = $product->getSku();

                $ext = $product->getExtensionAttributes();
                if (!$ext) {
                    $extFactory = $this->objectManager->get(\Magento\Catalog\Api\Data\ProductExtensionFactory::class);
                    $ext = $extFactory->create();
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
                $ratingValue = $summary ? $summary->getRatingSummary() : 0;
                $reviewsCount = $summary ? $summary->getReviewsCount() : 0;

                $reviewSummary = $this->reviewSummaryFactory->create();
                $reviewSummary->setRatingSummary((float)$ratingValue);
                $reviewSummary->setReviewsCount((int)$reviewsCount);
                $this->setReviewSummary($ext, $reviewSummary);

                $product->setExtensionAttributes($ext);
            }
        } finally {
            self::$isEnriching = false;
        }

        return $searchResults;
    }
}
