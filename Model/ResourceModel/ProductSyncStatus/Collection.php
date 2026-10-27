<?php
declare(strict_types=1);

namespace NexorAIQ\Connector\Model\ResourceModel\ProductSyncStatus;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use NexorAIQ\Connector\Model\ProductSyncStatus as Model;
use NexorAIQ\Connector\Model\ResourceModel\ProductSyncStatus as ResourceModel;

class Collection extends AbstractCollection
{
    protected function _construct()
    {
        $this->_init(Model::class, ResourceModel::class);
    }
}
