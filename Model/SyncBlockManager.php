<?php
declare(strict_types=1);

namespace NexorAIQ\Connector\Model;

use Magento\Framework\App\ResourceConnection;
use Psr\Log\LoggerInterface;

/**
 * Tracks whether outbound sync to NexoraIQ is currently blocked (e.g. HTTP 402 —
 * subscription expired, product limit reached) so cron/indexer/observers can stop
 * hitting the API on every tick instead of retrying an API call that is guaranteed
 * to fail until the account issue is resolved on the NexoraIQ side. A single row
 * (id=1) holds the latest block state; cleared once Controller\Adminhtml\System\
 * CheckSubscription confirms the API accepts requests again.
 */
class SyncBlockManager
{
    private const TABLE_NAME = 'nexoraiq_sync_block';
    private static bool $tableChecked = false;
    private ?array $cachedState = null;

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly LoggerInterface $logger
    ) {}

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
                    `id` SMALLINT UNSIGNED NOT NULL PRIMARY KEY DEFAULT 1,
                    `is_blocked` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                    `http_code` SMALLINT UNSIGNED NULL,
                    `error_type` VARCHAR(191) NULL,
                    `error_message` TEXT NULL,
                    `blocked_at` DATETIME NULL,
                    `checked_at` DATETIME NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
                $connection->query($sql);
            }
            self::$tableChecked = true;
        } catch (\Throwable $e) {
            $this->logger->error('NexoraIQ SyncBlockManager ensureTableExists failed: ' . $e->getMessage());
        }
    }

    /**
     * Records a blocking API response (e.g. subscription expired, plan limit reached).
     * error_type / error_message are stored verbatim from the API response — never hardcoded —
     * so the admin notice always reflects exactly what NexoraIQ returned.
     */
    public function setBlocked(int $httpCode, string $errorType, string $errorMessage): void
    {
        $this->ensureTableExists();
        $this->cachedState = null;
        try {
            $connection = $this->resourceConnection->getConnection();
            $tableName = $this->resourceConnection->getTableName(self::TABLE_NAME);
            $now = date('Y-m-d H:i:s');

            $connection->insertOnDuplicate($tableName, [
                'id'            => 1,
                'is_blocked'    => 1,
                'http_code'     => $httpCode,
                'error_type'    => $errorType,
                'error_message' => $errorMessage,
                'blocked_at'    => $now,
                'checked_at'    => $now,
            ], ['is_blocked', 'http_code', 'error_type', 'error_message', 'blocked_at', 'checked_at']);

            $this->logger->error(sprintf(
                'NexoraIQ SyncBlockManager: sync BLOCKED — http_code=%d error_type=%s error_message=%s',
                $httpCode,
                $errorType,
                $errorMessage
            ));
        } catch (\Throwable $e) {
            $this->logger->error('NexoraIQ SyncBlockManager setBlocked failed: ' . $e->getMessage());
        }
    }

    /**
     * Clears the block after Controller\Adminhtml\System\CheckSubscription confirms the API
     * accepts requests again.
     */
    public function clearBlock(): void
    {
        $this->ensureTableExists();
        $this->cachedState = null;
        try {
            $connection = $this->resourceConnection->getConnection();
            $tableName = $this->resourceConnection->getTableName(self::TABLE_NAME);

            $connection->insertOnDuplicate($tableName, [
                'id'            => 1,
                'is_blocked'    => 0,
                'http_code'     => null,
                'error_type'    => null,
                'error_message' => null,
                'blocked_at'    => null,
                'checked_at'    => date('Y-m-d H:i:s'),
            ], ['is_blocked', 'http_code', 'error_type', 'error_message', 'blocked_at', 'checked_at']);

            $this->logger->info('NexoraIQ SyncBlockManager: sync RESUMED — block cleared.');
        } catch (\Throwable $e) {
            $this->logger->error('NexoraIQ SyncBlockManager clearBlock failed: ' . $e->getMessage());
        }
    }

    /**
     * Cheap check used by cron/indexer/observers to short-circuit before attempting any
     * outbound call. Cached per-request (static-ish via instance property) since this may be
     * checked many times in a single cron run.
     */
    public function isBlocked(): bool
    {
        return (bool)($this->getState()['is_blocked'] ?? false);
    }

    /**
     * @return array{is_blocked: bool, http_code: ?int, error_type: ?string, error_message: ?string, blocked_at: ?string}|null
     */
    public function getState(): ?array
    {
        if ($this->cachedState !== null) {
            return $this->cachedState;
        }

        $this->ensureTableExists();
        try {
            $connection = $this->resourceConnection->getConnection();
            $tableName = $this->resourceConnection->getTableName(self::TABLE_NAME);
            $row = $connection->fetchRow(
                $connection->select()->from($tableName)->where('id = ?', 1)
            );

            if (!$row) {
                $this->cachedState = ['is_blocked' => false, 'http_code' => null, 'error_type' => null, 'error_message' => null, 'blocked_at' => null];
                return $this->cachedState;
            }

            $this->cachedState = [
                'is_blocked'    => (bool)$row['is_blocked'],
                'http_code'     => $row['http_code'] !== null ? (int)$row['http_code'] : null,
                'error_type'    => $row['error_type'],
                'error_message' => $row['error_message'],
                'blocked_at'    => $row['blocked_at'],
            ];
            return $this->cachedState;
        } catch (\Throwable $e) {
            $this->logger->error('NexoraIQ SyncBlockManager getState failed: ' . $e->getMessage());
            return null;
        }
    }
}
