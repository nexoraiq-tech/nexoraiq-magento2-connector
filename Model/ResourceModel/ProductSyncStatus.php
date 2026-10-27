<?php
declare(strict_types=1);

namespace NexorAIQ\Connector\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class ProductSyncStatus extends AbstractDb
{
    protected function _construct()
    {
        $this->_init('nexoraiq_product_sync_status', 'entity_id');
    }
}
