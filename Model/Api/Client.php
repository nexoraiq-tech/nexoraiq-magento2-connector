<?php
/**
 * Copyright © 2026 NexorAIQ. All rights reserved.
 */

declare(strict_types=1);

namespace NexorAIQ\Connector\Model\Api;

use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Framework\Phrase;
use Magento\Framework\Serialize\Serializer\Json;
use NexorAIQ\Connector\Helper\Data as HelperData;
use Psr\Log\LoggerInterface;

/**
 * Server-side HTTP client for the NexorAIQ API.
 *
 * Keys are read from the store configuration and only ever sent from the server, never exposed to
 * the browser. Every call has short timeouts and SSL verification (see HelperData::verifySsl()).
 */
class Client
{
    /** Public read-only search key, sent as "x-api-key" (search, autocomplete, analytics). */
    public const AUTH_SEARCH_KEY = 'search_key';

    /** Private connection key, sent as "Authorization: Bearer" (catalog sync, account calls). */
    public const AUTH_CONNECTION_KEY = 'connection_key';

    private const DEFAULT_TIMEOUT = 5;
    private const CONNECT_TIMEOUT = 3;

    public function __construct(
        private readonly CurlFactory $curlFactory,
        private readonly HelperData $helper,
        private readonly Json $json,
        private readonly LoggerInterface $logger,
        private readonly RequestContext $requestContext
    ) {
    }

    /**
     * GET {apiUrl}{path}?{query} and return the decoded JSON body.
     *
     * @param string $path    API path, e.g. "/search/autocomplete"
     * @param array  $query   Query string parameters; null values are dropped
     * @param int|null $storeId Store whose API URL and keys are used
     * @return array
     * @throws ApiException
     */
    public function get(
        string $path,
        array $query = [],
        ?int $storeId = null,
        string $auth = self::AUTH_SEARCH_KEY,
        int $timeout = self::DEFAULT_TIMEOUT
    ): array {
        $query = array_filter($query, static fn ($value) => $value !== null);
        $url = $this->buildUrl($path, $storeId) . ($query ? '?' . http_build_query($query) : '');

        $curl = $this->createCurl($storeId, $auth, $timeout);
        return $this->send($curl, 'GET', $url, static fn (Curl $c) => $c->get($url));
    }

    /**
     * POST a JSON body to {apiUrl}{path} and return the decoded JSON body.
     *
     * @param string $path
     * @param array  $body Encoded as JSON
     * @param int|null $storeId
     * @return array
     * @throws ApiException
     */
    public function post(
        string $path,
        array $body = [],
        ?int $storeId = null,
        string $auth = self::AUTH_SEARCH_KEY,
        int $timeout = self::DEFAULT_TIMEOUT
    ): array {
        $url = $this->buildUrl($path, $storeId);
        $payload = $this->json->serialize($body);

        $curl = $this->createCurl($storeId, $auth, $timeout);
        $curl->addHeader('Content-Type', 'application/json');
        return $this->send($curl, 'POST', $url, static fn (Curl $c) => $c->post($url, $payload));
    }

    private function buildUrl(string $path, ?int $storeId): string
    {
        return $this->helper->getApiUrl($storeId) . '/' . ltrim($path, '/');
    }

    /**
     * @throws ApiException
     */
    private function createCurl(?int $storeId, string $auth, int $timeout): Curl
    {
        $key = $auth === self::AUTH_CONNECTION_KEY
            ? $this->helper->getConnectionKey($storeId)
            : $this->helper->getSearchKey($storeId);
        if ($key === '') {
            throw new ApiException(new Phrase('NexorAIQ API key "%1" is not configured.', [$auth]));
        }

        $verifySsl = $this->helper->verifySsl($storeId);

        /** @var Curl $curl */
        $curl = $this->curlFactory->create();
        $curl->setTimeout($timeout);
        $curl->setOption(CURLOPT_CONNECTTIMEOUT, self::CONNECT_TIMEOUT);
        $curl->setOption(CURLOPT_SSL_VERIFYPEER, $verifySsl);
        $curl->setOption(CURLOPT_SSL_VERIFYHOST, $verifySsl ? 2 : 0);
        $curl->setOption(CURLOPT_PROTOCOLS, CURLPROTO_HTTPS | CURLPROTO_HTTP);
        $curl->setOption(CURLOPT_FOLLOWLOCATION, false);
        $curl->addHeader('Accept', 'application/json');
        if ($auth === self::AUTH_CONNECTION_KEY) {
            $curl->addHeader('Authorization', 'Bearer ' . $key);
        } else {
            $curl->addHeader('x-api-key', $key);
            // Storefront calls (search, autocomplete, analytics): session / shopper / device context.
            foreach ($this->requestContext->getHeaders() as $name => $value) {
                $curl->addHeader($name, $value);
            }
        }

        return $curl;
    }

    /**
     * @param callable(Curl): void $request
     * @throws ApiException
     */
    private function send(Curl $curl, string $method, string $url, callable $request): array
    {
        $started = microtime(true);
        try {
            $request($curl);
        } catch (\Throwable $e) {
            $this->logger->error('NexorAIQ API transport error', [
                'method' => $method, 'url' => $this->redact($url), 'error' => $e->getMessage(),
            ]);
            throw new ApiException(new Phrase('NexorAIQ API is unreachable.'), $e);
        }

        $status = $curl->getStatus();
        $body = (string)$curl->getBody();
        $context = [
            'method' => $method,
            'url'    => $this->redact($url),
            'status' => $status,
            'ms'     => (int)round((microtime(true) - $started) * 1000),
        ];

        if ($status < 200 || $status >= 300) {
            $this->logger->warning('NexorAIQ API returned an error status', $context + ['body' => mb_substr($body, 0, 500)]);
            throw new ApiException(new Phrase('NexorAIQ API request failed with status %1.', [$status]));
        }

        if ($body === '') {
            return [];
        }

        try {
            $decoded = $this->json->unserialize($body);
        } catch (\InvalidArgumentException $e) {
            $this->logger->warning('NexorAIQ API returned invalid JSON', $context);
            throw new ApiException(new Phrase('NexorAIQ API returned an invalid response.'), $e);
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Search terms can be personal data: keep them out of the logs.
     */
    private function redact(string $url): string
    {
        return (string)preg_replace('/([?&]q=)[^&]*/', '$1***', $url);
    }
}
