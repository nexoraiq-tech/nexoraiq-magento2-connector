<?php
/**
 * Copyright © 2026 NexorAIQ. All rights reserved.
 */

declare(strict_types=1);

namespace NexorAIQ\Connector\Model\Api;

use Magento\Authorization\Model\UserContextInterface;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Stdlib\CookieManagerInterface;

/**
 * Shopper context sent to the NexorAIQ search / GraphQL / analytics API on every storefront call:
 * - x-session-id: the browser's session id (see search/shared.phtml), forwarded from the incoming
 *   "X-Session-Id" header or the "nexoraiq_session_id" cookie;
 * - x-user-id: "user_" + base64(customer email), or "guest_1" for guests;
 * - x-device: web_desktop, web_mobile or web_tablet.
 */
class RequestContext
{
    public const HEADER_SESSION = 'x-session-id';
    public const HEADER_USER = 'x-user-id';
    public const HEADER_DEVICE = 'x-device';

    public const SESSION_COOKIE = 'nexoraiq_session_id';
    public const GUEST_USER_ID = 'guest_1';

    public const DEVICE_DESKTOP = 'web_desktop';
    public const DEVICE_MOBILE = 'web_mobile';
    public const DEVICE_TABLET = 'web_tablet';

    private ?array $headers = null;

    public function __construct(
        private readonly RequestInterface $request,
        private readonly CookieManagerInterface $cookieManager,
        private readonly UserContextInterface $userContext,
        private readonly CustomerRepositoryInterface $customerRepository
    ) {
    }

    /**
     * @return array<string, string> header name => value
     */
    public function getHeaders(): array
    {
        return $this->headers ??= [
            self::HEADER_SESSION => $this->getSessionId(),
            self::HEADER_USER    => $this->getUserId(),
            self::HEADER_DEVICE  => $this->getDevice(),
        ];
    }

    /**
     * The same headers as "Name: value" lines, for curl_setopt(CURLOPT_HTTPHEADER).
     *
     * @return string[]
     */
    public function getCurlHeaderLines(): array
    {
        $lines = [];
        foreach ($this->getHeaders() as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }
        return $lines;
    }

    public function getSessionId(): string
    {
        $id = $this->sanitize((string)$this->request->getHeader('X-Session-Id'))
            ?: $this->sanitize((string)$this->cookieManager->getCookie(self::SESSION_COOKIE));
        // No browser id (e.g. a headless GraphQL client): one id for this request.
        return $id !== '' ? $id : bin2hex(random_bytes(16));
    }

    public function getUserId(): string
    {
        if ((int)$this->userContext->getUserType() !== UserContextInterface::USER_TYPE_CUSTOMER) {
            return self::GUEST_USER_ID;
        }
        $customerId = (int)$this->userContext->getUserId();
        if ($customerId <= 0) {
            return self::GUEST_USER_ID;
        }
        try {
            $email = (string)$this->customerRepository->getById($customerId)->getEmail();
        } catch (\Exception $e) {
            return self::GUEST_USER_ID;
        }
        return $email !== '' ? 'user_' . base64_encode(strtolower(trim($email))) : self::GUEST_USER_ID;
    }

    public function getDevice(): string
    {
        $device = strtolower((string)$this->request->getHeader('X-Device'));
        if (in_array($device, [self::DEVICE_DESKTOP, self::DEVICE_MOBILE, self::DEVICE_TABLET], true)) {
            return $device;
        }
        return $this->detectDevice((string)$this->request->getHeader('User-Agent'));
    }

    public function detectDevice(string $userAgent): string
    {
        if (preg_match('/iPad|Tablet|PlayBook|Silk|Kindle|Nexus (7|9|10)|Android(?!.*Mobile)/i', $userAgent)) {
            return self::DEVICE_TABLET;
        }
        if (preg_match('/Mobi|iPhone|iPod|Android|BlackBerry|IEMobile|Opera Mini|Windows Phone/i', $userAgent)) {
            return self::DEVICE_MOBILE;
        }
        return self::DEVICE_DESKTOP;
    }

    /**
     * Ids come from the browser: keep them short and header-safe.
     */
    private function sanitize(string $id): string
    {
        return preg_match('/^[A-Za-z0-9._:-]{1,128}$/', $id) ? $id : '';
    }
}
