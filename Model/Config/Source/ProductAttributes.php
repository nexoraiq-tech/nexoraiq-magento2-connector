<?php
declare(strict_types=1);

namespace NexorAIQ\Connector\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use Magento\Catalog\Model\ResourceModel\Product\Attribute\CollectionFactory;

class ProductAttributes implements OptionSourceInterface
{
    /**
     * Content / SEO attributes that are usually neither filterable nor searchable,
     * but are still useful in the NexorAIQ product object.
     */
    private const META_ATTRIBUTES = [
        'name',
        'sku',
        'description',
        'short_description',
        'meta_title',
        'meta_keyword',
        'meta_description',
        'url_key',
    ];

    public function __construct(
        private readonly CollectionFactory $collectionFactory
    ) {
    }

    public function toOptionArray(): array
    {
        $collection = $this->collectionFactory->create();
        // Filterable (is_filterable > 0) OR searchable (is_searchable = 1) OR a meta/content attribute
        $collection->addFieldToFilter(
            ['is_filterable', 'is_searchable', 'attribute_code', 'attribute_code'],
            [['gt' => 0], ['eq' => 1], ['in' => self::META_ATTRIBUTES], ['like' => 'meta\_%']]
        );

        $options = [];
        foreach ($collection as $attribute) {
            $options[] = [
                'value' => $attribute->getAttributeCode(),
                'label' => ($attribute->getFrontendLabel() ?: $attribute->getAttributeCode()) . ' (' . $attribute->getAttributeCode() . ')'
            ];
        }
        usort($options, fn($a, $b) => strcmp((string)$a['label'], (string)$b['label']));
        return $options;
    }
}
