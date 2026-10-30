<?php
/**
 * Copyright © 2026 NexorAIQ. All rights reserved.
 */

declare(strict_types=1);

namespace NexorAIQ\Connector\Controller\Ajax;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json as JsonResult;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Store\Model\StoreManagerInterface;
use NexorAIQ\Connector\Model\Api\ApiException;
use NexorAIQ\Connector\Model\Api\Client;
use NexorAIQ\Connector\ViewModel\StorefrontSearch;

/**
 * GET /nexoraiq/ajax/autocomplete?q=...
 *
 * Storefront proxy for the NexorAIQ query autocomplete API. The browser never sees the API key;
 * the result limit and store view come from the store configuration, not from the request.
 */
class Autocomplete implements HttpGetActionInterface
{
    private const MAX_QUERY_LENGTH = 100;
    private const CACHE_SECONDS = 300;

    public function __construct(
        private readonly RequestInterface $request,
        private readonly JsonFactory $jsonFactory,
        private readonly Client $client,
        private readonly StorefrontSearch $storefrontSearch,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    public function execute(): JsonResult
    {
        $result = $this->jsonFactory->create();
        $result->setHeader('X-Robots-Tag', 'noindex, nofollow', true);

        $query = $this->getQuery();
        if ($query === '' || !$this->storefrontSearch->isActive()) {
            return $this->noStore($result->setData(['suggestions' => []]));
        }

        $store = $this->storeManager->getStore();
        try {
            $response = $this->client->get('/search/autocomplete', [
                'q'         => $query,
                'type'      => 'query',
                'limit'     => $this->storefrontSearch->getAutocompleteLimit(),
                'storeView' => $store->getCode(),
            ], (int)$store->getId());
        } catch (ApiException $e) {
            // Already logged by the client; the dropdown just shows "no suggestions".
            return $this->noStore($result->setData(['suggestions' => []]));
        }

        $result->setHeader('Cache-Control', 'public, max-age=' . self::CACHE_SECONDS, true);
        return $result->setData(['suggestions' => $this->mapSuggestions($response)]);
    }

    private function getQuery(): string
    {
        $query = $this->request->getParam('q');
        if (!is_string($query)) {
            return '';
        }
        $query = trim(strip_tags($query));
        return mb_substr((string)preg_replace('/\s+/u', ' ', $query), 0, self::MAX_QUERY_LENGTH);
    }

    /**
     * Keep only the fields the dropdown needs, as plain strings.
     */
    private function mapSuggestions(array $response): array
    {
        $limit = $this->storefrontSearch->getAutocompleteLimit();
        $suggestions = [];
        foreach ($response['suggestions'] ?? [] as $item) {
            if (!is_array($item) || !is_string($item['text'] ?? null) || trim($item['text']) === '') {
                continue;
            }
            $parts = is_array($item['parts'] ?? null) ? $item['parts'] : [];
            $hasParts = isset($parts['match']) && is_string($parts['match']);
            $suggestions[] = [
                'text'   => $item['text'],
                'before' => $hasParts ? (string)($parts['before'] ?? '') : $item['text'],
                'match'  => $hasParts ? $parts['match'] : '',
                'after'  => $hasParts ? (string)($parts['after'] ?? '') : '',
                'url'    => !empty($item['redirected']) ? $this->safeUrl($item['url'] ?? null) : '',
            ];
            if (count($suggestions) >= $limit) {
                break;
            }
        }

        return $suggestions;
    }

    /**
     * Only http(s) or site-relative redirect URLs; anything else (javascript:, data:, //host) is dropped.
     */
    private function safeUrl(mixed $url): string
    {
        if (!is_string($url) || $url === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $url)) {
            return $url;
        }
        if (str_starts_with($url, '//') || preg_match('#^[a-z][a-z0-9+.-]*:#i', $url)) {
            return '';
        }
        return ltrim($url, '/');
    }

    private function noStore(JsonResult $result): JsonResult
    {
        return $result->setHeader('Cache-Control', 'no-store', true);
    }
}
