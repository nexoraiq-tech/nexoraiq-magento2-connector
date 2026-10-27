<?php

/**
 * Copyright © 2025 NexoraIQ. All rights reserved.
 */

declare(strict_types=1);

namespace NexorAIQ\Connector\Api;

use NexorAIQ\Connector\Api\Data\PingResultInterface;

interface ConnectorInterface
{
    /**
     * @param string|null $key
     * @return \NexorAIQ\Connector\Api\Data\PingResultInterface
     */
    public function ping(?string $key = null): PingResultInterface;
}
