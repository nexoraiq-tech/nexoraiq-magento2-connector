<?php

/**
 * Copyright © 2025 NexorAIQ. All rights reserved.
 */

declare(strict_types=1);

namespace NexorAIQ\Connector\Model\Resolver;

use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Framework\GraphQl\Exception\GraphQlInputException;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use NexorAIQ\Connector\Helper\Data as HelperData;

class SearchConfig implements ResolverInterface
{
    private const LAYOUTS = ['autocomplete', 'overlay'];

    /**
     * @param HelperData $helper
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        private readonly HelperData $helper,
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    /**
     * @param Field $field
     * @param mixed $context
     * @param ResolveInfo $info
     * @param array|null $value
     * @param array|null $args
     * @return array
     * @throws GraphQlInputException
     */
    public function resolve(Field $field, $context, ResolveInfo $info, ?array $value = null, ?array $args = null): array
    {
        if (!isset($args['storeId'])) {
            throw new GraphQlInputException(__('storeId must be specified'));
        }

        $storeId = (int)$args['storeId'];

        return [
            'general' => [
                'enabled' => $this->helper->isEnabled($storeId),
                'apiUrl' => $this->helper->getApiUrl($storeId),
                'publicSearchKey' => $this->helper->getSearchKey($storeId),
                'searchType' => $this->helper->getSearchType($storeId),
            ],
            'catalogueIndex' => [
                'isMultiBrand' => $this->helper->isMultiBrand($storeId),
                'brandAttribute' => $this->helper->getBrandAttribute($storeId),
                'ignoreDescription' => $this->helper->isIgnoreDescription($storeId),
            ],
            'storefront' => $this->getStorefrontConfig($storeId),
        ];
    }

    /**
     * Storefront UI, search results page and add to cart settings for a store view.
     */
    private function getStorefrontConfig(int $storeId): array
    {
        $ui = fn(string $field) => $this->value('nexoraiq/storefront_ui/' . $field, $storeId);
        $flag = fn(string $path) => $this->scopeConfig->isSetFlag($path, ScopeInterface::SCOPE_STORE, $storeId);

        $desktop = $this->layout($ui('search_layout'), 'autocomplete');

        return [
            'takeoverEnabled' => $this->helper->isTakeoverEnabled($storeId),
            'searchLayout' => $desktop,
            'mobileSearchLayout' => $this->layout($ui('mobile_search_layout'), $desktop),
            'mobileBreakpoint' => '(max-width: 767px)',
            'autocompleteLimit' => (int)$ui('autocomplete_limit'),
            'overlayProductsLimit' => (int)$ui('overlay_products_limit'),
            'overlayLayoutType' => (string)$ui('overlay_layout_type'),
            'suggestionsEnabled' => $flag('nexoraiq/storefront_ui/suggestions_enabled'),
            'suggestionsLabel' => (string)$ui('suggestions_label'),
            'suggestionsLimit' => (int)$ui('suggestions_limit'),
            'categoriesEnabled' => $flag('nexoraiq/storefront_ui/categories_enabled'),
            'categoriesLabel' => (string)$ui('categories_label'),
            'categoriesLimit' => (int)$ui('categories_limit'),
            'brandsEnabled' => $flag('nexoraiq/storefront_ui/brands_enabled'),
            'brandsLabel' => (string)$ui('brands_label'),
            'brandsLimit' => (int)$ui('brands_limit'),
            'searchPageEnabled' => $flag('nexoraiq/search_page/enabled'),
            'searchPageRoute' => (string)$this->value('nexoraiq/search_page/route', $storeId),
            'searchPagePerPage' => (int)$this->value('nexoraiq/search_page/per_page', $storeId),
            'searchPageShowFilters' => $flag('nexoraiq/search_page/show_filters'),
            'addToCartOnOverlay' => $flag('nexoraiq/add_to_cart/show_on_overlay'),
            'addToCartOnSearchPage' => $flag('nexoraiq/add_to_cart/show_on_search_page'),
            'addToCartLabel' => (string)$this->value('nexoraiq/add_to_cart/button_label', $storeId),
            'afterAddToCart' => (string)$this->value('nexoraiq/add_to_cart/after_add', $storeId),
        ];
    }

    private function value(string $path, int $storeId): mixed
    {
        return $this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE, $storeId);
    }

    private function layout(mixed $value, string $fallback): string
    {
        return in_array($value, self::LAYOUTS, true) ? (string)$value : $fallback;
    }
}
