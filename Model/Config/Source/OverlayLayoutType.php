<?php
/**
 * Copyright © 2026 NexoraIQ. All rights reserved.
 */

declare(strict_types=1);

namespace NexorAIQ\Connector\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class OverlayLayoutType implements OptionSourceInterface
{
    public const ONE_COLUMN  = '1column';
    public const TWO_COLUMNS = '2columns';

    /**
     * Return array of options as value-label pairs
     *
     * @return array
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => self::ONE_COLUMN,  'label' => __('1 Column')],
            ['value' => self::TWO_COLUMNS, 'label' => __('2 Columns')]
        ];
    }
}
