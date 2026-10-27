<?php
declare(strict_types=1);

namespace NexorAIQ\Connector\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class BatchSize implements OptionSourceInterface
{
    public const DEFAULT_SIZE = 100;
    public const ALLOWED_SIZES = [100, 150, 200, 300];

    public function toOptionArray(): array
    {
        return [
            ['value' => '100', 'label' => __('100 (Low Load - Default)')],
            ['value' => '150', 'label' => __('150 (Balanced Load)')],
            ['value' => '200', 'label' => __('200 (High Load)')],
            ['value' => '300', 'label' => __('300 (Maximum Load)')],
        ];
    }
}
