<?php

/**
 * Copyright © 2025 NexorAIQ. All rights reserved.
 */

declare(strict_types=1);

namespace NexorAIQ\Connector\Model;

use NexorAIQ\Connector\Api\ConfigRepositoryInterface;
use NexorAIQ\Connector\Helper\Data as HelperData;

class ConfigRepository implements ConfigRepositoryInterface
{
    /**
     * @param HelperData $helper
     */
    public function __construct(
        private readonly HelperData $helper
    ) {
    }

    /**
     * Retrieve search credentials and dynamic UI configuration as a JSON string.
     *
     * @param int|null $storeId
     * @return string
     */
    public function getStoreConfig(?int $storeId = null): string
    {
        $data = [
            'general' => [
                'enabled' => $this->helper->isEnabled($storeId),
                'apiUrl' => $this->helper->getApiUrl($storeId),
                'publicSearchKey' => $this->helper->getSearchKey($storeId),
                'searchType' => $this->helper->getSearchType($storeId),
            ],
            'catalogueIndex' => [
                'isMultiBrand' => $this->helper->isMultiBrand($storeId),
                'brandAttribute' => $this->helper->getBrandAttribute($storeId),
                'ignoreDescription' => $this->helper->isIgnoreDescription($storeId),
            ],
        ];

        return (string)json_encode($data);
    }
}
