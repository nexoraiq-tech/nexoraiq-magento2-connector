<?php
/**
 * Copyright © 2025 NexorAIQ. All rights reserved.
 */
declare(strict_types=1);

namespace NexorAIQ\Connector\Cron;

use NexorAIQ\Connector\Helper\Data as HelperData;
use NexorAIQ\Connector\Model\Indexer\CatalogAction;
use NexorAIQ\Connector\Model\SyncQueueManager;
use NexorAIQ\Connector\Model\SyncBlockManager;
use Psr\Log\LoggerInterface;

/**
 * Drains the local queue populated by InventoryReservationPlugin / SourceItemsSavePlugin
 * (checkout-path plugins that only do a fast local DB write) and performs the actual
 * outbound NexoraIQ sync here, off the request path.
 */
class IndexQueue
{
    private const DEQUEUE_LIMIT = 500;

    public function __construct(
        private readonly SyncQueueManager $syncQueueManager,
        private readonly CatalogAction $catalogAction,
        private readonly HelperData $helper,
        private readonly SyncBlockManager $syncBlockManager,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        if (!$this->helper->isEnabled() || !$this->helper->isRealtimeEnabled()) {
            $this->logger->debug('NexoraIQ IndexQueue: skipped, extension disabled or realtime sync off.');
            return;
        }

        // Sync is blocked at the account/plan level (subscription expired, index limit
        // reached, etc.) — stop hitting the API every minute with a call that's guaranteed to
        // fail the same way. Leave the queue untouched; entries just accumulate harmlessly
        // (fast local DB writes only) until Check Subscription in General Settings confirms
        // the API accepts requests again and resumes processing.
        if ($this->syncBlockManager->isBlocked()) {
            $this->logger->debug('NexoraIQ IndexQueue: skipped, sync is currently blocked. Use Check Subscription in General Settings once resolved.');
            return;
        }

        $productIds = $this->syncQueueManager->dequeueBatch(self::DEQUEUE_LIMIT);
        if (empty($productIds)) {
            $this->logger->debug('NexoraIQ IndexQueue: queue empty, nothing to sync.');
            return;
        }

        $this->logger->info(sprintf('NexoraIQ IndexQueue: syncing %d queued product(s).', count($productIds)));
        $failedProductIds = $this->catalogAction->execute($productIds);

        if (!empty($failedProductIds)) {
            $this->logger->warning(
                sprintf('NexoraIQ IndexQueue: %d product(s) failed to sync, re-queuing for next run.', count($failedProductIds))
            );
            // Re-queue rather than drop, so a transient failure (API down, etc.) is retried
            // on the next cron tick instead of being silently lost.
            $this->syncQueueManager->enqueueBatch($failedProductIds);
        }
    }
}
