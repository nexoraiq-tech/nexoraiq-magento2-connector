<?php
/**
 * Copyright © 2025 NexoraIQ. All rights reserved.
 */
declare(strict_types=1);

namespace NexorAIQ\Connector\Model\Indexer;

use Magento\Framework\Indexer\ActionInterface as IndexerActionInterface;
use Magento\Framework\Mview\ActionInterface as MviewActionInterface;
use NexorAIQ\Connector\Helper\Data as HelperData;

class Catalog implements IndexerActionInterface, MviewActionInterface
{
    public function __construct(
        private readonly CatalogAction $actionWorker,
        private readonly HelperData $helper
    ) {}

    /**
     * Used by Mview trigger execution (scheduled cron updates). Gated on realtime_sync so
     * turning "Real-time Product Sync" off actually stops outbound calls from this path too —
     * previously only the queue-producing observers/plugins checked this flag, while catalog
     * table writes still reached NexoraIQ via the mview changelog regardless of the setting.
     */
    public function execute($ids): void
    {
        if (!empty($ids) && $this->helper->isRealtimeEnabled()) {
            $this->actionWorker->execute(is_array($ids) ? $ids : [$ids]);
        }
    }

    /**
     * Reindex all database records. Not gated on realtime_sync — an explicit full reindex
     * should run regardless of that toggle, same as the manual admin "Sync Now" action.
     */
    public function executeFull(): void
    {
        $this->actionWorker->executeFull();
    }

    /**
     * Reindex a specific list of entity IDs
     */
    public function executeList(array $ids): void
    {
        if (!empty($ids) && $this->helper->isRealtimeEnabled()) {
            $this->actionWorker->execute($ids);
        }
    }

    /**
     * Reindex a single record
     */
    public function executeRow($id): void
    {
        if ($id && $this->helper->isRealtimeEnabled()) {
            $this->actionWorker->execute([(int)$id]);
        }
    }
}
