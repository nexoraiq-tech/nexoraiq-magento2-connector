<?php
/**
 * Copyright © 2026 NexorAIQ. All rights reserved.
 */

declare(strict_types=1);

namespace NexorAIQ\Connector\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class OverlayProductsLimit implements OptionSourceInterface
{
    /**
     * Return array of options as value-label pairs
     *
     * @return array
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => 5, 'label' => __('5')],
            ['value' => 10, 'label' => __('10')],
            ['value' => 15, 'label' => __('15')],
            ['value' => 20, 'label' => __('20')],
            ['value' => 30, 'label' => __('30')],
            ['value' => 40, 'label' => __('40')],
        ];
    }
}
