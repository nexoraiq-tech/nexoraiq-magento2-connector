<?php
/**
 * Copyright © 2026 NexorAIQ. All rights reserved.
 */

declare(strict_types=1);

namespace NexorAIQ\Connector\Model;

use NexorAIQ\Connector\Api\SyncStatusInterface;
use NexorAIQ\Connector\Api\Data\SyncStatusItemInterface;
use NexorAIQ\Connector\Helper\Data as HelperData;
use Magento\Framework\Webapi\Exception as WebapiException;
use Psr\Log\LoggerInterface;

class SyncStatusApi implements SyncStatusInterface
{
    public function __construct(
        private readonly SyncStatusManager $syncStatusManager,
        private readonly HelperData        $helper,
        private readonly LoggerInterface   $logger
    ) {
    }

    /**
     * Validate the supplied connection key against the stored Magento config value.
     */
    private function validateKey(string $key): bool
    {
        $configuredKey = $this->helper->getConnectionKey();

        if (empty($configuredKey) || empty($key)) {
            $this->logger->warning('NexoraIQ SyncStatusApi: missing key in request.');
            return false;
        }

        if (!hash_equals($configuredKey, $key)) {
            $this->logger->warning('NexoraIQ SyncStatusApi: invalid connection key.');
            return false;
        }

        return true;
    }

    /**
     * @inheritdoc
     */
    public function reset(string $key = '', string $storeCode = '', bool $full = false): string
    {
        if (!$this->validateKey($key)) {
            throw new WebapiException(__('Unauthorized'), 0, WebapiException::HTTP_UNAUTHORIZED);
        }

        try {
            if ($full || empty($storeCode)) {
                $this->syncStatusManager->clearAllSyncStatuses();
                $this->logger->info('NexoraIQ SyncStatusApi: cleared ALL sync status records.');
            } else {
                $this->syncStatusManager->clearSyncStatusForStore(0, $storeCode);
                $this->logger->info("NexoraIQ SyncStatusApi: cleared sync status for store [{$storeCode}].");
            }
            return json_encode(['success' => true]);
        } catch (\Throwable $e) {
            $this->logger->error('SyncStatusApi::reset error: ' . $e->getMessage());
            return json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * @inheritdoc
     *
     * @param string $key
     * @param \NexorAIQ\Connector\Api\Data\SyncStatusItemInterface[] $items
     * @return string
     */
    public function updateBatch(string $key = '', array $items = []): string
    {
        if (!$this->validateKey($key)) {
            throw new WebapiException(__('Unauthorized'), 0, WebapiException::HTTP_UNAUTHORIZED);
        }

        try {
            if (!empty($items)) {
                $rawItems = array_map(function ($item) {
                    if ($item instanceof SyncStatusItemInterface) {
                        $isSynced = $item->getIsSynced();
                        if (method_exists($item, 'getData')) {
                            $data = $item->getData();
                            foreach (['is_synced', 'isSynced', 'is_synced_flag'] as $k) {
                                if (array_key_exists($k, $data) && $data[$k] !== null) {
                                    $val = $data[$k];
                                    $isSynced = is_bool($val) ? $val : in_array(strtolower((string)$val), ['true', '1', 'yes'], true);
                                    break;
                                }
                            }
                        }

                        return [
                            'product_id'     => $item->getProductId(),
                            'store_id'       => $item->getStoreId(),
                            'store_code'     => $item->getStoreCode(),
                            'is_synced'      => $isSynced,
                            'last_synced_at' => $item->getLastSyncedAt(),
                            'fields'         => $item->getFields(),
                        ];
                    }
                    return (array) $item;
                }, $items);

                $this->syncStatusManager->saveBatchSyncStatus($rawItems);
            }
            return json_encode(['success' => true, 'count' => count($items)]);
        } catch (\Throwable $e) {
            $this->logger->error('SyncStatusApi::updateBatch error: ' . $e->getMessage());
            return json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }
}
