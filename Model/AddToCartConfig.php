<?php
/**
 * Copyright © 2026 NexorAIQ. All rights reserved.
 */

declare(strict_types=1);

namespace NexorAIQ\Connector\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Store\Model\ScopeInterface;
use NexorAIQ\Connector\Model\Config\Source\AfterAddToCart;

/**
 * Add to Cart button settings for the search overlay and the search results page.
 */
class AddToCartConfig implements ArgumentInterface
{
    private const XML_PATH_PREFIX = 'nexoraiq/add_to_cart/';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly UrlInterface $urlBuilder
    ) {
    }

    public function isOverlayEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_PREFIX . 'show_on_overlay', ScopeInterface::SCOPE_STORE);
    }

    public function isSearchPageEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_PREFIX . 'show_on_search_page', ScopeInterface::SCOPE_STORE);
    }

    public function getButtonLabel(): string
    {
        $label = trim((string)$this->scopeConfig->getValue(self::XML_PATH_PREFIX . 'button_label', ScopeInterface::SCOPE_STORE));
        return $label !== '' ? $label : (string)__('Add to Cart');
    }

    public function getAfterAdd(): string
    {
        $value = (string)$this->scopeConfig->getValue(self::XML_PATH_PREFIX . 'after_add', ScopeInterface::SCOPE_STORE);
        return in_array($value, [AfterAddToCart::OPEN_DRAWER, AfterAddToCart::MESSAGE, AfterAddToCart::CART_PAGE], true)
            ? $value
            : AfterAddToCart::OPEN_DRAWER;
    }

    /**
     * Settings for the shared nexoraiqCart helper (view/frontend/templates/search/add-to-cart.phtml).
     */
    public function getJsConfig(): array
    {
        return [
            'url'      => $this->urlBuilder->getUrl('nexoraiq/cart/add'),
            'cartUrl'  => $this->urlBuilder->getUrl('checkout/cart'),
            'label'    => $this->getButtonLabel(),
            'afterAdd' => $this->getAfterAdd(),
        ];
    }
}
