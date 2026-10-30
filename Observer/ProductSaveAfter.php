<?php
declare(strict_types=1);
namespace NexorAIQ\Connector\Observer;

use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer;
use NexorAIQ\Connector\Helper\Data;
use NexorAIQ\Connector\Model\SyncQueueManager;
use Psr\Log\LoggerInterface;

/**
 * catalog_product_save_after fires synchronously inside the admin Save Product request,
 * so — like StockUpdateAfter — this must only do a fast local DB write. Calling
 * Helper::syncProduct() directly here used to block the admin save on outbound HTTP calls
 * to the NexoraIQ API (one active-store-views lookup plus one product-sync POST per store
 * view), which is what made admin product save slow when the module was enabled.
 * NexorAIQ\Connector\Cron\IndexQueue drains this queue and performs the actual sync.
 */
class ProductSaveAfter implements ObserverInterface
{
    public function __construct(
        private readonly Data $helper,
        private readonly SyncQueueManager $syncQueueManager,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(Observer $observer): void
    {
        if (!$this->helper->isEnabled() || !$this->helper->isRealtimeEnabled()) {
            return;
        }

        $product = $observer->getEvent()->getProduct();
        if (!$product || !$product->getId()) {
            return;
        }

        $productId = (int) $product->getId();
        $this->logger->info(sprintf('catalog_product_save_after: product_id=%d enqueued', $productId));
        $this->syncQueueManager->enqueueBatch([$productId]);
    }
}
