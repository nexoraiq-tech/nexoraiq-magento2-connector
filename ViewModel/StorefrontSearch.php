<?php
/**
 * Copyright © 2026 NexoraIQ. All rights reserved.
 */

declare(strict_types=1);

namespace NexorAIQ\Connector\ViewModel;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Search\Helper\Data as SearchHelper;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use NexorAIQ\Connector\Helper\Data as HelperData;
use NexorAIQ\Connector\Model\Config\Source\OverlayLayoutType;
use NexorAIQ\Connector\Model\Config\Source\SearchLayout;
use NexorAIQ\Connector\Model\AddToCartConfig;
use NexorAIQ\Connector\Model\SearchPageConfig;

/**
 * Supplies the Storefront UI Configuration to the header search template (luma/ or hyva/search/form.phtml).
 */
class StorefrontSearch implements ArgumentInterface
{
    private const XML_PATH_PREFIX = 'nexoraiq/storefront_ui/';

    public function __construct(
        private readonly HelperData $helper,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly StoreManagerInterface $storeManager,
        private readonly UrlInterface $urlBuilder,
        private readonly SearchHelper $searchHelper,
        private readonly Json $json,
        private readonly SearchPageConfig $searchPageConfig,
        private readonly AddToCartConfig $addToCartConfig
    ) {
    }

    /**
     * Takeover only runs when the extension is enabled for this store and a public search key is set.
     */
    public function isActive(): bool
    {
        $storeId = $this->getStoreId();
        return $this->helper->isEnabled($storeId)
            && $this->helper->isTakeoverEnabled($storeId)
            && $this->helper->getSearchKey($storeId) !== '';
    }

    /**
     * Layout on desktop / tablet screens (768px and wider).
     */
    public function getSearchLayout(): string
    {
        return $this->normalizeLayout($this->getValue('search_layout'));
    }

    /**
     * Layout on mobile screens (767px and narrower). The browser picks between the two
     * (full page cache serves the same HTML to every device).
     */
    public function getMobileSearchLayout(): string
    {
        $value = $this->getValue('mobile_search_layout');
        return $value === null || $value === '' ? $this->getSearchLayout() : $this->normalizeLayout($value);
    }

    /**
     * Desktop layout is the overlay (kept for backwards compatibility).
     */
    public function isOverlay(): bool
    {
        return $this->getSearchLayout() === SearchLayout::LAYOUT_OVERLAY;
    }

    /**
     * The overlay is used on desktop or on mobile, so its markup must be rendered.
     */
    public function hasOverlay(): bool
    {
        return $this->getSearchLayout() === SearchLayout::LAYOUT_OVERLAY
            || $this->getMobileSearchLayout() === SearchLayout::LAYOUT_OVERLAY;
    }

    /**
     * The autocomplete dropdown is used on desktop or on mobile.
     */
    public function hasAutocomplete(): bool
    {
        return $this->getSearchLayout() === SearchLayout::LAYOUT_AUTOCOMPLETE
            || $this->getMobileSearchLayout() === SearchLayout::LAYOUT_AUTOCOMPLETE;
    }

    public function isTwoColumns(): bool
    {
        return $this->hasOverlay()
            && $this->getValue('overlay_layout_type') === OverlayLayoutType::TWO_COLUMNS;
    }

    /**
     * Side blocks of the 2 column overlay, in display order, enabled ones only.
     *
     * @return array<int, array{type: string, label: string, limit: int}>
     */
    public function getSections(): array
    {
        if (!$this->isTwoColumns()) {
            return [];
        }

        $defaults = ['suggestions' => __('Suggestions'), 'categories' => __('Categories'), 'brands' => __('Brands')];
        $sections = [];
        foreach ($defaults as $type => $defaultLabel) {
            if (!$this->scopeConfig->isSetFlag(
                self::XML_PATH_PREFIX . $type . '_enabled',
                ScopeInterface::SCOPE_STORE,
                $this->getStoreId()
            )) {
                continue;
            }
            $label = trim((string)$this->getValue($type . '_label'));
            $sections[] = [
                'type'  => $type,
                'label' => $label !== '' ? $label : (string)$defaultLabel,
                'limit' => max(1, (int)$this->getValue($type . '_limit')),
            ];
        }

        return $sections;
    }

    /**
     * Product / suggestion limit of the desktop layout.
     */
    public function getProductsLimit(): int
    {
        return $this->isOverlay() ? $this->getOverlayLimit() : $this->getAutocompleteLimit();
    }

    public function getAutocompleteLimit(): int
    {
        $limit = (int)$this->getValue('autocomplete_limit');
        return $limit > 0 ? $limit : 10;
    }

    public function getOverlayLimit(): int
    {
        $limit = (int)$this->getValue('overlay_products_limit');
        return $limit > 0 ? $limit : 10;
    }

    /**
     * scheme://host[:port] of the NexorAIQ API, for a <link rel="preconnect"> hint.
     */
    public function getApiOrigin(): string
    {
        if (!$this->isActive()) {
            return '';
        }
        $parts = parse_url($this->helper->getApiUrl($this->getStoreId()));
        if (empty($parts['scheme']) || empty($parts['host'])) {
            return '';
        }
        return $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }

    /**
     * Settings consumed by the search form component (Luma: js/search-form.js, Hyvä: Alpine component).
     */
    public function getJsConfig(): string
    {
        return $this->json->serialize($this->getJsConfigData());
    }

    public function getJsConfigData(): array
    {
        $store = $this->storeManager->getStore();
        $storeId = (int)$store->getId();

        // Autocomplete goes straight from the browser to NexorAIQ (CORS-enabled) using the public,
        // read-only search key, skipping the Magento round trip. The private connection key never
        // leaves the server; autocompleteUrl stays as a fallback proxy if the direct call fails.
        return [
            'autocompleteUrl' => $this->urlBuilder->getUrl('nexoraiq/ajax/autocomplete'),
            'autocompleteApiUrl' => $this->helper->getApiUrl($storeId) . '/search/autocomplete',
            // Overlay layout: products + suggestions/categories/brands in one call to the NexorAIQ
            // GraphQL "overlay" query, authenticated with the same public search key (x-api-key).
            'graphqlUrl'     => $this->helper->getApiUrl($storeId) . '/graphql',
            'searchKey'      => $this->helper->getSearchKey($storeId),
            'storeView'      => $store->getCode(),
            'layout'         => $this->getSearchLayout(),
            'mobileLayout'   => $this->getMobileSearchLayout(),
            // Same breakpoint as the Luma and Hyvä mobile styles.
            'mobileMedia'    => '(max-width: 767px)',
            'twoColumns'     => $this->isTwoColumns(),
            'productsLimit'  => $this->getProductsLimit(),
            'autocompleteLimit' => $this->getAutocompleteLimit(),
            'overlayLimit'   => $this->getOverlayLimit(),
            'sections'       => $this->getSections(),
            'minQueryLength' => (int)$this->searchHelper->getMinQueryLength(),
            'trackEventUrl'  => $this->getTrackEventUrl(),
            'resultUrl'      => $this->getResultUrl(),
            'queryParam'     => $this->searchHelper->getQueryParamName(),
            'currencyCode'   => $store->getCurrentCurrencyCode(),
            'addToCart'      => $this->isAddToCartEnabled(),
            'cart'           => $this->addToCartConfig->getJsConfig(),
        ];
    }

    /**
     * REST proxy for storefront CLICK analytics in the current store view (NexorAIQ logEvent).
     */
    public function getTrackEventUrl(): string
    {
        $storeCode = $this->storeManager->getStore()->getCode();
        return $this->urlBuilder->getUrl('', ['_direct' => 'rest/' . $storeCode . '/V1/nexoraiq/analytics/event']);
    }

    /**
     * Add to Cart buttons on the overlay product cards.
     */
    public function isAddToCartEnabled(): bool
    {
        return $this->hasOverlay() && $this->addToCartConfig->isOverlayEnabled();
    }

    /**
     * The NexorAIQ search results page when enabled, otherwise catalogsearch/result.
     */
    public function getResultUrl(): string
    {
        return $this->searchPageConfig->isActive()
            ? $this->searchPageConfig->getUrl()
            : $this->searchHelper->getResultUrl();
    }

    private function normalizeLayout(mixed $value): string
    {
        return $value === SearchLayout::LAYOUT_OVERLAY ? SearchLayout::LAYOUT_OVERLAY : SearchLayout::LAYOUT_AUTOCOMPLETE;
    }

    private function getValue(string $field): mixed
    {
        return $this->scopeConfig->getValue(
            self::XML_PATH_PREFIX . $field,
            ScopeInterface::SCOPE_STORE,
            $this->getStoreId()
        );
    }

    private function getStoreId(): int
    {
        return (int)$this->storeManager->getStore()->getId();
    }
}
