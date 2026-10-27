<?php
/**
 * Copyright © 2026 NexoraIQ. All rights reserved.
 */

declare(strict_types=1);

namespace NexorAIQ\Connector\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class SearchType implements OptionSourceInterface
{
    public const TYPE_KEYWORD = 'keyword';
    public const TYPE_VECTOR  = 'vector';
    public const TYPE_HYBRID  = 'hybrid';

    /**
     * Return array of options as value-label pairs
     *
     * @return array
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => self::TYPE_KEYWORD, 'label' => __('Keyword')],
            ['value' => self::TYPE_VECTOR,  'label' => __('Vector')],
            ['value' => self::TYPE_HYBRID,  'label' => __('Hybrid')]
        ];
    }
}
