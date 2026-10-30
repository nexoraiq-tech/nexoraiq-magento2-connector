<?php
/**
 * Copyright © 2026 NexorAIQ. All rights reserved.
 */

declare(strict_types=1);

namespace NexorAIQ\Connector\Model\Analytics;

use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\Stdlib\CookieManagerInterface;
use Magento\Quote\Model\Quote\Item as QuoteItem;

/**
 * Ties cart items to the NexorAIQ search that led to them, so ADD_TO_CART events carry the search query and
 * the product id of the clicked hit.
 *
 * - The storefront (nexoraiqAnalytics.remember()) keeps the last clicked / added search hits in the
 *   "nexoraiq_attribution" cookie: {"<sku>": {"q": "<query>", "p": "<productId>", "s": "<source>"}}, where
 *   source is "trending" for the overlay's trending products (query "trending") and absent for typed searches.
 * - When such a product is added to the cart, the attribution is stored in the quote item's buy request, so
 *   later quantity updates of that cart line keep it.
 */
class Attribution
{
    public const COOKIE = 'nexoraiq_attribution';
    public const BUY_REQUEST_KEY = 'nexoraiq_search';

    public function __construct(
        private readonly CookieManagerInterface $cookieManager,
        private readonly Json $json
    ) {
    }

    /**
     * Attribution the browser recorded for this SKU.
     *
     * @return array{query: string, productId: string, source: string}|null
     */
    public function fromCookie(string $sku): ?array
    {
        $raw = (string)$this->cookieManager->getCookie(self::COOKIE);
        if ($raw === '' || $sku === '') {
            return null;
        }
        try {
            $entries = $this->json->unserialize($raw);
        } catch (\InvalidArgumentException $e) {
            return null;
        }
        $entry = is_array($entries) ? ($entries[$sku] ?? null) : null;
        if (!is_array($entry) || !is_string($entry['q'] ?? null) || trim($entry['q']) === '') {
            return null;
        }

        return [
            'query'     => mb_substr(trim($entry['q']), 0, 255),
            'productId' => is_string($entry['p'] ?? null) ? $entry['p'] : '',
            'source'    => is_string($entry['s'] ?? null) ? $entry['s'] : '',
        ];
    }

    /**
     * Attribution stored on a cart item.
     *
     * @return array{query: string, productId: string, source: string}|null
     */
    public function fromQuoteItem(QuoteItem $item): ?array
    {
        $option = $item->getOptionByCode('info_buyRequest');
        if (!$option) {
            return null;
        }
        try {
            $buyRequest = $this->json->unserialize((string)$option->getValue());
        } catch (\InvalidArgumentException $e) {
            return null;
        }
        $entry = is_array($buyRequest) ? ($buyRequest[self::BUY_REQUEST_KEY] ?? null) : null;

        return is_array($entry) && !empty($entry['query'])
            ? $this->normalize($entry)
            : null;
    }

    /**
     * @param array{query: string, productId: string, source?: string} $attribution
     */
    public function saveOnQuoteItem(QuoteItem $item, array $attribution): void
    {
        $option = $item->getOptionByCode('info_buyRequest');
        if (!$option) {
            return;
        }
        try {
            $buyRequest = $this->json->unserialize((string)$option->getValue());
        } catch (\InvalidArgumentException $e) {
            return;
        }
        if (!is_array($buyRequest)) {
            return;
        }
        $buyRequest[self::BUY_REQUEST_KEY] = $this->normalize($attribution);
        $option->setValue($this->json->serialize($buyRequest));
    }

    /**
     * Attribution as kept on cart / order items (buy request "nexoraiq_search").
     *
     * @return array{query: string, productId: string, source: string}
     */
    public function normalize(array $entry): array
    {
        return [
            'query'     => (string)($entry['query'] ?? ''),
            'productId' => (string)($entry['productId'] ?? ''),
            'source'    => is_string($entry['source'] ?? null) ? $entry['source'] : '',
        ];
    }
}
