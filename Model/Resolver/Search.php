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

class Search implements ResolverInterface
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
                'hits' => [],
                'total' => 0,
                'limit' => 12,
                'currentPage' => 1,
                'totalPage' => 0,
                'searchTimeMs' => 0,
                'aggregations' => null
            ];
        }

        try {
            $store = $context->getExtensionAttributes()->getStore();
            $storeId = (int)$store->getId();
            $storeCode = $store->getCode();

            $query = $args['query'] ?? '';
            $page = $args['currentPage'] ?? 1;
            $limit = $args['pageSize'] ?? 12;

            $params = [
                'q' => $query,
                'page' => $page,
                'limit' => $limit,
                'storeView' => $storeCode
            ];

            if (isset($args['category'])) {
                $params['category'] = $args['category'];
            }
            if (isset($args['minPrice'])) {
                $params['minPrice'] = $args['minPrice'];
            }
            if (isset($args['maxPrice'])) {
                $params['maxPrice'] = $args['maxPrice'];
            }
            if (isset($args['sortBy'])) {
                $params['sortBy'] = $args['sortBy'];
            }
            if (isset($args['inStockOnly'])) {
                $params['inStockOnly'] = $args['inStockOnly'] ? 'true' : 'false';
            }
            $searchType = $args['searchType'] ?? $this->helper->getSearchType($storeId);
            if (!empty($searchType)) {
                $params['searchType'] = $searchType;
            }

            // Build request URL
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
                $this->logger->warning('NexoraIQ search API proxy failed', ['code' => $code, 'url' => $url]);
                return [
                    'hits' => [],
                    'total' => 0,
                    'limit' => $limit,
                    'currentPage' => $page,
                    'totalPage' => 0,
                    'searchTimeMs' => 0,
                    'aggregations' => null
                ];
            }

            $data = json_decode($res, true);
            if (!is_array($data)) {
                return [
                    'hits' => [],
                    'total' => 0,
                    'limit' => $limit,
                    'currentPage' => $page,
                    'totalPage' => 0,
                    'searchTimeMs' => 0,
                    'aggregations' => null
                ];
            }

            // Format hits
            $hits = [];
            $rawHits = $data['hits'] ?? [];
            foreach ($rawHits as $hit) {
                $hits[] = [
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

            // Format aggregations
            $rawAggs = $data['aggregations'] ?? null;
            $aggregations = null;
            if (is_array($rawAggs)) {
                $aggregations = [
                    'categories' => $rawAggs['categories'] ?? [],
                    'tags' => $rawAggs['tags'] ?? [],
                    'priceRanges' => $rawAggs['priceRanges'] ?? [],
                ];
            }

            return [
                'hits' => $hits,
                'total' => (int)($data['total'] ?? 0),
                'limit' => (int)($data['limit'] ?? $limit),
                'currentPage' => (int)($data['currentPage'] ?? $page),
                'totalPage' => (int)($data['totalPage'] ?? 0),
                'searchTimeMs' => (int)($data['search_time_ms'] ?? 0),
                'aggregations' => $aggregations
            ];
        } catch (\Throwable $e) {
            $this->logger->error('NexoraIQ Search Resolver error: ' . $e->getMessage());
            return [
                'hits' => [],
                'total' => 0,
                'limit' => 12,
                'currentPage' => 1,
                'totalPage' => 0,
                'searchTimeMs' => 0,
                'aggregations' => null
            ];
        }
    }
}
