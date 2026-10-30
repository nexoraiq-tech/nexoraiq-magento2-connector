<?php
/**
 * Copyright © 2026 NexorAIQ. All rights reserved.
 */

declare(strict_types=1);

namespace NexorAIQ\Connector\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class SearchLayout implements OptionSourceInterface
{
    public const LAYOUT_AUTOCOMPLETE = 'autocomplete';
    public const LAYOUT_OVERLAY      = 'overlay';

    /**
     * Return array of options as value-label pairs
     *
     * @return array
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => self::LAYOUT_AUTOCOMPLETE, 'label' => __('Autocomplete')],
            ['value' => self::LAYOUT_OVERLAY,      'label' => __('Overlay Layout')]
        ];
    }
}
