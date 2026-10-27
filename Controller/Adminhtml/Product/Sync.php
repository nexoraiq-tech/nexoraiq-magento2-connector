<?php
declare(strict_types=1);

namespace NexorAIQ\Connector\Controller\Adminhtml\Product;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use NexorAIQ\Connector\Model\Indexer\CatalogAction;
use Psr\Log\LoggerInterface;

class Sync extends Action
{
    /**
     * Authorization level of a basic admin session
     */
    const ADMIN_RESOURCE = 'NexorAIQ_Connector::config';

    public function __construct(
        Context $context,
        private readonly JsonFactory $resultJsonFactory,
        private readonly CatalogAction $catalogAction,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct($context);
    }

    /**
     * Reindex / Sync a single product ID to NexoraIQ via Ajax
     */
    public function execute(): Json
    {
        $result = $this->resultJsonFactory->create();
        $productId = (int)$this->getRequest()->getParam('product_id');

        if (!$productId) {
            return $result->setData([
                'success' => false,
                'message' => __('Invalid Product ID provided.')
            ]);
        }

        $this->logger->info(sprintf('NexoraIQ Admin: manual sync requested for product_id=%d', $productId));

        try {
            $this->catalogAction->execute([$productId]);
            $this->logger->info(sprintf('NexoraIQ Admin: manual sync completed for product_id=%d', $productId));
            return $result->setData([
                'success' => true,
                'message' => __('Product synced successfully to NexorAIQ.')
            ]);
        } catch (\Throwable $e) {
            $this->logger->error(sprintf('NexoraIQ Admin: manual sync failed for product_id=%d: %s', $productId, $e->getMessage()));
            return $result->setData([
                'success' => false,
                'message' => __('Sync failed: %1', $e->getMessage())
            ]);
        }
    }
}
