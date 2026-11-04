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

            // created_at is refreshed when the product is already queued, so it always holds the
            // time of the latest change (see CatalogAction's "synced after queued" skip rule).
            $now = gmdate('Y-m-d H:i:s');
            foreach (array_chunk($productIds, 1000) as $chunk) {
                $rows = array_map(static fn (int $id) => ['product_id' => $id, 'created_at' => $now], $chunk);
                $connection->insertOnDuplicate($tableName, $rows, ['created_at']);
            }
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
        return array_keys($this->dequeueBatchWithQueuedAt($limit));
    }

    /**
     * Like dequeueBatch(), keyed by product ID with the UTC time it was (last) queued.
     *
     * @return array<int, string>
     */
    public function dequeueBatchWithQueuedAt(int $limit): array
    {
        try {
            $connection = $this->resourceConnection->getConnection();
            $tableName = $this->resourceConnection->getTableName(self::TABLE_NAME);

            $select = $connection->select()
                ->from($tableName, ['entity_id', 'product_id', 'created_at'])
                ->order('entity_id ASC')
                ->limit($limit);
            $rows = $connection->fetchAll($select);

            if (empty($rows)) {
                return [];
            }

            $entityIds = array_column($rows, 'entity_id');
            $connection->delete($tableName, ['entity_id IN (?)' => $entityIds]);

            $queuedAt = [];
            foreach ($rows as $row) {
                $queuedAt[(int)$row['product_id']] = (string)$row['created_at'];
            }
            $this->logger->info(sprintf('NexoraIQ SyncQueueManager: dequeued %d product(s).', count($queuedAt)));
            return $queuedAt;
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
