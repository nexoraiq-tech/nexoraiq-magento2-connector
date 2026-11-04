<?php
/**
 * Copyright © 2026 NexorAIQ. All rights reserved.
 */
declare(strict_types=1);

namespace NexorAIQ\Connector\Plugin;

use Magento\Catalog\Model\Product\Action;
use NexorAIQ\Connector\Helper\Data as HelperData;
use NexorAIQ\Connector\Model\SyncQueueManager;
use Psr\Log\LoggerInterface;

/**
 * Product grid mass actions (Change Status, Update Attributes, website assignment) write the
 * attribute tables directly through Product\Action and fire no catalog_product_save_after, so
 * ProductSaveAfter never sees them. Queue the affected products here; Cron\IndexQueue syncs
 * them (a disabled product is sent as status "disabled" per store view).
 */
class ProductActionPlugin
{
    public function __construct(
        private readonly HelperData $helper,
        private readonly SyncQueueManager $syncQueueManager,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param Action $subject
     * @param Action $result
     * @param array $productIds
     * @param array $attrData
     * @param int $storeId
     * @return Action
     */
    public function afterUpdateAttributes(Action $subject, $result, $productIds, $attrData, $storeId)
    {
        $this->enqueue((array)$productIds, 'updateAttributes(' . implode(',', array_keys((array)$attrData)) . ')');
        return $result;
    }

    /**
     * @param Action $subject
     * @param mixed $result
     * @param array $productIds
     * @param array $websiteIds
     * @param string $type
     * @return mixed
     */
    public function afterUpdateWebsites(Action $subject, $result, $productIds, $websiteIds, $type)
    {
        $this->enqueue((array)$productIds, 'updateWebsites(' . $type . ')');
        return $result;
    }

    /**
     * @param array $productIds
     * @param string $source
     */
    private function enqueue(array $productIds, string $source): void
    {
        try {
            if (!$productIds || !$this->helper->isEnabled() || !$this->helper->isRealtimeEnabled()) {
                return;
            }
            $this->logger->info(sprintf('Product mass action %s: %d product(s) enqueued', $source, count($productIds)));
            $this->syncQueueManager->enqueueBatch($productIds);
        } catch (\Throwable $e) {
            // Never break the admin mass action because of the sync queue.
            $this->logger->error('NexoraIQ ProductActionPlugin failed: ' . $e->getMessage());
        }
    }
}
