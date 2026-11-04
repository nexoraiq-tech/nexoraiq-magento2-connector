<?php
/**
 * Copyright © 2026 NexorAIQ. All rights reserved.
 */
declare(strict_types=1);

namespace NexorAIQ\Connector\Model\SpecialPrice;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\EntityManager\MetadataPool;

/**
 * Finds products whose special price starts or ends on given dates, so they can be queued for a
 * NexorAIQ sync even though nobody saves them (Magento applies the dates on its own).
 */
class ScheduledChangeFinder
{
    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly EavConfig $eavConfig,
        private readonly MetadataPool $metadataPool
    ) {
    }

    /**
     * Product IDs that have a special price and whose $dateAttribute (special_from_date or
     * special_to_date) falls between $fromDate and $toDate (inclusive, 'Y-m-d'), for the given
     * store view or the default (All Store Views) scope.
     *
     * @return int[]
     */
    public function findProductIds(string $dateAttribute, string $fromDate, string $toDate, int $storeId): array
    {
        if ($fromDate > $toDate) {
            return [];
        }

        $connection = $this->resourceConnection->getConnection();
        $metadata = $this->metadataPool->getMetadata(ProductInterface::class);
        $linkField = $metadata->getLinkField(); // entity_id (Mage-OS / Open Source) or row_id (Commerce)

        $dateAttributeId = (int)$this->eavConfig->getAttribute('catalog_product', $dateAttribute)->getAttributeId();
        $priceAttributeId = (int)$this->eavConfig->getAttribute('catalog_product', 'special_price')->getAttributeId();
        if (!$dateAttributeId || !$priceAttributeId) {
            return [];
        }

        $hasSpecialPrice = $connection->select()
            ->from(['sp' => $this->resourceConnection->getTableName('catalog_product_entity_decimal')], [new \Zend_Db_Expr('1')])
            ->where('sp.' . $linkField . ' = d.' . $linkField)
            ->where('sp.attribute_id = ?', $priceAttributeId)
            ->where('sp.value IS NOT NULL');

        $select = $connection->select()
            ->distinct()
            ->from(['d' => $this->resourceConnection->getTableName('catalog_product_entity_datetime')], [])
            ->join(
                ['e' => $this->resourceConnection->getTableName('catalog_product_entity')],
                'e.' . $linkField . ' = d.' . $linkField,
                ['entity_id']
            )
            ->where('d.attribute_id = ?', $dateAttributeId)
            ->where('d.store_id IN (?)', [0, $storeId])
            ->where('d.value >= ?', $fromDate . ' 00:00:00')
            ->where('d.value <= ?', $toDate . ' 23:59:59')
            ->where('EXISTS (?)', $hasSpecialPrice);

        return array_map('intval', $connection->fetchCol($select));
    }
}
