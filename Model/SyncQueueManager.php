<?php
declare(strict_types=1);

namespace NexorAIQ\Connector\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Model\CallbackPool;
use Psr\Log\LoggerInterface;

/**
 * Lightweight local queue so checkout-path plugins (inventory reservation, MSI source item
 * save) can record "this product needs a NexoraIQ sync" with a single fast DB write instead of
 * making a blocking outbound HTTP call inline during Place Order / stock save. Cron/IndexQueue
 * drains this table and performs the actual (network) sync out-of-band.
 */
class SyncQueueManager
{
    private const TABLE_NAME = 'nexoraiq_sync_queue';

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly LoggerInterface $logger
    ) {
    }


    /**
     * Enqueue product IDs for async sync. Fast local insert only — no network I/O,
     * safe to call from the checkout/inventory-reservation request path.
     *
     * @param int[] $productIds
     */
    public function enqueueBatch(array $productIds): void
    {
        $productIds = array_unique(array_filter(array_map('intval', $productIds)));
        if (empty($productIds)) {
            return;
        }

        try {
            $connection = $this->resourceConnection->getConnection();
            $inTransaction = $connection->getTransactionLevel() > 0;
        } catch (\Throwable $e) {
            $this->logger->error('NexoraIQ SyncQueueManager enqueueBatch failed: ' . $e->getMessage());
            return;
        }

        if ($inTransaction) {
            // Called inside a DB transaction (product / stock save, order placement): write the
            // queue row only after the transaction is committed, and drop it on rollback.
            // Writing it inside the transaction breaks Magento's performance fixture generator
            // (setup:performance:generate-fixtures), which replays every INSERT made while a
            // template product is saved and requires each table to reference the product table.
            CallbackPool::attach(
                spl_object_hash($connection),
                function () use ($productIds): void {
                    $this->insertQueueRows($productIds);
                }
            );
            return;
        }

        $this->insertQueueRows($productIds);
    }

    /**
     * @param int[] $productIds
     */
    private function insertQueueRows(array $productIds): void
    {
        try {
            $connection = $this->resourceConnection->getConnection();
            $tableName = $this->resourceConnection->getTableName(self::TABLE_NAME);

            $rows = array_map(static fn (int $id) => ['product_id' => $id], $productIds);
            $connection->insertOnDuplicate($tableName, $rows, ['product_id']);
            $this->logger->info(sprintf('NexoraIQ SyncQueueManager: enqueued %d product(s): %s', count($productIds), implode(',', $productIds)));
        } catch (\Throwable $e) {
            $this->logger->error('NexoraIQ SyncQueueManager enqueueBatch failed: ' . $e->getMessage());
        }
    }

    /**
     * Pull up to $limit queued product IDs and remove them from the queue.
     *
     * @return int[]
     */
    public function dequeueBatch(int $limit): array
    {
        try {
            $connection = $this->resourceConnection->getConnection();
            $tableName = $this->resourceConnection->getTableName(self::TABLE_NAME);

            $select = $connection->select()
                ->from($tableName, ['entity_id', 'product_id'])
                ->order('entity_id ASC')
                ->limit($limit);
            $rows = $connection->fetchAll($select);

            if (empty($rows)) {
                return [];
            }

            $entityIds = array_column($rows, 'entity_id');
            $connection->delete($tableName, ['entity_id IN (?)' => $entityIds]);

            $productIds = array_values(array_unique(array_map(static fn ($row) => (int)$row['product_id'], $rows)));
            $this->logger->info(sprintf('NexoraIQ SyncQueueManager: dequeued %d product(s).', count($productIds)));
            return $productIds;
        } catch (\Throwable $e) {
            $this->logger->error('NexoraIQ SyncQueueManager dequeueBatch failed: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Remove specific product IDs from the queue (e.g. after successful real-time webhook sync)
     * so they are not re-processed by the background cron job.
     *
     * @param int[] $productIds
     */
    public function removeFromQueue(array $productIds): void
    {
        $productIds = array_unique(array_filter(array_map('intval', $productIds)));
        if (empty($productIds)) {
            return;
        }

        try {
            $connection = $this->resourceConnection->getConnection();
            $tableName = $this->resourceConnection->getTableName(self::TABLE_NAME);
            $connection->delete($tableName, ['product_id IN (?)' => $productIds]);
            $this->logger->info(sprintf('NexoraIQ SyncQueueManager: removed %d product(s) from queue following real-time sync: %s', count($productIds), implode(',', $productIds)));
        } catch (\Throwable $e) {
            $this->logger->error('NexoraIQ SyncQueueManager removeFromQueue failed: ' . $e->getMessage());
        }
    }
}
