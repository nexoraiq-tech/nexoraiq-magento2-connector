<?php
/**
 * Copyright © 2026 NexoraIQ. All rights reserved.
 */

declare(strict_types=1);

namespace NexorAIQ\Connector\Model\Resolver\StoreConfig;

use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use NexorAIQ\Connector\Helper\Data as HelperData;

class TakeoverEnabled implements ResolverInterface
{
    public function __construct(
        private readonly HelperData $helper
    ) {
    }

    public function resolve(Field $field, $context, ResolveInfo $info, ?array $value = null, ?array $args = null): bool
    {
        $storeId = null;
        try {
            $store = $context->getExtensionAttributes()?->getStore();
            $storeId = $store ? (int)$store->getId() : null;
        } catch (\Throwable $e) {
            $storeId = null;
        }
        return $this->helper->isTakeoverEnabled($storeId);
    }
}
