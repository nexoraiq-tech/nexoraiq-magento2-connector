<?php
declare(strict_types=1);

namespace NexorAIQ\Connector\Model\Data;

use NexorAIQ\Connector\Api\Data\SyncStatusItemInterface;
use Magento\Framework\DataObject;

class SyncStatusItem extends DataObject implements SyncStatusItemInterface
{
    public function getProductId(): int
    {
        return (int) $this->getData('product_id');
    }

    public function setProductId(int $productId): void
    {
        $this->setData('product_id', $productId);
    }

    public function getStoreId(): int
    {
        return (int) $this->getData('store_id');
    }

    public function setStoreId(int $storeId): void
    {
        $this->setData('store_id', $storeId);
    }

    public function getStoreCode(): string
    {
        return (string) $this->getData('store_code');
    }

    public function setStoreCode(string $storeCode): void
    {
        $this->setData('store_code', $storeCode);
    }

    public function getIsSynced(): bool
    {
        $val = $this->getData('is_synced');
        if (is_bool($val)) {
            return $val;
        }
        if (is_numeric($val)) {
            return (int)$val === 1;
        }
        if (is_string($val)) {
            return filter_var($val, FILTER_VALIDATE_BOOLEAN);
        }
        return (bool)$val;
    }

    public function setIsSynced(bool $isSynced): void
    {
        $this->setData('is_synced', $isSynced);
    }

    public function getLastSyncedAt(): string
    {
        return (string) $this->getData('last_synced_at');
    }

    public function setLastSyncedAt(string $lastSyncedAt): void
    {
        $this->setData('last_synced_at', $lastSyncedAt);
    }

    public function getFields(): ?array
    {
        $fields = $this->getData('fields');
        if ($fields === null) {
            return null;
        }
        return (array) $fields;
    }

    public function setFields(?array $fields = null): void
    {
        $this->setData('fields', $fields);
    }
}
