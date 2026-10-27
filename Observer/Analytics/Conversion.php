<?php
/**
 * Copyright © 2026 NexoraIQ. All rights reserved.
 */

declare(strict_types=1);

namespace NexorAIQ\Connector\Observer\Analytics;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Item as OrderItem;
use NexorAIQ\Connector\Model\Analytics\Attribution;
use NexorAIQ\Connector\Model\Analytics\EventLogger;
use Psr\Log\LoggerInterface;

/**
 * sales_model_service_quote_submit_success: logs one CONVERSION per ordered product (storefront, REST and
 * GraphQL checkouts alike).
 *
 * Revenue is the line total incl. tax minus discount, in the order currency (revenue) and the base currency
 * (baseRevenue). The search query is added when the product was found through NexorAIQ search (attribution
 * kept on the cart item, see Model\Analytics\Attribution).
 */
class Conversion implements ObserverInterface
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
            $order = $observer->getEvent()->getData('order');
            if (!$order instanceof Order) {
                return;
            }
            $store = $order->getStore();
            $currency = (string)$order->getOrderCurrencyCode();
            $baseCurrency = (string)$order->getBaseCurrencyCode();

            $events = [];
            foreach ($order->getAllVisibleItems() as $item) {
                /** @var OrderItem $item */
                $attribution = $this->attributionOf($item);
                $productId = $this->eventLogger->catalogProductId($attribution['productId'] ?? '')
                    ?: $this->eventLogger->productId((int)$item->getProductId(), $store);

                $events[] = [EventLogger::CONVERSION, [
                    'query'            => $attribution['query'] ?? null,
                    'productId'        => $productId,
                    'revenue'          => $this->lineTotal($item, false),
                    'currencyCode'     => $currency,
                    'currency'         => $currency,
                    'baseRevenue'      => $this->lineTotal($item, true),
                    'baseCurrency'     => $baseCurrency,
                    'baseCurrencyCode' => $baseCurrency,
                ]];
            }

            if ($events) {
                $this->eventLogger->logMany($events, $store);
            }
        } catch (\Throwable $e) {
            $this->logger->error('NexorAIQ CONVERSION analytics failed: ' . $e->getMessage());
        }
    }

    /**
     * Search attribution copied from the cart item's buy request, or the browser cookie as a fallback.
     *
     * @return array{query: string, productId: string}|null
     */
    private function attributionOf(OrderItem $item): ?array
    {
        $buyRequest = $item->getProductOptions()['info_buyRequest'] ?? null;
        $entry = is_array($buyRequest) ? ($buyRequest[Attribution::BUY_REQUEST_KEY] ?? null) : null;
        if (is_array($entry) && !empty($entry['query'])) {
            return ['query' => (string)$entry['query'], 'productId' => (string)($entry['productId'] ?? '')];
        }

        // The storefront keys the cookie by the search hit's SKU, which for a configurable is the parent SKU,
        // while the order item's SKU is the chosen variant's.
        $sku = (string)($item->getProduct()?->getData('sku') ?: $item->getSku());

        return $this->attribution->fromCookie($sku);
    }

    private function lineTotal(OrderItem $item, bool $base): float
    {
        $total = $base
            ? (float)$item->getBaseRowTotalInclTax() - abs((float)$item->getBaseDiscountAmount())
            : (float)$item->getRowTotalInclTax() - abs((float)$item->getDiscountAmount());

        return round(max(0.0, $total), 4);
    }
}
