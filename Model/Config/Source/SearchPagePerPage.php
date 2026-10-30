<?php
/**
 * Copyright © 2026 NexorAIQ. All rights reserved.
 */

declare(strict_types=1);

namespace NexorAIQ\Connector\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class SearchPagePerPage implements OptionSourceInterface
{
    /**
     * Return array of options as value-label pairs
     *
     * @return array
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => 12, 'label' => __('12')],
            ['value' => 24, 'label' => __('24')],
            ['value' => 36, 'label' => __('36')],
            ['value' => 48, 'label' => __('48')],
            ['value' => 60, 'label' => __('60')],
        ];
    }
}
