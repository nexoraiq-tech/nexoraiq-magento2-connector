<?php

/**
 * Copyright © 2025 NexorAIQ. All rights reserved.
 */

declare(strict_types=1);

namespace NexorAIQ\Connector\Model\Resolver;

use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use NexorAIQ\Connector\Api\ConnectorInterface;

class Ping implements ResolverInterface
{
    /**
     * @param ConnectorInterface $connector
     */
    public function __construct(
        private readonly ConnectorInterface $connector
    ) {
    }

    /**
     * @param Field $field
     * @param mixed $context
     * @param ResolveInfo $info
     * @param array|null $value
     * @param array|null $args
     * @return mixed[]
     */
    public function resolve(Field $field, $context, ResolveInfo $info, ?array $value = null, ?array $args = null): array
    {
        $key = $args['key'] ?? null;
        $result = $this->connector->ping($key);

        return [
            'status' => $result->getStatus(),
            'extension' => $result->getExtension(),
            'version' => $result->getVersion(),
            'platform' => $result->getPlatform(),
            'magento_version' => $result->getMagentoVersion(),
            'edition' => $result->getEdition(),
            'connection_key_configured' => $result->getConnectionKeyConfigured(),
            'connection_key_valid' => $result->getConnectionKeyValid(),
            'catalog_accessible' => $result->getCatalogAccessible(),
            'product_count' => $result->getProductCount(),
            'store_count' => $result->getStoreCount(),
        ];
    }
}
