<?php
declare(strict_types=1);

namespace NexorAIQ\Connector\Api\Data;

interface SyncStatusItemInterface
{
    /**
     * @return int
     */
    public function getProductId(): int;

    /**
     * @param int $productId
     * @return void
     */
    public function setProductId(int $productId): void;

    /**
     * @return int
     */
    public function getStoreId(): int;

    /**
     * @param int $storeId
     * @return void
     */
    public function setStoreId(int $storeId): void;

    /**
     * @return string
     */
    public function getStoreCode(): string;

    /**
     * @param string $storeCode
     * @return void
     */
    public function setStoreCode(string $storeCode): void;

    /**
     * @return bool
     */
    public function getIsSynced(): bool;

    /**
     * @param bool $isSynced
     * @return void
     */
    public function setIsSynced(bool $isSynced): void;

    /**
     * @return string
     */
    public function getLastSyncedAt(): string;

    /**
     * @param string $lastSyncedAt
     * @return void
     */
    public function setLastSyncedAt(string $lastSyncedAt): void;

    /**
     * @return string[]|null
     */
    public function getFields(): ?array;

    /**
     * @param string[]|null $fields
     * @return void
     */
    public function setFields(?array $fields = null): void;
}
