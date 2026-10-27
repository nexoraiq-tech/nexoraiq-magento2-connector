<?php
declare(strict_types=1);

namespace NexorAIQ\Connector\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class BrandIdentifiedUsing implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => 'attribute', 'label' => __('Attributes')],
            ['value' => 'category', 'label' => __('Category')]
        ];
    }
}
