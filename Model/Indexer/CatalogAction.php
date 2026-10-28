<?php
/**
 * Copyright © 2025 NexorAIQ. All rights reserved.
 */
declare(strict_types=1);

namespace NexorAIQ\Connector\Model\Indexer;

use NexorAIQ\Connector\Helper\Data as HelperData;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product\Attribute\Source\Status as ProductStatus;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

use NexorAIQ\Connector\Model\SyncStatusManager;
use NexorAIQ\Connector\Model\SyncQueueManager;
use NexorAIQ\Connector\Model\SyncBlockManager;

class CatalogAction
{
    // Magento status: 1 = Enabled, 2 = Disabled
    private const STATUS_ENABLED  = '1';
    private const STATUS_DISABLED = '2';

    public function __construct(
        private readonly HelperData $helper,
        private readonly CollectionFactory $collectionFactory,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly StoreManagerInterface $storeManager,
        private readonly SyncStatusManager $syncStatusManager,
        private readonly SyncQueueManager $syncQueueManager,
        private readonly SyncBlockManager $syncBlockManager,
        private readonly LoggerInterface $logger
    ) {}

    /**
     * Reindex specific product IDs (from mview changelog or direct list).
     * Handles both enable→sync and disable→remove-from-NexorAIQ scenarios.
     *
     * @param int[] $ids
     * @param bool $force Force sync without skipping recently synced products (used by manual UI sync/reindex)
     * @return int[] Product IDs that failed to sync for at least one store view — callers
     *               (e.g. Cron\IndexQueue) should re-queue these so a transient failure
     *               (API down, mapping error) is retried instead of silently dropped.
     */
    public function execute(array $ids, bool $force = false): array
    {
        if (!$this->helper->isEnabled()) {
            return [];
        }

        // Sync is blocked at the account/plan level (e.g. subscription expired, index limit
        // reached) — don't attempt another outbound call, it's guaranteed to fail the same way
        // until Check Subscription confirms the account is fixed. Return the IDs as "failed" so
        // the caller (Cron\IndexQueue) re-queues them rather than dropping them, so they're
        // picked up automatically once sync resumes.
        if ($this->syncBlockManager->isBlocked()) {
            return $ids;
        }

        $failedProductIds = [];

        // Suppress E_DEPRECATED warnings so Magento's ErrorHandler does not convert
        // Zend Statement deprecation warnings into exceptions and crash mapping.
        $oldLevel = error_reporting();
        error_reporting($oldLevel & ~E_DEPRECATED & ~E_USER_DEPRECATED);

        try {
            $activeStoreViews = $this->helper->getActiveStoreViews();
            $storeViews = $this->storeManager->getStores();
            foreach ($storeViews as $store) {
                $storeId = (int)$store->getId();
                if (!$store->isActive()) {
                    continue;
                }
                $storeCode = $store->getCode();
                if ($activeStoreViews !== null && !in_array($storeCode, $activeStoreViews)) {
                    continue;
                }

                $this->logger->info(
                    sprintf('NexorAIQ Indexer: Processing %d product updates for store view %s', count($ids), $storeCode)
                );

                $batchSize = $this->helper->getBatchSize($storeId);

                // Chunk IDs to load and sync in small batches
                $chunks = array_chunk($ids, $batchSize);
                foreach ($chunks as $chunkIds) {
                    $payloads = [];
                    foreach ($chunkIds as $productId) {
                        try {
                            // Deduplication: If real-time webhook already synced this product with full stock within last 5 mins, skip duplicate bulk HTTP call
                            if (!$force && $this->syncStatusManager->isRecentlySynced($productId, $storeId, 300, true)) {
                                $this->logger->info(sprintf('NexorAIQ Indexer: Product ID %d recently synced via real-time for store %s, skipping bulk sync.', $productId, $storeCode));
                                continue;
                            }

                            $product = $this->productRepository->getById($productId, false, $storeId);
                            $productStatus = (string)$product->getStatus();

                            if ($productStatus === self::STATUS_DISABLED) {
                                // Product was disabled in Magento — remove it from NexorAIQ search index
                                $payloads[] = [
                                    'entity_id'       => $productId,
                                    'status'          => 'disabled',
                                    'store_view_code' => $storeCode,
                                    'store_id'        => $storeId,
                                ];
                            } else {
                                // Product is enabled — sync full payload
                                $payloads[] = $this->helper->buildProductPayload($product, $store);
                            }
                        } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
                            // Product physically deleted from Magento
                            $payloads[] = [
                                'entity_id'       => $productId,
                                'status'          => 'deleted',
                                'store_view_code' => $storeCode,
                                'store_id'        => $storeId,
                            ];
                        } catch (\Throwable $e) {
                            $this->logger->error(
                                sprintf('NexorAIQ Indexer failed mapping product ID %d for store %s: %s', $productId, $storeCode, $e->getMessage())
                            );
                            $failedProductIds[] = $productId;
                        }
                    }

                    if (!empty($payloads)) {
                        $sent = $this->helper->sendBulkPayload($payloads, $storeCode);
                        if (!$sent) {
                            foreach (array_column($payloads, 'entity_id') as $failedId) {
                                $failedProductIds[] = (int)$failedId;
                            }
                        }
                    }
                }
            }
        } finally {
            error_reporting($oldLevel);
        }

        return array_values(array_unique($failedProductIds));
    }

    /**
     * Reindex all catalog products (Full sync).
     *
     * Enqueues every product ID into the async sync queue instead of sending outbound HTTP
     * calls inline. The previous implementation looped store-view × batch and made a blocking
     * curl_exec() (up to 30s each) per chunk sequentially — with ~14 store views and a ~21k
     * product catalog that's hundreds of sequential blocking calls, easily hours, with no
     * resumability if interrupted. Cron\IndexQueue already drains this queue in bounded
     * batches every minute, so a full reindex now completes safely off the request/CLI path
     * the same way real-time syncs do.
     *
     * Products already synced successfully within the configurable "full reindex skip window"
     * are excluded from the enqueue (see getRecentlySyncedProductIds()) so a full reindex only
     * re-queues what actually needs it, instead of blindly resending the whole catalog every
     * time. The queue itself is store-agnostic (NexorAIQ_sync_queue keys on product_id only —
     * a dequeued product syncs to all active stores), so a product is only skipped if it was
     * recently synced in EVERY active store; otherwise it's queued to cover the stale store(s).
     */
    public function executeFull(): void
    {
        if (!$this->helper->isEnabled()) {
            return;
        }

        // Same reasoning as execute(): while blocked at the account/plan level, don't bother
        // enqueueing — Controller\Adminhtml\System\CheckSubscription calls executeFull()
        // explicitly once the block is confirmed cleared, so this will run again then.
        if ($this->syncBlockManager->isBlocked()) {
            $this->logger->info('NexorAIQ Indexer: full reindex skipped — sync is currently blocked (see Check Subscription in General Settings).');
            return;
        }

        $skipWindowSeconds = $this->helper->getFullReindexSkipWindowMinutes() * 60;

        try {
            $activeStoreViews = $this->helper->getActiveStoreViews();
            $storeViews = $this->storeManager->getStores();
            $staleProductIds = [];
            $totalCandidates = 0;
            $totalSkipped = 0;

            foreach ($storeViews as $store) {
                $storeId = (int)$store->getId();
                if (!$store->isActive()) {
                    continue;
                }
                $storeCode = $store->getCode();
                if ($activeStoreViews !== null && !in_array($storeCode, $activeStoreViews)) {
                    continue;
                }

                $collection = $this->collectionFactory->create();
                $collection->setStoreId($storeId);
                $collection->addWebsiteFilter((int)$store->getWebsiteId());
                $collection->addAttributeToSelect('entity_id');
                $storeProductIds = array_map('intval', $collection->getAllIds());
                $totalCandidates += count($storeProductIds);

                $recentlySyncedIds = [];
                if ($skipWindowSeconds > 0) {
                    foreach (array_chunk($storeProductIds, 5000) as $idChunk) {
                        $recentlySyncedIds = array_merge(
                            $recentlySyncedIds,
                            $this->syncStatusManager->getRecentlySyncedProductIds($idChunk, $storeId, $skipWindowSeconds, true)
                        );
                    }
                }

                $staleForStore = array_diff($storeProductIds, $recentlySyncedIds);
                $totalSkipped += count($storeProductIds) - count($staleForStore);

                $this->logger->info(
                    sprintf(
                        'NexorAIQ Indexer: Store %s — %d candidate(s), %d already synced within %d min (skipped), %d need resync',
                        $storeCode,
                        count($storeProductIds),
                        count($storeProductIds) - count($staleForStore),
                        (int)($skipWindowSeconds / 60),
                        count($staleForStore)
                    )
                );

                $staleProductIds = array_merge($staleProductIds, $staleForStore);
            }

            $staleProductIds = array_values(array_unique(array_map('intval', $staleProductIds)));
            if (!empty($staleProductIds)) {
                foreach (array_chunk($staleProductIds, 1000) as $chunk) {
                    $this->syncQueueManager->enqueueBatch($chunk);
                }
            }

            $this->logger->info(
                sprintf(
                    'NexorAIQ Indexer: Full reindex — %d candidate(s) total, %d skipped (recently synced), %d enqueued for async sync.',
                    $totalCandidates,
                    $totalSkipped,
                    count($staleProductIds)
                )
            );
        } catch (\Throwable $e) {
            $this->logger->error('NexorAIQ Indexer full reindex enqueue failed: ' . $e->getMessage());
        }
    }
}
