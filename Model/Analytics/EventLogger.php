<?php
/**
 * Copyright © 2026 NexoraIQ. All rights reserved.
 */

declare(strict_types=1);

namespace NexorAIQ\Connector\Model\Analytics;

use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use NexorAIQ\Connector\Helper\Data as HelperData;
use NexorAIQ\Connector\Model\Api\Client;
use NexorAIQ\Connector\Model\Api\RequestContext;
use Psr\Log\LoggerInterface;

/**
 * Sends storefront analytics to the NexorAIQ GraphQL "logEvent" mutation, per store view.
 *
 * Every event carries sessionId, userId and device (Model\Api\RequestContext) and storeView: the store code,
 * e.g. "india_en" (NexorAIQ identifies store views by code, so the numeric Magento store id is not sent).
 * On top of that each event type sends its own fields:
 * - CLICK:       query, productId
 * - ADD_TO_CART: query, productId
 * - CONVERSION:  query (when known), productId, revenue / currency(Code), baseRevenue / baseCurrency(Code)
 *
 * Product ids use the NexorAIQ catalog format "{websiteId}_{storeId}_{entityId}" (see productId()).
 */
class EventLogger
{
    public const CLICK = 'CLICK';
    public const ADD_TO_CART = 'ADD_TO_CART';
    public const CONVERSION = 'CONVERSION';

    /** Fields each event type sends besides the common ones, with their GraphQL types. */
    private const EVENT_FIELDS = [
        self::CLICK       => ['productId' => 'String'],
        self::ADD_TO_CART => ['productId' => 'String'],
        self::CONVERSION  => [
            'productId'        => 'String',
            'revenue'          => 'Float',
            'currencyCode'     => 'String',
            'currency'         => 'String',
            'baseRevenue'      => 'Float',
            'baseCurrency'     => 'String',
            'baseCurrencyCode' => 'String',
        ],
    ];

    /** Fields an event type cannot be logged without. */
    private const REQUIRED_FIELDS = [
        self::CLICK       => ['productId'],
        self::ADD_TO_CART => ['productId'],
        self::CONVERSION  => ['productId', 'revenue', 'currencyCode'],
    ];

    /** Fields read back from each logEvent. */
    private const SELECTION = 'id responseId eventType productId createdAt';
    private const CONVERSION_SELECTION =
        ' revenue currencyCode currency baseRevenue baseCurrency baseCurrencyCode';

    private const COMMON_FIELDS = [
        'sessionId'   => 'String!',
        'eventType'   => 'EventType!',
        'query'       => 'String',
        'userId'      => 'String',
        'device'      => 'String',
        'storeView'   => 'String',
    ];

    private const TIMEOUT = 5;
    /** Conversions carry revenue and the API can take ~3s to answer, so they get longer before giving up. */
    private const CONVERSION_TIMEOUT = 10;

    public function __construct(
        private readonly Client $client,
        private readonly HelperData $helper,
        private readonly RequestContext $requestContext,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Log one event. Returns the logEvent result (id, responseId, eventType, ...) or null when it was not sent.
     *
     * @param string $eventType CLICK, ADD_TO_CART or CONVERSION (case-insensitive)
     * @param array<string, mixed> $data Event fields, e.g. ['query' => 'iphone', 'productId' => '1_1_20622']
     * @param StoreInterface|int|null $store Store view the event belongs to; the current one by default
     */
    public function log(string $eventType, array $data, StoreInterface|int|null $store = null): ?array
    {
        return $this->logMany([[$eventType, $data]], $store)[0] ?? null;
    }

    /**
     * Log several events of one store view in a single request (one aliased logEvent per event).
     *
     * @param array<int, array{0: string, 1: array<string, mixed>}> $events [eventType, data] pairs
     * @return array<int, array|null> logEvent results, keyed like $events
     */
    public function logMany(array $events, StoreInterface|int|null $store = null): array
    {
        $results = array_fill_keys(array_keys($events), null);
        try {
            $store = $store instanceof StoreInterface ? $store : $this->storeManager->getStore($store);
        } catch (\Throwable $e) {
            return $results;
        }
        $storeId = (int)$store->getId();
        if (!$storeId || !$this->helper->isEnabled($storeId)) {
            return $results;
        }

        $declarations = [];
        $mutations = [];
        $variables = [];
        $aliases = [];
        $hasConversion = false;
        foreach ($events as $key => [$eventType, $data]) {
            $eventType = strtoupper(trim($eventType));
            $fields = $this->buildFields($eventType, $data, $store);
            if ($fields === null) {
                continue;
            }

            $hasConversion = $hasConversion || $eventType === self::CONVERSION;
            $alias = 'e' . count($aliases);
            $aliases[$alias] = $key;
            $types = self::COMMON_FIELDS + self::EVENT_FIELDS[$eventType];
            $arguments = [];
            foreach ($fields as $name => $value) {
                $variable = $alias . '_' . $name;
                $declarations[] = '$' . $variable . ': ' . $types[$name];
                $arguments[] = $name . ': $' . $variable;
                $variables[$variable] = $value;
            }
            $mutations[] = sprintf(
                '%s: logEvent(%s) { %s }',
                $alias,
                implode(', ', $arguments),
                self::SELECTION . ($eventType === self::CONVERSION ? self::CONVERSION_SELECTION : '')
            );
        }

        if (!$mutations) {
            return $results;
        }

        $request = [
            'query'     => 'mutation LogEvents(' . implode(', ', $declarations) . ') { ' . implode(' ', $mutations) . ' }',
            'variables' => $variables,
        ];

        try {
            $response = $this->client->post(
                '/graphql',
                $request,
                $storeId,
                Client::AUTH_SEARCH_KEY,
                $hasConversion ? self::CONVERSION_TIMEOUT : self::TIMEOUT
            );
        } catch (\Throwable $e) {
            $this->logger->error('NexorAIQ logEvent failed: ' . $e->getMessage(), ['store' => $store->getCode()]);
            return $results;
        }

        if (!empty($response['errors'])) {
            $this->logger->warning('NexorAIQ logEvent returned errors', [
                'store'  => $store->getCode(),
                'errors' => array_column((array)$response['errors'], 'message'),
            ]);
        }
        foreach ($aliases as $alias => $key) {
            $results[$key] = $response['data'][$alias] ?? null;
            if (($results[$key]['eventType'] ?? null) === self::CONVERSION) {
                $this->logger->info('NexorAIQ CONVERSION logged', [
                    'store'     => $store->getCode(),
                    'id'        => $results[$key]['id'] ?? null,
                    'productId' => $results[$key]['productId'] ?? null,
                    'revenue'   => $results[$key]['revenue'] ?? null,
                    'currency'  => $results[$key]['currencyCode'] ?? null,
                ]);
            }
        }

        return $results;
    }

    /**
     * Log a storefront search to the NexorAIQ "logSearch" mutation, tagged with the store view
     * (storeView = store code, e.g. "india_en"). Returns the responseId, or null when it was not sent.
     *
     * @param StoreInterface|int|null $store Store view the search was made in; the current one by default
     */
    public function logSearch(
        string $query,
        ?string $sessionId,
        int $resultsCount,
        int $tookMs,
        ?string $userId = null,
        ?string $device = null,
        StoreInterface|int|null $store = null
    ): ?string {
        try {
            $store = $store instanceof StoreInterface ? $store : $this->storeManager->getStore($store);
        } catch (\Throwable $e) {
            return null;
        }
        $storeId = (int)$store->getId();
        $query = mb_substr(trim($query), 0, 255);
        if (!$storeId || $query === '' || !$this->helper->isEnabled($storeId)) {
            return null;
        }

        $variables = array_filter([
            'query'        => $query,
            'sessionId'    => $this->safeId($sessionId) ?: $this->requestContext->getSessionId(),
            'resultsCount' => max(0, $resultsCount),
            'tookMs'       => max(0, $tookMs),
            'userId'       => $this->safeId($userId) ?: $this->requestContext->getUserId(),
            'device'       => $this->safeId($device) ?: $this->requestContext->getDevice(),
            'storeView'    => (string)$store->getCode(),
        ], static fn ($value) => $value !== null && $value !== '');

        $request = [
            'query' => 'mutation LogSearch($query: String!, $sessionId: String!, $resultsCount: Int!, $tookMs: Int!,'
                . ' $userId: String, $device: String, $storeView: String) {'
                . ' logSearch(query: $query, sessionId: $sessionId, resultsCount: $resultsCount, tookMs: $tookMs,'
                . ' userId: $userId, device: $device, storeView: $storeView)'
                . ' { success responseId } }',
            'variables' => $variables,
        ];

        try {
            $response = $this->client->post('/graphql', $request, $storeId, Client::AUTH_SEARCH_KEY, self::TIMEOUT);
        } catch (\Throwable $e) {
            $this->logger->error('NexorAIQ logSearch failed: ' . $e->getMessage(), ['store' => $store->getCode()]);
            return null;
        }
        if (!empty($response['errors'])) {
            $this->logger->warning('NexorAIQ logSearch returned errors', [
                'store'  => $store->getCode(),
                'errors' => array_column((array)$response['errors'], 'message'),
            ]);
        }
        $result = $response['data']['logSearch'] ?? null;

        return !empty($result['success']) ? (string)($result['responseId'] ?? '') : null;
    }

    /**
     * NexorAIQ catalog product id: "{websiteId}_{storeId}_{entityId}".
     */
    public function productId(int $entityId, StoreInterface $store): string
    {
        return $store->getWebsiteId() . '_' . $store->getId() . '_' . $entityId;
    }

    /**
     * Product id as the browser sends it: a search hit's "externalId" ("{tenant}_1_1_20622") or the bare id.
     */
    public function normalizeProductId(?string $productId): string
    {
        $productId = trim((string)$productId);
        if (preg_match('/(?:^|_)(\d+_\d+_\d+)$/', $productId, $match)) {
            return $match[1];
        }
        return $this->safeId($productId);
    }

    /**
     * Catalog product id ("{websiteId}_{storeId}_{entityId}") from a browser value, or '' when it is not one
     * (e.g. the search hit's own UUID).
     */
    public function catalogProductId(?string $productId): string
    {
        $productId = $this->normalizeProductId($productId);
        return preg_match('/^\d+_\d+_\d+$/', $productId) ? $productId : '';
    }

    /**
     * Ids can come from the browser: keep them short and plain.
     */
    private function safeId(mixed $id): string
    {
        $id = is_scalar($id) ? trim((string)$id) : '';
        return preg_match('/^[A-Za-z0-9._:-]{1,128}$/', $id) ? $id : '';
    }

    /**
     * Event arguments: common shopper / store view context plus the fields of this event type.
     *
     * @return array<string, mixed>|null null when the event type is unknown or a required field is missing
     */
    private function buildFields(string $eventType, array $data, StoreInterface $store): ?array
    {
        if (!isset(self::EVENT_FIELDS[$eventType])) {
            $this->logger->warning('NexorAIQ logEvent: unknown event type', ['eventType' => $eventType]);
            return null;
        }

        $fields = [
            'sessionId'   => $this->safeId($data['sessionId'] ?? null) ?: $this->requestContext->getSessionId(),
            'eventType'   => $eventType,
            'query'       => isset($data['query']) ? mb_substr(trim((string)$data['query']), 0, 255) : null,
            'userId'      => $this->requestContext->getUserId(),
            'device'      => $this->requestContext->getDevice(),
            'storeView'   => (string)$store->getCode(),
        ];
        foreach (self::EVENT_FIELDS[$eventType] as $name => $type) {
            $value = $data[$name] ?? null;
            if ($value === null || $value === '' || !is_scalar($value)) {
                continue;
            }
            $fields[$name] = $type === 'Float' ? round((float)$value, 4) : (string)$value;
        }

        foreach (self::REQUIRED_FIELDS[$eventType] as $name) {
            if (!isset($fields[$name]) || $fields[$name] === '') {
                $this->logger->warning('NexorAIQ logEvent: missing field', ['eventType' => $eventType, 'field' => $name]);
                return null;
            }
        }

        return array_filter($fields, static fn ($value) => $value !== null && $value !== '');
    }

}
