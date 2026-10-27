<?php
declare(strict_types=1);

namespace NexorAIQ\Connector\Controller\Adminhtml\System;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use NexorAIQ\Connector\Helper\Data as HelperData;
use NexorAIQ\Connector\Model\Indexer\CatalogAction;
use NexorAIQ\Connector\Model\SyncBlockManager;
use Psr\Log\LoggerInterface;

/**
 * "Check Subscription" button on Stores > Configuration > General Settings. Sends one real
 * product to the same /integrations/magento/product-sync-bulk endpoint used by cron/indexer —
 * if NexoraIQ accepts it, the block (subscription expired / index limit reached / etc.) is
 * confirmed cleared and a full reindex is triggered to resume real-time sync from a clean
 * state. If it still fails, the current error_type/error_message from NexoraIQ is returned
 * as-is so the admin sees exactly what's still wrong.
 */
class CheckSubscription extends Action
{
    const ADMIN_RESOURCE = 'NexorAIQ_Connector::config';

    public function __construct(
        Context $context,
        private readonly JsonFactory $resultJsonFactory,
        private readonly HelperData $helper,
        private readonly CatalogAction $catalogAction,
        private readonly SyncBlockManager $syncBlockManager,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct($context);
    }

    public function execute(): Json
    {
        $result = $this->resultJsonFactory->create();

        $this->logger->info('NexoraIQ Admin: Check Subscription requested.');
        $test = $this->helper->testProductSync();

        if (!empty($test['success'])) {
            // Belt and suspenders — sendBulkPayload() already clears the block on a successful
            // call, but make sure explicitly before resuming the full reindex.
            $this->syncBlockManager->clearBlock();

            $this->logger->info('NexoraIQ Admin: Check Subscription succeeded — triggering full reindex to resume sync.');
            $this->catalogAction->executeFull();

            return $result->setData([
                'success' => true,
                'message' => __('Subscription is active — sync has resumed and a full reindex has been queued.')->render(),
            ]);
        }

        $this->logger->warning('NexoraIQ Admin: Check Subscription failed: ' . ($test['message'] ?? ''));

        return $result->setData([
            'success' => false,
            'message' => $test['message'] ?? __('Sync is still blocked. See NexorAIQ Indexer log for details.')->render(),
        ]);
    }
}
