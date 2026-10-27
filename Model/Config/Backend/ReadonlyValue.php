<?php
declare(strict_types=1);

namespace NexorAIQ\Connector\Model\Config\Backend;

use Magento\Framework\App\Config\Value;

/**
 * Keeps the stored value of a read-only config field; submitted values are ignored.
 */
class ReadonlyValue extends Value
{
    public function beforeSave()
    {
        $this->setValue($this->getOldValue());
        return parent::beforeSave();
    }
}
