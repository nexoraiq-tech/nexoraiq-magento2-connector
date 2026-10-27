<?php
declare(strict_types=1);

namespace NexorAIQ\Connector\Model;

use Magento\Framework\Model\AbstractModel;

class ProductSyncStatus extends AbstractModel
{
    protected function _construct()
    {
        $this->_init(\NexorAIQ\Connector\Model\ResourceModel\ProductSyncStatus::class);
    }
}
