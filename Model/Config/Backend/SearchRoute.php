<?php
/**
 * Copyright © 2026 NexoraIQ. All rights reserved.
 */

declare(strict_types=1);

namespace NexorAIQ\Connector\Model\Config\Backend;

use Magento\Framework\App\Config\Value;
use Magento\Framework\Exception\LocalizedException;
use NexorAIQ\Connector\Model\SearchPageConfig;

/**
 * Normalises and validates the search results page URL path ("search", "shop/search", ...).
 */
class SearchRoute extends Value
{
    /**
     * Paths that belong to Magento itself and must not be taken over.
     */
    private const RESERVED = [
        'admin', 'api', 'catalog', 'catalogsearch', 'checkout', 'cms', 'customer', 'graphql',
        'media', 'nexoraiq', 'rest', 'soap', 'static', 'wishlist', 'sales', 'contact', 'newsletter',
    ];

    public function beforeSave()
    {
        $route = strtolower(trim((string)$this->getValue(), " /"));
        if ($route === '') {
            $route = SearchPageConfig::DEFAULT_ROUTE;
        }

        if (!preg_match('#^[a-z0-9][a-z0-9_-]*(/[a-z0-9][a-z0-9_-]*)*$#', $route)) {
            throw new LocalizedException(__(
                'Search Page URL "%1" is invalid. Use letters, numbers, "-" and "_", with "/" between parts (e.g. "search" or "shop/search").',
                $route
            ));
        }
        if (in_array(explode('/', $route)[0], self::RESERVED, true)) {
            throw new LocalizedException(__('Search Page URL "%1" is reserved by Magento. Choose another path.', $route));
        }

        $this->setValue($route);
        return parent::beforeSave();
    }
}
