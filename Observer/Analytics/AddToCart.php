<?php
/**
 * Copyright © 2026 NexoraIQ. All rights reserved.
 */

declare(strict_types=1);

namespace NexorAIQ\Connector\Observer\Analytics;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Quote\Model\Quote\Item as QuoteItem;
use NexorAIQ\Connector\Model\Analytics\Attribution;
use NexorAIQ\Connector\Model\Analytics\EventLogger;
use Psr\Log\LoggerInterface;

/**
 * sales_quote_product_add_after: logs ADD_TO_CART for products found through NexorAIQ search (storefront
 * add to cart, REST and GraphQL carts alike) and keeps the search attribution on the cart item.
 */
class AddToCart implements ObserverInterface
{
    public function __construct(
        private readonly Attribution $attribution,
        private readonly EventLogger $eventLogger,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(Observer $observer): void
    {
        try {
            $events = [];
            $store = null;
            /** @var QuoteItem $item */
            foreach ((array)$observer->getEvent()->getData('items') as $item) {
                if (!$item instanceof QuoteItem || $item->getParentItem()) {
                    continue;
                }
                $product = $item->getProduct();
                $attribution = $this->attribution->fromQuoteItem($item)
                    ?? $this->attribution->fromCookie((string)$product->getData('sku'));
                if ($attribution === null) {
                    continue;
                }

                $store = $item->getQuote()->getStore();
                $attribution['productId'] = $this->eventLogger->catalogProductId($attribution['productId'])
                    ?: $this->eventLogger->productId((int)$product->getId(), $store);
                $this->attribution->saveOnQuoteItem($item, $attribution);

                $events[] = [EventLogger::ADD_TO_CART, $attribution];
            }

            if ($events) {
                $this->eventLogger->logMany($events, $store);
            }
        } catch (\Throwable $e) {
            $this->logger->error('NexorAIQ ADD_TO_CART analytics failed: ' . $e->getMessage());
        }
    }
}
