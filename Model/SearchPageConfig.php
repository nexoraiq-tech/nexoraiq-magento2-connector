<?php
/**
 * Copyright © 2026 NexoraIQ. All rights reserved.
 */

declare(strict_types=1);

namespace NexorAIQ\Connector\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use NexorAIQ\Connector\Helper\Data as HelperData;

/**
 * Settings of the NexorAIQ search results page (Stores > Configuration > NexorAIQ > Storefront Configuration).
 */
class SearchPageConfig
{
    private const XML_PATH_PREFIX = 'nexoraiq/search_page/';
    public const DEFAULT_ROUTE = 'search';
    public const DEFAULT_PER_PAGE = 24;

    public function __construct(
        private readonly HelperData $helper,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly StoreManagerInterface $storeManager,
        private readonly UrlInterface $urlBuilder
    ) {
    }

    /**
     * The page needs the extension, the storefront takeover and a public search key for the store.
     */
    public function isActive(?int $storeId = null): bool
    {
        $storeId ??= $this->getStoreId();
        return $this->isSetFlag('enabled', $storeId)
            && $this->helper->isEnabled($storeId)
            && $this->helper->isTakeoverEnabled($storeId)
            && $this->helper->getSearchKey($storeId) !== '';
    }

    /**
     * URL path of the page, e.g. "search" or "shop/search", without leading/trailing slashes.
     */
    public function getRoute(?int $storeId = null): string
    {
        $route = trim((string)$this->getValue('route', $storeId ?? $this->getStoreId()), " /");
        return $route !== '' ? strtolower($route) : self::DEFAULT_ROUTE;
    }

    public function getPerPage(?int $storeId = null): int
    {
        $perPage = (int)$this->getValue('per_page', $storeId ?? $this->getStoreId());
        return $perPage > 0 ? $perPage : self::DEFAULT_PER_PAGE;
    }

    public function isFiltersEnabled(?int $storeId = null): bool
    {
        return $this->isSetFlag('show_filters', $storeId ?? $this->getStoreId());
    }

    /**
     * Absolute URL of the page in the current store, with optional query parameters.
     */
    public function getUrl(array $query = []): string
    {
        return $this->urlBuilder->getUrl('', ['_direct' => $this->getRoute(), '_query' => $query]);
    }

    private function getValue(string $field, int $storeId): mixed
    {
        return $this->scopeConfig->getValue(self::XML_PATH_PREFIX . $field, ScopeInterface::SCOPE_STORE, $storeId);
    }

    private function isSetFlag(string $field, int $storeId): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_PREFIX . $field, ScopeInterface::SCOPE_STORE, $storeId);
    }

    private function getStoreId(): int
    {
        return (int)$this->storeManager->getStore()->getId();
    }
}
