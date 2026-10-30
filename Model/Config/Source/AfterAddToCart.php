<?php
/**
 * Copyright © 2026 NexorAIQ. All rights reserved.
 */

declare(strict_types=1);

namespace NexorAIQ\Connector\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class AfterAddToCart implements OptionSourceInterface
{
    public const OPEN_DRAWER = 'drawer';
    public const MESSAGE     = 'message';
    public const CART_PAGE   = 'cart';

    /**
     * Return array of options as value-label pairs
     *
     * @return array
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => self::OPEN_DRAWER, 'label' => __('Open Mini Cart')],
            ['value' => self::MESSAGE,     'label' => __('Show Success Message Only')],
            ['value' => self::CART_PAGE,   'label' => __('Go to Shopping Cart Page')],
        ];
    }
}
