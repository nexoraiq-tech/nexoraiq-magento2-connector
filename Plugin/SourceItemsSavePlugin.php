<?php

/**
 * Copyright © 2025 NexorAIQ. All rights reserved.
 */

declare(strict_types=1);

namespace NexorAIQ\Connector\Plugin;

use Magento\InventoryApi\Api\SourceItemsSaveInterface;
use Magento\InventoryApi\Api\Data\SourceItemInterface;
use Magento\ConfigurableProduct\Model\ResourceModel\Product\Type\Configurable;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use NexorAIQ\Connector\Helper\Data as HelperData;
use NexorAIQ\Connector\Model\SyncQueueManager;
use Psr\Log\LoggerInterface;

class SourceItemsSavePlugin
{
    /**
     * @param HelperData $helper
     * @param SyncQueueManager $syncQueueManager
     * @param Configurable $configurable
     * @param ProductCollectionFactory $productCollectionFactory
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly HelperData $helper,
        private readonly SyncQueueManager $syncQueueManager,
        private readonly Configurable $configurable,
        private readonly ProductCollectionFactory $productCollectionFactory,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Plugin after MSI source items save
     *
     * @param SourceItemsSaveInterface $subject
     * @param mixed $result
     * @param array $sourceItems
     * @return mixed
     */
    public function afterExecute(
        SourceItemsSaveInterface $subject,
        $result,
        array $sourceItems
    ) {
        try {
            if (!$this->helper->isEnabled() || !$this->helper->isRealtimeEnabled()) {
                return $result;
            }

            // Extract SKUs
            $skus = [];
            foreach ($sourceItems as $item) {
                if ($item instanceof SourceItemInterface && $item->getSku()) {
                    $skus[] = $item->getSku();
                }
            }

            if (empty($skus)) {
                return $result;
            }

            // Find product IDs for these SKUs
            $productCollection = $this->productCollectionFactory->create();
            $productCollection->addAttributeToFilter('sku', ['in' => $skus]);
            $productCollection->addAttributeToSelect('entity_id');
            
            $productIds = [];
            foreach ($productCollection as $product) {
                $productIds[] = (int)$product->getId();
            }

            if (empty($productIds)) {
                return $result;
            }

            $productIdsToIndex = $productIds;

            // Include configurable parents and children
            foreach ($productIds as $productId) {
                // Find parent configurable product IDs
                $parentProductIds = $this->configurable->getParentIdsByChild($productId);
                if (!empty($parentProductIds)) {
                    $productIdsToIndex = array_merge($productIdsToIndex, $parentProductIds);
                }

                // If this is a parent configurable itself, also sync child configurations
                $childProductIds = $this->configurable->getChildrenIds($productId);
                if (!empty($childProductIds)) {
                    foreach ($childProductIds as $childGroup) {
                        if (is_array($childGroup)) {
                            $productIdsToIndex = array_merge($productIdsToIndex, $childGroup);
                        }
                    }
                }
            }

            $productIdsToIndex = array_unique(array_map('intval', $productIdsToIndex));

            // Queue resolved products for async sync (Cron\IndexQueue drains this table
            // and performs the actual outbound HTTP sync). Only a local DB write happens
            // here, so the MSI source-item save is never blocked on the NexoraIQ API.
            $this->syncQueueManager->enqueueBatch($productIdsToIndex);
        } catch (\Throwable $e) {
            $this->logger->error('NexoraIQ MSI Source Items Sync Error: ' . $e->getMessage());
        }

        return $result;
    }
}
