<?php
namespace NexorAIQ\Connector\Model\Config\Backend;

use Magento\Framework\App\Config\Value;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Registry;
use NexorAIQ\Connector\Helper\Data as HelperData;
use Psr\Log\LoggerInterface;

class Enabled extends Value
{
    public function __construct(
        Context $context,
        Registry $registry,
        ScopeConfigInterface $config,
        TypeListInterface $cacheTypeList,
        private readonly HelperData $helper,
        private readonly LoggerInterface $logger,
        ?AbstractResource $resource = null,
        ?AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        parent::__construct($context, $registry, $config, $cacheTypeList, $resource, $resourceCollection, $data);
    }

    /**
     * @return $this
     */
    public function afterSave()
    {
        parent::afterSave();

        $value = (bool)$this->getValue();

        try {
            $this->helper->notifyExtensionStatusToNexoraIQ($value);
            $this->logger->info(sprintf('NexoraIQ Admin: extension %s, NexoraIQ notified', $value ? 'enabled' : 'disabled'));
        } catch (\Throwable $e) {
            // Silently catch to prevent admin config save from failing if API is unreachable
            $this->logger->error(sprintf(
                'NexoraIQ Admin: extension %s, failed to notify NexoraIQ: %s',
                $value ? 'enabled' : 'disabled',
                $e->getMessage()
            ));
        }

        return $this;
    }
}
