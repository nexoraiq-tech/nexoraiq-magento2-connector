<?php

/**
 * Copyright © 2025 NexorAIQ. All rights reserved.
 */

declare(strict_types=1);

namespace NexorAIQ\Connector\Api;

interface ConfigRepositoryInterface
{
    /**
     * Retrieve search credentials and dynamic UI configuration as a JSON string.
     *
     * @param int|null $storeId
     * @return string
     */
    public function getStoreConfig(?int $storeId = null): string;
}
