<?php
declare(strict_types=1);

namespace NexorAIQ\Connector\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

class SyncStatusManager
{
    private const TABLE_NAME = 'nexoraiq_product_sync_status';
    private static bool $tableChecked = false;
    private array $storeIdCache = [];

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface $logger
    ) {}

    /**
     * Ensures table exists so extension works immediately without requiring setup:upgrade
     */
    private function ensureTableExists(): void
    {
        if (self::$tableChecked) {
            return;
        }

        try {
            $connection = $this->resourceConnection->getConnection();
            $tableName = $this->resourceConnection->getTableName(self::TABLE_NAME);

            if (!$connection->isTableExists($tableName)) {
                $sql = "CREATE TABLE IF NOT EXISTS {$tableName} (
                    `entity_id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    `product_id` INT UNSIGNED NOT NULL,
                    `store_id` INT UNSIGNED NOT NULL DEFAULT 0,
                    `store_code` VARCHAR(64) NOT NULL DEFAULT '',
                    `is_synced` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                    `last_synced_at` DATETIME NULL,
                    `indexed_fields` TEXT NULL,
                    UNIQUE KEY `NEXORAIQ_SYNC_PROD_STORE` (`product_id`, `store_id`),
                    KEY `NEXORAIQ_SYNC_PROD_ID` (`product_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
                $connection->query($sql);
            }
            self::$tableChecked = true;
        } catch (\Throwable $e) {
            $this->logger->error('NexoraIQ SyncStatusManager ensureTableExists failed: ' . $e->getMessage());
        }
    }

    /**
     * Resolve numeric store_id from store_code if store_id <= 0.
     * Uses StoreManager with direct DB fallback for maximum reliability in REST contexts.
     */
    private function getStoreId(int $storeId, string $storeCode): int
    {
        if ($storeId > 0) {
            return $storeId;
        }

        if ($storeCode === '') {
            return 0;
        }

        if (!array_key_exists($storeCode, $this->storeIdCache)) {
            $resolvedId = 0;
            try {
                $store = $this->storeManager->getStore($storeCode);
                $id = ($store && $store->getId() !== null) ? (int)$store->getId() : 0;
                if ($id > 0) {
                    $resolvedId = $id;
                }
            } catch (\Throwable $e) {
                // Ignore and fall back to DB lookup
            }

            if ($resolvedId <= 0) {
                try {
                    $connection = $this->resourceConnection->getConnection();
                    $storeTable = $this->resourceConnection->getTableName('store');
                    $select = $connection->select()->from($storeTable, ['store_id'])->where('code = ?', $storeCode);
                    $id = (int)$connection->fetchOne($select);
                    $resolvedId = $id > 0 ? $id : 0;
                } catch (\Throwable $e) {
                    $resolvedId = 0;
                }
            }

            $this->storeIdCache[$storeCode] = $resolvedId;
        }

        return $this->storeIdCache[$storeCode];
    }

    /**
     * Clear all sync statuses for a specific store view (by store_id or store_code).
     * Prevents duplicate/stale records when resyncing an individual store view.
     */
    public function clearSyncStatusForStore(int $storeId = 0, string $storeCode = ''): void
    {
        $this->ensureTableExists();
        try {
            $connection = $this->resourceConnection->getConnection();
            $tableName = $this->resourceConnection->getTableName(self::TABLE_NAME);

            $resolvedStoreId = $this->getStoreId($storeId, $storeCode);

            if ($resolvedStoreId > 0) {
                $connection->delete($tableName, ['store_id = ?' => $resolvedStoreId]);
            } elseif ($storeCode !== '') {
                $connection->delete($tableName, ['store_code = ?' => $storeCode]);
            }
            $this->logger->info(sprintf('NexoraIQ SyncStatusManager: cleared sync status for store_id=%d store_code=%s', $resolvedStoreId, $storeCode));
        } catch (\Throwable $e) {
            $this->logger->error('NexoraIQ SyncStatusManager clearSyncStatusForStore failed: ' . $e->getMessage());
        }
    }

    /**
     * Clear all sync statuses across all store views.
     * Called before a full catalog resync.
     */
    public function clearAllSyncStatuses(): void
    {
        $this->ensureTableExists();
        try {
            $connection = $this->resourceConnection->getConnection();
            $tableName = $this->resourceConnection->getTableName(self::TABLE_NAME);
            $connection->truncateTable($tableName);
            $this->logger->info('NexoraIQ SyncStatusManager: cleared all sync statuses.');
        } catch (\Throwable $e) {
            $this->logger->error('NexoraIQ SyncStatusManager clearAllSyncStatuses failed: ' . $e->getMessage());
        }
    }

    /**
     * Save/update product sync status in database for a given product and store view.
     * Uses insertOnDuplicate to ensure no duplicate (product_id, store_id) rows exist.
     */
    public function saveSyncStatus(
        int $productId,
        int $storeId,
        string $storeCode,
        bool $isSynced,
        array $fields = [],
        ?string $syncedAt = null
    ): void {
        $this->ensureTableExists();
        try {
            $connection = $this->resourceConnection->getConnection();
            $tableName = $this->resourceConnection->getTableName(self::TABLE_NAME);

            $resolvedStoreId = $this->getStoreId($storeId, $storeCode);
            $dateFormatted = $syncedAt ?: date('Y-m-d H:i:s');
            $fieldsString = ($isSynced && !empty($fields)) ? implode(',', $fields) : null;

            $data = [
                'product_id'     => $productId,
                'store_id'       => $resolvedStoreId,
                'store_code'     => $storeCode,
                'is_synced'      => $isSynced ? 1 : 0,
                'last_synced_at' => $dateFormatted,
                'indexed_fields' => $fieldsString,
            ];

            $connection->insertOnDuplicate($tableName, $data, ['store_id', 'is_synced', 'last_synced_at', 'indexed_fields', 'store_code']);
            $this->logger->debug(sprintf(
                'NexoraIQ SyncStatusManager: saved status product_id=%d store_id=%d synced=%s',
                $productId,
                $resolvedStoreId,
                $isSynced ? 'true' : 'false'
            ));
        } catch (\Throwable $e) {
            $this->logger->error(sprintf('NexoraIQ failed saving sync status for product %d, store %s: %s', $productId, $storeCode, $e->getMessage()));
        }
    }

    /**
     * Save/update sync statuses for a batch of products across store views.
     * Uses insertOnDuplicate to guarantee NO duplicate rows exist per (product_id, store_id).
     */
    public function saveBatchSyncStatus(array $items): void
    {
        if (empty($items)) {
            return;
        }

        $this->ensureTableExists();
        try {
            $connection = $this->resourceConnection->getConnection();
            $tableName = $this->resourceConnection->getTableName(self::TABLE_NAME);

            $rows = [];
            $now = date('Y-m-d H:i:s');

            foreach ($items as $item) {
                $productId = (int)($item['product_id'] ?? $item['entity_id'] ?? 0);
                $storeCode = (string)($item['store_code'] ?? $item['store_view_code'] ?? '');
                $storeId   = $this->getStoreId((int)($item['store_id'] ?? 0), $storeCode);

                // Parse is_synced accurately for booleans, strings ('true'/'false'), numbers
                if (array_key_exists('is_synced', $item) && $item['is_synced'] !== null) {
                    $val = $item['is_synced'];
                    if (is_bool($val)) {
                        $isSynced = $val;
                    } elseif (is_string($val)) {
                        $isSynced = in_array(strtolower($val), ['true', '1', 'yes'], true);
                    } else {
                        $isSynced = (int)$val === 1;
                    }
                } else {
                    $isSynced = true;
                    if (isset($item['status']) && in_array($item['status'], [2, '2', 'disabled', 'deleted'], true)) {
                        $isSynced = false;
                    }
                }

                $fields   = ($isSynced && is_array($item['fields'] ?? null)) ? $item['fields'] : [];
                $syncedAt = !empty($item['last_synced_at']) ? $item['last_synced_at'] : $now;

                if ($productId <= 0) {
                    continue;
                }

                $fieldsString = ($isSynced && !empty($fields)) ? implode(',', $fields) : null;

                $rows[] = [
                    'product_id'     => $productId,
                    'store_id'       => $storeId,
                    'store_code'     => $storeCode,
                    'is_synced'      => $isSynced ? 1 : 0,
                    'last_synced_at' => $syncedAt,
                    'indexed_fields' => $fieldsString,
                ];
            }

            if (!empty($rows)) {
                $chunks = array_chunk($rows, 250);
                foreach ($chunks as $chunk) {
                    $connection->insertOnDuplicate(
                        $tableName,
                        $chunk,
                        ['store_id', 'is_synced', 'last_synced_at', 'indexed_fields', 'store_code']
                    );
                }
            }
        } catch (\Throwable $e) {
            $this->logger->error('NexoraIQ SyncStatusManager saveBatchSyncStatus failed: ' . $e->getMessage());
        }
    }

    /**
     * Get stored sync statuses for a product across all store views directly from Database
     */
    public function getSyncStatusesFromDb(int $productId): array
    {
        $this->ensureTableExists();
        try {
            $connection = $this->resourceConnection->getConnection();
            $tableName = $this->resourceConnection->getTableName(self::TABLE_NAME);

            $select = $connection->select()
                ->from($tableName)
                ->where('product_id = ?', $productId);

            $rows = $connection->fetchAll($select);
            $results = [];
            foreach ($rows as $row) {
                $fieldsList = !empty($row['indexed_fields']) ? explode(',', $row['indexed_fields']) : [];
                $formattedDate = !empty($row['last_synced_at']) ? date('d M Y, h:i:s A', strtotime($row['last_synced_at'])) : null;
                $results[$row['store_code']] = [
                    'is_synced'      => (bool)$row['is_synced'],
                    'last_synced_at' => $formattedDate,
                    'fields'         => $fieldsList,
                ];
            }
            return $results;
        } catch (\Throwable $e) {
            $this->logger->error('NexoraIQ getSyncStatusesFromDb failed: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Check if a product was successfully synced for a store view within the last $withinSeconds seconds.
     */
    public function isRecentlySynced(int $productId, int $storeId, int $withinSeconds = 300): bool
    {
        $this->ensureTableExists();
        try {
            $connection = $this->resourceConnection->getConnection();
            $tableName = $this->resourceConnection->getTableName(self::TABLE_NAME);

            $cutoff = date('Y-m-d H:i:s', time() - $withinSeconds);
            $select = $connection->select()
                ->from($tableName, ['entity_id'])
                ->where('product_id = ?', $productId)
                ->where('store_id = ?', $storeId)
                ->where('is_synced = 1')
                ->where('last_synced_at >= ?', $cutoff);

            $row = $connection->fetchOne($select);
            return !empty($row);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Batched equivalent of isRecentlySynced() for a whole store view, used by a full reindex
     * to avoid one query per product. Returns the set of product IDs (from $productIds) that
     * were successfully synced for $storeId within the last $withinSeconds seconds.
     *
     * @param int[] $productIds
     * @return int[]
     */
    public function getRecentlySyncedProductIds(array $productIds, int $storeId, int $withinSeconds): array
    {
        if (empty($productIds) || $withinSeconds <= 0) {
            return [];
        }

        $this->ensureTableExists();
        try {
            $connection = $this->resourceConnection->getConnection();
            $tableName = $this->resourceConnection->getTableName(self::TABLE_NAME);

            $cutoff = date('Y-m-d H:i:s', time() - $withinSeconds);
            $select = $connection->select()
                ->from($tableName, ['product_id'])
                ->where('product_id IN (?)', $productIds)
                ->where('store_id = ?', $storeId)
                ->where('is_synced = 1')
                ->where('last_synced_at >= ?', $cutoff);

            return array_map('intval', $connection->fetchCol($select));
        } catch (\Throwable $e) {
            $this->logger->error('NexoraIQ getRecentlySyncedProductIds failed: ' . $e->getMessage());
            return [];
        }
    }
}
