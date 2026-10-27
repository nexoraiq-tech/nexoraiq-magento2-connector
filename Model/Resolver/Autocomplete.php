<?php

/**
 * Copyright © 2025 NexoraIQ. All rights reserved.
 */

declare(strict_types=1);

namespace NexorAIQ\Connector\Model\Resolver;

use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use NexorAIQ\Connector\Helper\Data as HelperData;
use NexorAIQ\Connector\Model\Api\RequestContext;
use Psr\Log\LoggerInterface;

class Autocomplete implements ResolverInterface
{
    /**
     * @param HelperData $helper
     * @param LoggerInterface $logger
     * @param RequestContext $requestContext
     */
    public function __construct(
        private readonly HelperData $helper,
        private readonly LoggerInterface $logger,
        private readonly RequestContext $requestContext
    ) {
    }

    /**
     * @inheritdoc
     */
    public function resolve(
        Field $field,
        $context,
        ResolveInfo $info,
        array $value = null,
        array $args = null
    ) {
        if (!$this->helper->isEnabled()) {
            return [
                'suggestions' => [],
                'products' => []
            ];
        }

        try {
            $store = $context->getExtensionAttributes()->getStore();
            $storeId = (int)$store->getId();
            $storeCode = $store->getCode();

            $query = $args['query'] ?? '';

            // Query SaaS search API with a limit of 5 products for autocomplete dropdown preview
            $params = [
                'q' => $query,
                'page' => 1,
                'limit' => 5,
                'storeView' => $storeCode
            ];
            $searchType = $this->helper->getSearchType($storeId);
            if (!empty($searchType)) {
                $params['searchType'] = $searchType;
            }

            $apiUrl = $this->helper->getApiUrl($storeId);
            $searchKey = $this->helper->getSearchKey($storeId);

            $url = $apiUrl . '/search?' . http_build_query($params);
            $ch = curl_init($url);

            $headers = [
                'Content-Type: application/json',
                'x-api-key: ' . $searchKey
            ];
            $headers = array_merge($headers, $this->requestContext->getCurlHeaderLines());

            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_TIMEOUT        => 5,
                CURLOPT_SSL_VERIFYPEER => $this->helper->verifySsl($storeId),
            ]);

            $res = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($code < 200 || $code >= 300 || !$res) {
                return [
                    'suggestions' => [],
                    'products' => []
                ];
            }

            $data = json_decode($res, true);
            if (!is_array($data)) {
                return [
                    'suggestions' => [],
                    'products' => []
                ];
            }

            // Map product hits
            $products = [];
            $rawHits = $data['hits'] ?? [];
            foreach ($rawHits as $hit) {
                $products[] = [
                    'id' => $hit['id'] ?? '',
                    'title' => $hit['title'] ?? '',
                    'sku' => $hit['sku'] ?? '',
                    'price' => isset($hit['price']) ? (float)$hit['price'] : 0.0,
                    'compareAtPrice' => isset($hit['compareAtPrice']) ? (float)$hit['compareAtPrice'] : null,
                    'category' => $hit['category'] ?? null,
                    'inStock' => (bool)($hit['inStock'] ?? false),
                    'imageUrl' => $hit['imageUrl'] ?? null,
                ];
            }

            // Build query suggestions from category/tag aggregations
            $suggestions = [];
            $aggs = $data['aggregations'] ?? null;
            if (is_array($aggs)) {
                $categories = $aggs['categories'] ?? [];
                foreach ($categories as $cat) {
                    if (count($suggestions) >= 5) {
                        break;
                    }
                    $suggestions[] = $query . ' in ' . $cat;
                }
            }

            if (empty($suggestions)) {
                $suggestions = [$query];
            }

            return [
                'suggestions' => $suggestions,
                'products' => $products
            ];
        } catch (\Throwable $e) {
            $this->logger->error('NexoraIQ Autocomplete Resolver error: ' . $e->getMessage());
            return [
                'suggestions' => [],
                'products' => []
            ];
        }
    }
}
