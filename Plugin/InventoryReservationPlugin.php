<?php

/**
 * Copyright © 2025 NexorAIQ. All rights reserved.
 */

declare(strict_types=1);

namespace NexorAIQ\Connector\Plugin;

use Magento\InventorySales\Model\PlaceReservationsForSalesEvent;
use Magento\InventorySalesApi\Api\Data\SalesEventInterface;
use Magento\InventorySalesApi\Api\Data\SalesChannelInterface;
use Magento\ConfigurableProduct\Model\ResourceModel\Product\Type\Configurable;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use NexorAIQ\Connector\Helper\Data as HelperData;
use NexorAIQ\Connector\Model\SyncQueueManager;
use Psr\Log\LoggerInterface;

class InventoryReservationPlugin
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
     * Trigger NexoraIQ sync when MSI salable qty changes (inventory reservation)
     *
     * @param PlaceReservationsForSalesEvent $subject
     * @param mixed $result
     * @param array $items
     * @param SalesChannelInterface $salesChannel
     * @param SalesEventInterface $salesEvent
     * @return mixed
     */
    public function afterExecute(
        PlaceReservationsForSalesEvent $subject,
        $result,
        array $items,
        SalesChannelInterface $salesChannel,
        SalesEventInterface $salesEvent
    ) {
        try {
            if (!$this->helper->isEnabled() || !$this->helper->isRealtimeEnabled()) {
                return $result;
            }

            $allowedEvents = [
                'order_placed',
                'order_canceled',
                'creditmemo_created',
                'shipment_created'
            ];

            if (!in_array($salesEvent->getType(), $allowedEvents)) {
                return $result;
            }

            if (empty($items)) {
                return $result;
            }

            // Collect SKUs
            $skus = [];
            foreach ($items as $item) {
                if (method_exists($item, 'getSku') && $item->getSku()) {
                    $skus[] = $item->getSku();
                }
            }

            if (empty($skus)) {
                return $result;
            }

            $skus = array_unique($skus);

            // Find product IDs
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
                $parentProductIds = $this->configurable->getParentIdsByChild($productId);
                if (!empty($parentProductIds)) {
                    $productIdsToIndex = array_merge($productIdsToIndex, $parentProductIds);
                }

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
            // here, so Place Order / stock-reservation is never blocked on the NexoraIQ API.
            $this->syncQueueManager->enqueueBatch($productIdsToIndex);
        } catch (\Throwable $e) {
            $this->logger->error('NexoraIQ MSI Inventory Reservation Sync Error: ' . $e->getMessage());
        }

        return $result;
    }
}
