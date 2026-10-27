<?php
declare(strict_types=1);
namespace NexorAIQ\Connector\Observer;

use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer;
use NexorAIQ\Connector\Helper\Data as HelperData;
use NexorAIQ\Connector\Model\SyncQueueManager;
use Psr\Log\LoggerInterface;

/**
 * cataloginventory_stock_item_save_after also fires synchronously during Place Order
 * (legacy stock decrement), so this must only do a fast local DB write — see
 * NexorAIQ\Connector\Cron\IndexQueue for the actual (network) sync.
 */
class StockUpdateAfter implements ObserverInterface
{
    public function __construct(
        private readonly SyncQueueManager $syncQueueManager,
        private readonly HelperData $helper,
        private readonly LoggerInterface $logger
    ) {}

    public function execute(Observer $observer): void
    {
        if (!$this->helper->isEnabled() || !$this->helper->isRealtimeEnabled()) {
            return;
        }

        $item = $observer->getEvent()->getItem();
        if (!$item || !$item->getProductId()) return;

        $productId = (int) $item->getProductId();
        $this->logger->info(sprintf('cataloginventory_stock_item_save_after: product_id=%d enqueued', $productId));
        $this->syncQueueManager->enqueueBatch([$productId]);
    }
}
