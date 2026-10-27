<?php
declare(strict_types=1);
namespace NexorAIQ\Connector\Observer;

use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer;
use NexorAIQ\Connector\Helper\Data;
use NexorAIQ\Connector\Model\SyncQueueManager;
use Psr\Log\LoggerInterface;

/**
 * Runs synchronously inside the admin Delete Product request, so — like ProductSaveAfter —
 * this must only do a fast local DB write instead of blocking on outbound HTTP calls.
 * The queued product id is re-fetched by CatalogAction during the cron drain; a
 * NoSuchEntityException there is caught and reported as a 'deleted' sync, same as before.
 */
class ProductDeleteAfter implements ObserverInterface
{
    public function __construct(
        private readonly Data $helper,
        private readonly SyncQueueManager $syncQueueManager,
        private readonly LoggerInterface $logger
    ) {}

    public function execute(Observer $observer): void
    {
        if (!$this->helper->isEnabled() || !$this->helper->isRealtimeEnabled()) {
            return;
        }

        $product = $observer->getEvent()->getProduct();
        if (!$product || !$product->getId()) return;

        $productId = (int) $product->getId();
        $this->logger->info(sprintf('catalog_product_delete_after: product_id=%d enqueued', $productId));
        $this->syncQueueManager->enqueueBatch([$productId]);
    }
}
