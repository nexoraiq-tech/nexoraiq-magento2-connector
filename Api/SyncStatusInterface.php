<?php
declare(strict_types=1);

namespace NexorAIQ\Connector\Api;

interface SyncStatusInterface
{
    /**
     * Clear product sync status records for a store view (or all) before a resync.
     *
     * The NexoraIQ connection key must be supplied via the ?key= query parameter.
     *
     * @param string $key       NexoraIQ connection key (mapped from ?key= query param)
     * @param string $storeCode Store view code to clear. Empty string = clear all.
     * @param bool   $full      If true, ignore $storeCode and clear all store views.
     * @return string JSON-encoded result, e.g. {"success":true}
     * @throws \Magento\Framework\Webapi\Exception When the connection key is wrong (HTTP 401)
     */
    public function reset(string $key = '', string $storeCode = '', bool $full = false): string;

    /**
     * Batch upsert product sync status records.
     *
     * The NexoraIQ connection key must be supplied via the ?key= query parameter.
     *
     * @param string $key NexoraIQ connection key (mapped from ?key= query param)
     * @param \NexorAIQ\Connector\Api\Data\SyncStatusItemInterface[] $items
     * @return string JSON-encoded result, e.g. {"success":true,"count":42}
     * @throws \Magento\Framework\Webapi\Exception When the connection key is wrong (HTTP 401)
     */
    public function updateBatch(string $key = '', array $items = []): string;
}
